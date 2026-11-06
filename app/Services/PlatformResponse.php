<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;

/**
 * 统一响应信封（v1 接口）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §6.4、§6.5。
 *
 * v1 与存量 RPC 接口的差别只有两点，其余保持同构：
 *   1. HTTP 状态码与 code 成对出现（不再出现 200 + FORBIDDEN / 401 内容返回 200）；
 *   2. 追加 message 别名（值等于 msg），兼容考试平台的通用客户端（§6.4.3 规则二）。
 *
 * 成功判据对外一律是 code === 'OK'；ret 保留给存量前端。
 */
class PlatformResponse
{
    public const OK = 'OK';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const NOT_FOUND = 'NOT_FOUND';
    public const CONFLICT = 'CONFLICT';
    public const BUSINESS_ERROR = 'BUSINESS_ERROR';
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /** code → HTTP 状态（§6.5.2：两者必须成对出现） */
    private const STATUS_MAP = [
        self::OK => 200,
        self::VALIDATION_ERROR => 400,
        self::AUTH_REQUIRED => 401,
        self::FORBIDDEN => 403,
        self::NOT_FOUND => 404,
        self::CONFLICT => 409,
        // 业务规则拒绝沿用 200：对端以 code 判定，避免浏览器把业务提示当网络错误
        self::BUSINESS_ERROR => 200,
        self::SERVICE_UNAVAILABLE => 503,
        self::INTERNAL_ERROR => 500,
    ];

    /**
     * 成功响应
     *
     * @param mixed $data
     * @param string $msg
     * @return JsonResponse
     */
    public static function ok($data = [], string $msg = 'success'): JsonResponse
    {
        return self::build(1, $msg, $data, self::OK, 200);
    }

    /**
     * 失败响应
     *
     * @param string $code 9 个规范码之一
     * @param string $msg 人类可读提示
     * @param array $data 结构化补充（reason 等），失败时不得为 null
     * @param int|null $status 覆盖默认状态码
     * @return JsonResponse
     */
    public static function fail(string $code, string $msg, array $data = [], ?int $status = null): JsonResponse
    {
        $status = $status ?? (self::STATUS_MAP[$code] ?? 200);
        return self::build(-1, $msg, $data ?: [], $code, $status);
    }

    /**
     * 业务拒绝（HTTP 200 + BUSINESS_ERROR，§6.5.1）
     *
     * @param string $msg
     * @param array $data
     * @return JsonResponse
     */
    public static function businessError(string $msg, array $data = []): JsonResponse
    {
        return self::fail(self::BUSINESS_ERROR, $msg, $data, 200);
    }

    /**
     * 未认证（401 + AUTH_REQUIRED，§6.5.2 末段）
     *
     * @param string $msg
     * @param array $data
     * @return JsonResponse
     */
    public static function authRequired(string $msg = '请登录后继续...', array $data = []): JsonResponse
    {
        return self::fail(self::AUTH_REQUIRED, $msg, $data, 401);
    }

    /**
     * 构造信封
     *
     * @param int $ret
     * @param string $msg
     * @param mixed $data
     * @param string $code
     * @param int $status
     * @return JsonResponse
     */
    private static function build(int $ret, string $msg, $data, string $code, int $status): JsonResponse
    {
        $requestId = self::requestId();

        $body = [
            'ret' => $ret,
            'msg' => $msg,
            // 失败时 data 不得为 null（§6.4.3 规则三），对端可能按 null 误判
            'data' => $data === null ? [] : $data,
            'code' => $code,
            'requestId' => $requestId,
            // message 别名：只追加，不改 msg
            'message' => $msg,
        ];

        return new JsonResponse($body, $status, [
            'X-Request-Id' => $requestId,
            'Cache-Control' => 'no-store',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 取当前请求的追踪号（与 PlatformContract 中间件同值）
     *
     * @return string
     */
    public static function requestId(): string
    {
        $requestId = '';
        if (function_exists('request') && request()) {
            $requestId = (string) request()->attributes->get('platform.request_id', '');
        }
        if ($requestId === '') {
            $requestId = 'req_' . bin2hex(random_bytes(10));
        }
        return $requestId;
    }
}
