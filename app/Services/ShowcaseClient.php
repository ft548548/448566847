<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 成果广场客户端（Work 权威在广场）
 *
 * 依据：
 *   docs/platform/data-dictionary-mapping.md §6 —— Work / Feedback 的权威平台是广场，
 *   本平台不持有该实体，跨平台读取走对方 API。
 *   《统一身份与网关接入说明》§3.4 B1 —— 不得读写广场数据库，只走公开 API。
 *   §12.4 —— 出站调用一律 L2 签名。
 *
 * 本期定位：**预留 + 占位实现**。契约（路径/字段/错误码）先定下来，
 * 广场侧接口就绪后只需把 SHOWCASE_BASE_URL 与凭据配上，业务代码不用改。
 *
 * 字段命名遵循 §6.3：v1 接口用 camelCase（同一接口内不得混用 snake_case）。
 */
class ShowcaseClient
{
    /** 本平台推送给广场的作品状态 */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /**
     * 由项目构造作品草稿（映射规则见 data-dictionary-mapping.md）
     *
     * @param mixed $project 项目模型
     * @param array $authorSubjects 参与者的 sub 列表
     * @return array
     */
    public function buildWorkFromProject($project, array $authorSubjects = []): array
    {
        return [
            // 广场侧主键：发布前为 null，发布后由广场回填
            'workId' => null,
            // 来源标识：平台编码 + 来源实体，广场据此做幂等与溯源
            'sourcePlatform' => (string) config('platform.app_code', 'workshop'),
            'sourceEntityId' => 'project_' . (int) ($project->id ?? 0),
            'title' => (string) ($project->name ?? ''),
            'summary' => $this->summaryOf($project),
            'coverUrl' => null,
            'authorSubjects' => array_values(array_filter($authorSubjects)),
            'tags' => [],
            'status' => self::STATUS_DRAFT,
            // 业务时间用带时区 RFC 3339（§8.2.3）
            'publishedAt' => null,
        ];
    }

    /**
     * 发布作品
     *
     * @param array $work buildWorkFromProject() 的产物
     * @param string $requestId
     * @return array{ok:bool,workId:string|null,status:string,code:string,message:string,mock:bool}
     */
    public function publishWork(array $work, string $requestId = ''): array
    {
        return $this->call('POST', $this->workPath(), $work, $requestId, 'workId');
    }

    /**
     * 撤回作品（保留记录，状态置 withdrawn）
     *
     * @param string $workId
     * @param string $requestId
     * @return array
     */
    public function withdrawWork(string $workId, string $requestId = ''): array
    {
        $path = rtrim($this->workPath(), '/') . '/' . rawurlencode($workId) . '/withdraw';
        return $this->call('POST', $path, ['reason' => ''], $requestId, null);
    }

    /**
     * 查询作品状态
     *
     * @param string $workId
     * @param string $requestId
     * @return array
     */
    public function getWork(string $workId, string $requestId = ''): array
    {
        $path = rtrim((string) config('platform.showcase.work_query_path', '/api/v1/plaza/internal/works'), '/')
            . '/' . rawurlencode($workId);
        return $this->call('GET', $path, null, $requestId, 'workId');
    }

    /**
     * 统一出站调用
     *
     * 失败不抛异常：成果推送属于"可延迟、不可阻断"的链路，
     * 项目提交/完成不因为广场不可用而失败。
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @param string $requestId
     * @param string|null $idField 从响应 data 中提取的主键字段
     * @return array
     */
    private function call(string $method, string $path, ?array $body, string $requestId, ?string $idField): array
    {
        if ($this->mockEnabled()) {
            return [
                'ok' => true,
                'workId' => $idField === 'workId' ? ('work_mock_' . substr(sha1($path . json_encode($body)), 0, 12)) : null,
                'status' => self::STATUS_DRAFT,
                'code' => PlatformResponse::OK,
                'message' => 'mock',
                'mock' => true,
            ];
        }

        $baseUrl = rtrim((string) config('platform.showcase.base_url', ''), '/');
        $payload = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = GatewaySigner::callHeaders(
            (string) config('platform.showcase.app_secret', ''),
            (string) config('platform.showcase.app_key', 'svc-workshop'),
            (string) config('platform.app_code', 'workshop'),
            $method,
            $path,
            '',
            $payload,
            $requestId
        );
        $headers['Content-Type'] = 'application/json';

        $timeout = max(0.2, ((int) config('platform.showcase.timeout_ms', 2000)) / 1000);

        try {
            $pending = Http::withHeaders($headers)->timeout($timeout)->acceptJson();
            $response = strtoupper($method) === 'GET'
                ? $pending->get($baseUrl . $path)
                : $pending->withBody($payload, 'application/json')->send($method, $baseUrl . $path);
        } catch (\Throwable $e) {
            Log::warning('[showcase] request failed: ' . $e->getMessage(), [
                'path' => $path,
                'requestId' => $requestId,
            ]);
            return $this->failure(PlatformResponse::SERVICE_UNAVAILABLE, '成果广场暂不可用');
        }

        if (!$response->ok()) {
            return $this->failure(PlatformResponse::SERVICE_UNAVAILABLE, '成果广场返回 HTTP ' . $response->status());
        }

        $data = $response->json();
        if (!is_array($data)) {
            return $this->failure(PlatformResponse::INTERNAL_ERROR, '成果广场响应不是合法 JSON');
        }

        // §6.4.3 规则一：以 code 判定，HTTP 200 + 业务错误是可能的
        $code = (string) ($data['code'] ?? '');
        if ($code !== PlatformResponse::OK) {
            return $this->failure(
                $code !== '' ? $code : PlatformResponse::BUSINESS_ERROR,
                (string) ($data['message'] ?? $data['msg'] ?? '成果广场拒绝该操作')
            );
        }

        $payloadData = $data['data'] ?? [];
        if (!is_array($payloadData)) {
            $payloadData = [];
        }

        return [
            'ok' => true,
            'workId' => $idField && isset($payloadData[$idField]) ? (string) $payloadData[$idField] : null,
            'status' => (string) ($payloadData['status'] ?? self::STATUS_DRAFT),
            'code' => PlatformResponse::OK,
            'message' => (string) ($data['message'] ?? $data['msg'] ?? 'success'),
            'mock' => false,
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @return array
     */
    private function failure(string $code, string $message): array
    {
        return [
            'ok' => false,
            'workId' => null,
            'status' => '',
            'code' => $code,
            'message' => $message,
            'mock' => false,
        ];
    }

    /**
     * 项目简介摘要（广场展示用，限长避免超对端字段长度）
     *
     * @param mixed $project
     * @return string
     */
    private function summaryOf($project): string
    {
        $desc = trim((string) ($project->desc ?? $project->description ?? ''));
        if ($desc === '') {
            return '';
        }
        return mb_substr(strip_tags($desc), 0, 500);
    }

    /**
     * @return string
     */
    private function workPath(): string
    {
        return (string) config('platform.showcase.work_path', '/api/v1/plaza/internal/works');
    }

    /**
     * 未配置 base_url 时自动 mock
     *
     * @return bool
     */
    private function mockEnabled(): bool
    {
        $value = config('platform.showcase.mock', null);
        if ($value !== null && $value !== '') {
            return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
        }
        return trim((string) config('platform.showcase.base_url', '')) === '';
    }
}
