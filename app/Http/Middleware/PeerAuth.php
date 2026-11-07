<?php

namespace App\Http\Middleware;

use App\Services\PeerSigner;
use App\Services\PlatformResponse;
use Closure;

/**
 * 三平台对端层鉴权（《三平台对接方案 V2.0》§5.2 对端层）
 *
 * 校验四样东西，缺一项或错一项都不放行：
 *   1. X-App-Code 必须是已登记且 enabled 的通道——未登记 403 PEER_NOT_OPEN
 *   2. X-Request-Id 格式合法（8~128 位 [A-Za-z0-9._:-]）
 *   3. X-Timestamp 在 ±skew 秒内——超窗 401（时钟偏移是三端联调最常见的 401 原因）
 *   4. X-Internal-Sign 与按原始字节重算的 HMAC 一致
 *
 * 另外做通道绑定：路径里的 {source} 必须与 X-App-Code 相同，
 * 否则 exam 就能拿自己的密钥访问本该只给 learning 的通道（403 PEER_SCOPE_DENIED）。
 *
 * 免签模式（auth_mode=none）只允许在 auth_none_envs 列出的环境启用，
 * 且即便免签也照做通道绑定校验——联调期同样不该出现跨平台越权。
 */
class PeerAuth
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $appCode = trim((string) $request->headers->get(PeerSigner::HEADER_APP_CODE, ''));
        $requestId = trim((string) $request->headers->get(PeerSigner::HEADER_REQUEST_ID, ''));
        $source = $request->route('source');

        // —— 免签模式（联调期）：仅白名单环境可用 ——
        if ($this->authNoneAllowed()) {
            $bound = $source !== null && $appCode !== '' && $source !== $appCode;
            if ($bound) {
                return $this->deny(
                    'PEER_SCOPE_DENIED',
                    '通道与调用方编码不一致：' . $appCode . ' 不能访问 ' . $source . ' 通道',
                    403
                );
            }
            $request->attributes->set('peer.source', $appCode !== '' ? $appCode : (string) $source);
            $request->attributes->set('peer.request_id', $requestId !== '' ? $requestId : PeerSigner::newRequestId());
            $request->attributes->set('peer.verified', false);
            $request->attributes->set('peer.auth_mode', 'none');

            return $next($request);
        }

        // —— 1. 通道登记 ——
        if ($appCode === '') {
            return $this->deny('AUTH_REQUIRED', '缺少请求头 X-App-Code', 401);
        }

        $channel = config('platform.peers.channels.' . $appCode);
        if (!is_array($channel)) {
            return $this->deny('PEER_NOT_OPEN', '未登记的对端编码：' . $appCode, 403);
        }
        if (!$this->enabled($channel)) {
            return $this->deny('PEER_NOT_OPEN', '通道未开启：' . $appCode, 403);
        }

        $secret = (string) ($channel['secret'] ?? '');
        if ($secret === '') {
            // 未配密钥按"通道不可用"处理，而不是"跳过验签"——后者等于开了后门
            return $this->deny('PEER_NOT_OPEN', '通道未配置密钥：' . $appCode, 403);
        }

        // —— 2. requestId ——
        if (!PeerSigner::validRequestId($requestId)) {
            return $this->deny('AUTH_REQUIRED', 'X-Request-Id 缺失或格式非法', 401);
        }

        // —— 3. 时间窗 ——
        $timestampRaw = trim((string) $request->headers->get(PeerSigner::HEADER_TIMESTAMP, ''));
        if ($timestampRaw === '' || !ctype_digit($timestampRaw)) {
            return $this->deny('AUTH_REQUIRED', 'X-Timestamp 缺失或非整数秒', 401);
        }
        $skew = max(0, (int) config('platform.peers.skew', 300));
        $drift = abs(time() - (int) $timestampRaw);
        if ($drift > $skew) {
            return $this->deny(
                'AUTH_REQUIRED',
                '请求时间戳超出允许窗口：偏移 ' . $drift . ' 秒，上限 ' . $skew . ' 秒',
                401
            );
        }

        // —— 4. 验签（对原始字节）——
        $signature = (string) $request->headers->get(PeerSigner::HEADER_SIGNATURE, '');
        if ($signature === '') {
            return $this->deny('AUTH_REQUIRED', '缺少请求头 X-Internal-Sign', 401);
        }
        $rawBody = $request->getContent();
        if (!PeerSigner::verify($secret, $appCode, $requestId, $timestampRaw, $rawBody, $signature)) {
            return $this->deny('AUTH_REQUIRED', '对端层签名校验失败', 401);
        }

        // —— 5. 通道绑定 ——
        if ($source !== null && $source !== $appCode) {
            return $this->deny(
                'PEER_SCOPE_DENIED',
                '通道与调用方编码不一致：' . $appCode . ' 不能访问 ' . $source . ' 通道',
                403
            );
        }

        $request->attributes->set('peer.source', $appCode);
        $request->attributes->set('peer.request_id', $requestId);
        $request->attributes->set('peer.verified', true);
        $request->attributes->set('peer.auth_mode', 'auto');

        return $next($request);
    }

    /**
     * 当前环境是否允许免签
     *
     * 生产环境即便误配 auth_mode=none 也不会生效——这类"方便开关"一旦
     * 被带上线就是免密后门，必须在代码层兜住。
     *
     * @return bool
     */
    private function authNoneAllowed(): bool
    {
        $mode = strtolower(trim((string) config('platform.peers.auth_mode', 'auto')));
        if ($mode !== 'none') {
            return false;
        }

        $raw = (string) config('platform.peers.auth_none_envs', 'local,testing');
        $envs = array_filter(array_map('trim', explode(',', strtolower($raw))));
        $current = strtolower(app()->environment());

        return in_array($current, $envs, true) && !in_array($current, ['production', 'prod'], true);
    }

    /**
     * 通道是否启用（兼容 "0"/"false" 等字符串写法）
     *
     * @param array $channel
     * @return bool
     */
    private function enabled(array $channel): bool
    {
        $value = $channel['enabled'] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
    }

    /**
     * 拒绝响应：code 直接用方案 §10.4 的对端错误码
     *
     * @param string $code
     * @param string $message
     * @param int $status
     * @return \Illuminate\Http\JsonResponse
     */
    private function deny(string $code, string $message, int $status)
    {
        return PlatformResponse::fail($code, $message, ['reason' => $code], $status);
    }
}
