<?php

namespace App\Http\Middleware;

use App\Services\PlatformResponse;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 幂等键（Idempotency-Key）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §6.7.1。
 *
 * 判定顺序（与文档一致）：
 *   1. 取 Idempotency-Key，缺失 → 400 VALIDATION_ERROR
 *   2. 命中且已完成 → 直接返回缓存 {status, body}
 *   3. 命中且处理中 → 409 CONFLICT
 *   4. 未命中 → 写占位（60s）→ 业务事务 → 覆盖为最终结果（24h）
 *
 * 作用域必须绑定调用者身份（`auth:idem:{subject}:{key}`），否则两个人用同一个
 * 键会互相拿到对方的响应。subject 为空时退回客户端 IP，仍然隔离。
 *
 * 只缓存 2xx 结果：业务拒绝（如"你已报名"）不缓存，否则用户改条件后重试
 * 会一直拿到旧结论。
 */
class Idempotency
{
    private const PREFIX = 'auth:idem:';

    /**
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!$this->enabled() || !in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($key === '') {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                '写操作必须携带 Idempotency-Key',
                ['reason' => 'IDEMPOTENCY_KEY_REQUIRED'],
                400
            );
        }

        if (strlen($key) < 8 || strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                'Idempotency-Key 格式非法（8~128 位 [A-Za-z0-9._:-]）',
                ['reason' => 'IDEMPOTENCY_KEY_INVALID'],
                400
            );
        }

        $cacheKey = $this->cacheKey($request, $key);

        // 原子占位：add 成功说明本请求是首次执行
        $acquired = Cache::add($cacheKey, ['status' => 'processing', 'at' => time()], $this->lockTtl());
        if (!$acquired) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ($cached['status'] ?? '') === 'done') {
                return $this->replay($cached);
            }
            return PlatformResponse::fail(
                PlatformResponse::CONFLICT,
                '相同 Idempotency-Key 的请求正在处理中，请勿重复提交',
                ['reason' => 'IDEMPOTENCY_IN_PROGRESS'],
                409
            );
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // 异常不缓存，允许调用方用同一个键重试
            Cache::forget($cacheKey);
            throw $e;
        }

        $status = $response instanceof \Symfony\Component\HttpFoundation\Response
            ? $response->getStatusCode()
            : 200;

        if ($status >= 200 && $status < 300) {
            $body = $response instanceof \Symfony\Component\HttpFoundation\Response
                ? (string) $response->getContent()
                : '';
            Cache::put($cacheKey, [
                'status' => 'done',
                'http' => $status,
                'body' => $body,
                'at' => time(),
            ], $this->ttl());
        } else {
            Cache::forget($cacheKey);
        }

        return $response;
    }

    /**
     * 回放缓存结果
     *
     * @param array $cached
     * @return \Illuminate\Http\JsonResponse
     */
    private function replay(array $cached)
    {
        $body = $cached['body'] ?? '';
        $decoded = is_string($body) ? json_decode($body, true) : null;

        if (!is_array($decoded)) {
            return PlatformResponse::ok([], 'success');
        }

        // 重放的是业务结果，不是追踪号：requestId 必须是本次请求的，
        // 否则按 requestId 检索日志会串到上一次请求上（§6.4.3 规则四）。
        $decoded['idempotentReplay'] = true;
        $decoded['requestId'] = PlatformResponse::requestId();

        return new \Illuminate\Http\JsonResponse(
            $decoded,
            (int) ($cached['http'] ?? 200),
            [
                'X-Request-Id' => PlatformResponse::requestId(),
                'Cache-Control' => 'no-store',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 幂等作用域：绑定身份，避免跨用户串号
     *
     * @param \Illuminate\Http\Request $request
     * @param string $key
     * @return string
     */
    private function cacheKey($request, string $key): string
    {
        $subject = trim((string) $request->headers->get('X-User-Id', ''));
        if ($subject === '') {
            $subject = 'ip-' . (string) $request->getClientIp();
        }
        return self::PREFIX . sha1($subject) . ':' . $key;
    }

    /**
     * @return bool
     */
    private function enabled(): bool
    {
        $value = config('platform.idempotency.enabled', true);
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
    }

    /**
     * @return int
     */
    private function ttl(): int
    {
        return max(60, (int) config('platform.idempotency.ttl', 86400));
    }

    /**
     * @return int
     */
    private function lockTtl(): int
    {
        return max(10, (int) config('platform.idempotency.lock_ttl', 60));
    }
}
