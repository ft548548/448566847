<?php

namespace App\Services;

use App\Models\ExamAdmissionProjection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 考试平台客户端（准入回源）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0：
 *   §8.3.1 判定流程（投影 → 缓存 → 回源）
 *   §8.3.3 超时与降级：超时返回 pending，**不得**判为不合格
 *   §8.3.4 缓存规则：键 admission:{subject}:{courseId}、TTL 60 秒、只缓存明确结论
 *   §12.4  L2 签名：method / path / query / body 四段，时间窗 ±300s
 *
 * 本类只读不回写：准入结论的权威在考试平台，本平台不做二次判定（§1.3.3）。
 */
class ExamClient
{
    /** 规范 reason 枚举（§8.3.2），下游不得自造第二套 */
    public const REASON_PASSED = 'passed';
    public const REASON_NOT_STARTED = 'not_started';
    public const REASON_FAILED = 'failed';
    public const REASON_VIOLATION = 'violation';
    public const REASON_PENDING = 'pending';
    public const REASON_PREREQUISITE_MISSING = 'prerequisite_missing';

    /** 结论来源，便于联调时区分"真的回源了"还是"命中了投影/缓存" */
    public const SOURCE_REMOTE = 'remote';
    public const SOURCE_CACHE = 'cache';
    public const SOURCE_PROJECTION = 'projection';
    public const SOURCE_MOCK = 'mock';

