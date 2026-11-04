<?php

namespace App\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 五平台约束规范（V2.0）契约适配层
 *
 * 设计原则：**只做叠加，不改语义**。DooTask 现有前端按 `ret` 判断成败、
 * 依赖 `ret/msg/data` 三个字段，因此这里绝不删除或改名既有字段，只在
 * 响应体末尾追加 `code` 与 `requestId`，并在响应头回带 `X-Request-Id`。
 *
 * 对应规范：
 *   §8.1 请求头 `X-Request-Id`（8~128 位 `[A-Za-z0-9._:-]`）、`X-App-Code`（五平台编码）
 *   §8.2 统一响应信封 `code` 与 `requestId`、错误码枚举
 *   §10.5 日志携带 requestId 以便跨服务串联
 *
 * 开关：`.env` 中 `PLATFORM_CONTRACT_ENABLED=false` 可整体关闭，回到原始行为。
 *
 * 已知偏离（见 docs/platform/deviations.md）：
 *   - 信封仍是 `{ret,msg,data}` 叠加 `{code,requestId}`，不是纯粹的规范信封；
 *   - DooTask 用 `ret=-1` 同时表示"未登录"与部分业务拒绝，映射只能取近似值。
 */
class PlatformContract
{
    /** 规范 §8.1：请求头名（大小写不敏感，此处为响应头回带用的规范写法） */
    public const HEADER_REQUEST_ID = 'X-Request-Id';
    public const HEADER_APP_CODE = 'X-App-Code';

    /** 规范 §8.1：五平台编码，固定集合，不得自造 */
    public const PLATFORM_CODES = ['forum', 'learning', 'exam', 'workshop', 'showcase'];

    /** 规范 §8.1：requestId 允许的字符与长度 */
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    /** 超过该体积的响应不做 JSON 叠加，避免额外内存与 CPU 开销（如任务导出） */
    private const MAX_DECORATE_BYTES = 2097152;