    /**
     * 查询准入结论
     *
     * @param string $subject 论坛 sub 原值
     * @param string $courseId 课程ID（原值）
     * @param string $requestId 追踪号，透传给对端
     * @return array
     */
    public function admission(string $subject, string $courseId, string $requestId = ''): array
    {
        if ($courseId === '') {
            // 不绑定课程 = 不校验准入（§10.4.2：这是保证存量流程不变的开关）
            return $this->result(true, self::REASON_PASSED, null, self::SOURCE_REMOTE, false, '');
        }

        // ① 投影快路径（只加速；命中投影也不作为拒绝依据，见 checkOrReject）
        $projection = $this->fromProjection($subject, $courseId);
        if ($projection !== null) {
            return $projection;
        }

        // ② 结果缓存（60 秒）
        $cacheKey = $this->cacheKey($subject, $courseId);
        if ($this->cacheEnabled()) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['eligible'])) {
                $cached['source'] = self::SOURCE_CACHE;
                $cached['cached'] = true;
                return $cached;
            }
        }

        // ③ 回源
        if ($this->mockEnabled()) {
            return $this->mockResult($subject, $courseId);
        }

        $result = $this->requestRemote($subject, $courseId, $requestId);

        // ④ 只缓存明确结论：pending 不入缓存，否则考试平台恢复后用户还要被挡一分钟
        if ($this->cacheEnabled() && $result['reason'] !== self::REASON_PENDING && $result['source'] === self::SOURCE_REMOTE) {
            Cache::put($cacheKey, $result, $this->cacheTtl());
        }

        return $result;
    }

    /**
     * 判定是否放行（供报名等写操作调用）
     *
     * 返回结构固定为 `[allow, result]`：
     *   - eligible=true            → allow=true
     *   - eligible=false + passed  → allow=true（理论不会出现，防御）
     *   - 其它 reason              → allow=false，reason 原样返回给用户
     *
     * @param string $subject
     * @param string $courseId
     * @param string $requestId
     * @return array{allow:bool,result:array}
     */
    public function check(string $subject, string $courseId, string $requestId = ''): array
    {
        $result = $this->admission($subject, $courseId, $requestId);

        $allow = (bool) $result['eligible'] || $result['reason'] === self::REASON_PASSED;

        return ['allow' => $allow, 'result' => $result];
    }

    /**
     * 把 reason 翻译为面向用户的文案（§8.3.2）
     *
     * 下游不得自己写第二套映射——同一原因出现两种说法就会在联调时对不上。
     *
     * @param string $reason
     * @param string $courseName
     * @return string
     */
    public static function reasonMessage(string $reason, string $courseName = '相关课程'): string
    {
        switch ($reason) {
            case self::REASON_PASSED:
                return '资格校验通过';
            case self::REASON_NOT_STARTED:
                return '请先完成「' . $courseName . '」考试';
            case self::REASON_FAILED:
                return '考试成绩未达标，请重新考试';
            case self::REASON_VIOLATION:
                return '考试违规次数超限，请联系教务';
            case self::REASON_PENDING:
                return '资格校验中，请稍后重试';
            case self::REASON_PREREQUISITE_MISSING:
                return '请先完成前置课程';
            default:
                return '准入资格校验未通过';
        }
    }

    /**
     * 从投影表读取未过期结论
     *
     * @param string $subject
     * @param string $courseId
     * @return array|null
     */
    private function fromProjection(string $subject, string $courseId): ?array
    {
        try {
            $row = ExamAdmissionProjection::whereSubject($subject)
                ->whereCourseId($courseId)
                ->first();
        } catch (\Throwable $e) {
            // 表不存在或查询异常时降级为回源，不能因为投影故障挡住用户
            return null;
        }

        if (!$row || empty($row->expires_at)) {
            return null;
        }
        if (strtotime((string) $row->expires_at) <= time()) {
            return null;
        }

        return $this->result(
            (int) $row->eligible === 1,
            (string) $row->reason,
            $row->score === null ? null : (int) $row->score,
            self::SOURCE_PROJECTION,
            true,
            (string) $row->rule_version,
            $row->granted_at ? $row->granted_at->toIso8601String() : null
        );
    }

    /**
     * 回源考试平台
     *
     * @param string $subject
     * @param string $courseId
     * @param string $requestId
     * @return array
     */
    private function requestRemote(string $subject, string $courseId, string $requestId): array
    {
        $baseUrl = rtrim((string) config('platform.exam.base_url', ''), '/');
        $path = rtrim((string) config('platform.exam.admission_path', '/api/v1/exam/internal/admissions'), '/')
            . '/' . rawurlencode($subject)
            . '/' . rawurlencode($courseId);

        $secret = (string) config('platform.exam.app_secret', '');
        $headers = GatewaySigner::callHeaders(
            $secret,
            (string) config('platform.exam.app_key', 'svc-workshop'),
            (string) config('platform.app_code', 'workshop'),
            'GET',
            $path,
            '',
            '',
            $requestId
        );

        $timeout = max(0.2, ((int) config('platform.exam.timeout_ms', 1500)) / 1000);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->get($baseUrl . $path);
        } catch (\Throwable $e) {
            // 超时 / DNS / 连接失败：一律 pending，绝不判为不合格（§8.3.3）
            Log::warning('[exam] admission request failed: ' . $e->getMessage(), [
                'subject' => $subject,
                'courseId' => $courseId,
                'requestId' => $requestId,
            ]);
            return $this->result(false, self::REASON_PENDING, null, self::SOURCE_REMOTE, false, '');
        }

        $status = $response->status();
        if ($status === 401 || $status === 403) {
            // 凭据问题属于平台级故障，静默放行会让准入彻底失效
            Log::error('[exam] admission unauthorized', ['status' => $status, 'requestId' => $requestId]);
            return $this->result(false, self::REASON_PENDING, null, self::SOURCE_REMOTE, false, '')
                + ['serviceUnavailable' => true];
        }

        if (!$response->ok()) {
            return $this->result(false, self::REASON_PENDING, null, self::SOURCE_REMOTE, false, '');
        }

        $body = $response->json();
        if (!is_array($body)) {
            return $this->result(false, self::REASON_PENDING, null, self::SOURCE_REMOTE, false, '');
        }

        // §6.4.3 规则一：以 code 为成功判据，不能只看 HTTP 200
        $code = (string) ($body['code'] ?? '');
        if ($code !== '' && $code !== PlatformResponse::OK) {
            Log::warning('[exam] admission business error', ['code' => $code, 'body' => $body]);
            return $this->result(false, self::REASON_PENDING, null, self::SOURCE_REMOTE, false, '');
        }

        $data = $body['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $reason = (string) ($data['reason'] ?? '');
        if (!in_array($reason, $this->reasonEnum(), true)) {
            // 未知 reason 按 pending 处理：宁可让用户等，也不能凭空拒绝
            $reason = self::REASON_PENDING;
        }

        return $this->result(
            (bool) ($data['eligible'] ?? false),
            $reason,
            isset($data['score']) ? (int) $data['score'] : null,
            self::SOURCE_REMOTE,
            false,
            (string) ($data['ruleVersion'] ?? $data['rule_version'] ?? '')
        );
    }

    /**
     * mock 结果（本地/离线演示）
     *
     * 明确标记 source=mock，避免联调时把演示数据当作真实回源结论。
     *
     * @param string $subject
     * @param string $courseId
     * @return array
     */
    private function mockResult(string $subject, string $courseId): array
    {
        $eligible = $this->boolConfig('platform.exam.mock_eligible', true);
        $reason = (string) config('platform.exam.mock_reason', self::REASON_PASSED);

        $result = $this->result($eligible, $eligible ? self::REASON_PASSED : $reason, $eligible ? 100 : 52, self::SOURCE_MOCK, false, 'mock-v1');
        $result['mock'] = true;

        return $result;
    }

    /**
     * 统一结果结构
     *
     * @param bool $eligible
     * @param string $reason
     * @param int|null $score
     * @param string $source
     * @param bool $cached
     * @param string $ruleVersion
     * @return array
     */
    private function result(bool $eligible, string $reason, ?int $score, string $source, bool $cached, string $ruleVersion, ?string $grantedAt = null): array
    {
        $now = new \DateTimeImmutable('now');
        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'score' => $score,
            'ruleVersion' => $ruleVersion,
            'source' => $source,
            'cached' => $cached,
            'checkedAt' => $now->format('c'),
            'grantedAt' => $grantedAt,
        ];
    }

    /**
     * 是否启用 mock（未配置 base_url 时自动 mock）
     *
     * @return bool
     */
    private function mockEnabled(): bool
    {
        $value = config('platform.exam.mock', null);
        if ($value !== null && $value !== '') {
            return $this->boolConfig('platform.exam.mock', false);
        }
        return trim((string) config('platform.exam.base_url', '')) === '';
    }

    /**
     * @return bool
     */
    private function cacheEnabled(): bool
    {
        return $this->boolConfig('platform.exam.cache_enabled', true);
    }

    /**
     * @return int
     */
    private function cacheTtl(): int
    {
        // 上限 1800 秒（§13.1：不得 > 1800）
        return min(1800, max(1, (int) config('platform.exam.cache_ttl', 60)));
    }

    /**
     * @param string $subject
     * @param string $courseId
     * @return string
     */
    private function cacheKey(string $subject, string $courseId): string
    {
        // 键与考试平台缓存键同构，便于对账（§8.3.4）
        return 'admission:' . $subject . ':' . $courseId;
    }

    /**
     * @return array
     */
    private function reasonEnum(): array
    {
        return [
            self::REASON_PASSED,
            self::REASON_NOT_STARTED,
            self::REASON_FAILED,
            self::REASON_VIOLATION,
            self::REASON_PENDING,
            self::REASON_PREREQUISITE_MISSING,
        ];
    }

    /**
     * @param string $key
     * @param bool $default
     * @return bool
     */
    private function boolConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
    }
}