    /** 规范 §8.2：本期固定错误码集合 */
    private const CODE_OK = 'OK';
    private const CODE_VALIDATION_ERROR = 'VALIDATION_ERROR';
    private const CODE_AUTH_REQUIRED = 'AUTH_REQUIRED';
    private const CODE_FORBIDDEN = 'FORBIDDEN';
    private const CODE_NOT_FOUND = 'NOT_FOUND';
    private const CODE_CONFLICT = 'CONFLICT';
    private const CODE_BUSINESS_ERROR = 'BUSINESS_ERROR';
    private const CODE_SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    private const CODE_INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!$this->enabled()) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $requestId = $this->resolveRequestId($request);
        $appCode = trim((string) $request->headers->get(self::HEADER_APP_CODE, ''));

        // 规范 §8.1：X-App-Code 若给出，必须是五平台编码之一。
        // 存量客户端从不发送该头，因此这条分支不会影响现有功能。
        if ($appCode !== '' && !in_array($appCode, self::PLATFORM_CODES, true)) {
            $response = $this->invalidAppCodeResponse($appCode, $requestId);
            return $this->finish($response, $request, $requestId, $appCode, $startedAt);
        }

        $response = $next($request);

        $this->decorateBody($response, $requestId);

        return $this->finish($response, $request, $requestId, $appCode ?: 'dootask', $startedAt);
    }

    private function enabled(): bool
    {
        $value = env('PLATFORM_CONTRACT_ENABLED', true);
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
    }

    /**
     * 合法入站 requestId 直接沿用，否则生成 `req_<20位十六进制>`。
     *
     * 注意与"拒绝非法值"的区别：这里选择**回落**而非 400。原因是 requestId 属于
     * 追踪信息，拒绝它没有任何安全收益，反而会让上游拿到与本系统不一致的 id。
     */
    private function resolveRequestId($request): string
    {
        $inbound = trim((string) $request->headers->get(self::HEADER_REQUEST_ID, ''));
        if ($inbound !== '' && preg_match(self::REQUEST_ID_PATTERN, $inbound) === 1) {
            return $inbound;
        }
        return 'req_' . bin2hex(random_bytes(10));
    }

    /**
     * 在既有响应体末尾追加 `code` 与 `requestId`。
     *
     * 只处理「JSON 对象且含 ret 键」的响应；文件流、HTML 页面、非 JSON 一律跳过。
     * 任何一步异常都不影响原响应，确保本中间件永远不会打断业务请求。
     */
    private function decorateBody($response, string $requestId): void
    {
        if (!($response instanceof \Symfony\Component\HttpFoundation\Response)) {
            return;
        }
        if ($response instanceof BinaryFileResponse) {
            return;
        }

        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return;
        }
        if (strlen($content) > self::MAX_DECORATE_BYTES) {
            return;
        }
        $trimmed = ltrim($content);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !array_key_exists('ret', $decoded)) {
            return;
        }
        // 已经是规范信封（或已处理过），不重复叠加
        if (array_key_exists('requestId', $decoded)) {
            return;
        }

        $decoded['code'] = $this->mapCode($decoded['ret'], $response->getStatusCode(), (string) ($decoded['msg'] ?? ''));
        $decoded['requestId'] = $requestId;

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        $response->setContent($encoded);
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * 规范 §8.2：HTTP 状态与业务码一致。
     *
     * DooTask 的 `ret`：>0 成功，<=0 失败；`-1` 在身份层表示"需要登录/身份失效"，
     * 但招募域也复用 -1 表示业务拒绝（无法在中间件区分），故 -1 优先映射为
     * AUTH_REQUIRED，属已登记的已知偏离。
     *
     * 另外 DooTask 在"方法不存在"时返回 HTTP 200 + msg 前缀 `404 not found`，
     * 状态码不可靠，只能按消息判定为 NOT_FOUND。
     */
    private function mapCode($ret, int $status, string $msg = ''): string
    {
        $ret = is_numeric($ret) ? (int) $ret : 0;

        if ($ret > 0) {
            return self::CODE_OK;
        }

        switch ($status) {
            case 400:
                return self::CODE_VALIDATION_ERROR;
            case 401:
                return self::CODE_AUTH_REQUIRED;
            case 403:
                return self::CODE_FORBIDDEN;
            case 404:
                return self::CODE_NOT_FOUND;
            case 409:
                return self::CODE_CONFLICT;
            case 429:
            case 502:
            case 503:
                return self::CODE_SERVICE_UNAVAILABLE;
            case 500:
                return self::CODE_INTERNAL_ERROR;
        }

        if (stripos($msg, '404 not found') === 0) {
            return self::CODE_NOT_FOUND;
        }

        return $ret === -1 ? self::CODE_AUTH_REQUIRED : self::CODE_BUSINESS_ERROR;
    }

    /**
     * 非法的 X-App-Code 返回。
     *
     * 同时保留 `ret/msg/data` 与规范 `code/requestId`，这样即使客户端误发了
     * 非法头，按 `ret` 判断成败的老代码也不会突然拿到看不懂的结构。
     */
    private function invalidAppCodeResponse(string $appCode, string $requestId)
    {
        $body = [
            'ret' => 0,
            'msg' => '请求头 X-App-Code 非法，只接受 forum/learning/exam/workshop/showcase',
            'data' => [
                'reason' => 'APP_CODE_INVALID',
                'appCode' => $appCode,
            ],
            'code' => self::CODE_VALIDATION_ERROR,
            'requestId' => $requestId,
        ];

        return new \Illuminate\Http\JsonResponse($body, 400, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 统一收尾：回带 requestId 响应头 + 写访问日志。
     */
    private function finish($response, $request, string $requestId, string $appCode, float $startedAt)
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->headers->set(self::HEADER_REQUEST_ID, $requestId);
        }

        $this->writeAccessLog($request, $response, $requestId, $appCode, $startedAt);

        return $response;
    }

    /**
     * 规范 §10.5 / §12：跨服务用 requestId 串联，日志不记录 Token 与敏感载荷。
     * 只记录请求元信息，不记录请求体。
     */
    private function writeAccessLog($request, $response, string $requestId, string $appCode, float $startedAt): void
    {
        try {
            $status = 0;
            if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
                $status = $response->getStatusCode();
            }

            $line = [
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'requestId' => $requestId,
                'appCode' => $appCode,
                'method' => $request->getMethod(),
                'path' => '/' . ltrim($request->path(), '/'),
                'status' => $status,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'ip' => $request->getClientIp(),
            ];

            $file = storage_path('logs/platform-access.log');
            @file_put_contents(
                $file,
                json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // 日志失败绝不影响业务响应
        }
    }
}
