<?php

namespace App\Http\Middleware;

use App\Services\GatewaySigner;
use Closure;

/**
 * 网关信任层（v1 接口专用）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0：
 *   §5.3.2 网关注入的可信头
 *   §5.4   公开路径白名单（精确匹配，不用前缀通配）
 *   §5.5   可信头防伪造：必须校验 X-Internal-Sign，不能只靠网络隔离
 *   §6.8   CORS 白名单
 *
 * 只挂在 `/api/v1/workshop/*` 路由组上，对存量 `/api/{module}/{method}`
 * 完全无影响——这是"不影响现有功能"的硬边界。
 */
class GatewayTrust
{
    /** §5.4 公开路径白名单：不要求内部签名 */
    private const PUBLIC_PATHS = [
        'api/v1/workshop/auth/login',
        'api/v1/workshop/auth/oauth/callback',
        'api/v1/workshop/auth/forum-verify',
        'api/v1/workshop/auth/logged-out',
        'api/v1/workshop/health',
    ];

    /** 避免同一进程内重复刷告警 */
    private static $warned = [];

    /**
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->preflight($request);
        }

        $path = trim($request->path(), '/');
        $isPublic = in_array($path, self::PUBLIC_PATHS, true);

        if (!$isPublic && !$this->fromTrustedNetwork($request)) {
            return $this->reject($request, GatewaySigner::REASON_NOT_TRUSTED_NETWORK, '请求来源不在网关可信网段内');
        }

        $trusted = false;

        if (!$isPublic) {
            $result = $this->verify($request);
            if (!$result['ok']) {
                return $this->reject($request, $result['reason'], $this->reasonMessage($result['reason']), $result);
            }
            $trusted = (bool) $result['trusted'];
        }

        // 只有验签通过（或显式关闭验签的调试模式）才允许后续读取 X-User-Id；
        // 公开路径一律不信任该头，避免有人用登录回调伪造身份。
        $request->attributes->set('gateway.trusted', $trusted);

        $response = $next($request);

        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->headers->set('Cache-Control', 'no-store');
            self::applyCors($request, $response);
        }

        return $response;
    }

    /**
     * 校验内部签名
     *
     * @param \Illuminate\Http\Request $request
     * @return array{ok:bool,reason:string,trusted:bool}
     */
    private function verify($request): array
    {
        $enabled = $this->boolConfig('platform.gateway.verify_sign', true);
        // 统一走 GatewaySigner::internalSecret()：直填值与文件注入都在那里处理并 trim
        $secret = GatewaySigner::internalSecret();

        if (!$enabled) {
            // §5.5.3 调试开关：必须打印醒目告警，生产靠配置评审卡住
            $this->warnOnce('verify_off', '[platform] GATEWAY_VERIFY_SIGN=false，已跳过可信头验签，仅可用于本地调试');
            return ['ok' => true, 'reason' => '', 'trusted' => true];
        }

        if ($secret === '') {
            // 本地/测试允许"放行但不信任"（否则前端联调连不上）；
            // 其他环境一律拒绝——生产上"空密钥 + allow"等于把可信头交给网络隔离兜底。
            if ($this->allowDirectAccess() && $this->localEnvironment()) {
                $this->warnOnce('secret_empty', '[platform] GATEWAY_INTERNAL_SECRET 未配置且允许直连，可信头不被信任');
                return ['ok' => true, 'reason' => '', 'trusted' => false];
            }
            return ['ok' => false, 'reason' => GatewaySigner::REASON_SECRET_MISSING, 'trusted' => false];
        }

        if (!GatewaySigner::isWellFormedSecret($secret)) {
            // 配置错误与签名错误必须分开报：前者改 .env 就好，后者要查对端算法，
            // 混成一个 SIGN_MISMATCH 会让排查方向完全跑偏。审计由外层 reject() 统一写。
            return ['ok' => false, 'reason' => GatewaySigner::REASON_SECRET_MALFORMED, 'trusted' => false];
        }

        $skew = (int) config('platform.gateway.skew', 300);
        $result = GatewaySigner::verifyTrust($request->headers->all(), $secret, $skew);

        if (!$result['ok'] && $result['reason'] === GatewaySigner::REASON_SIGN_MISSING && $this->allowDirectAccess()) {
            // 本地直连：允许通过，但绝不信任身份头
            $this->warnOnce('direct', '[platform] 检测到无签名的直连请求，已按匿名处理（GATEWAY_DIRECT_ACCESS=allow）');
            return ['ok' => true, 'reason' => '', 'trusted' => false];
        }

        return [
            'ok' => $result['ok'],
            'reason' => $result['reason'],
            'trusted' => $result['ok'],
            // 时间偏移要一路传到 reject()，否则对端只能看到 CLOCK_SKEW 却不知道差了多少秒
            'skew' => (int) ($result['skew'] ?? 0),
        ];
    }

    /**
     * 来源网段校验（配置为空则跳过，由安全组承担第一道防线）
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private function fromTrustedNetwork($request): bool
    {
        $cidr = trim((string) config('platform.gateway.trusted_cidr', ''));
        if ($cidr === '') {
            return true;
        }

        $ip = (string) $request->getClientIp();
        if ($ip === '') {
            return false;
        }

        foreach (explode(',', $cidr) as $segment) {
            if ($this->ipInCidr($ip, trim($segment))) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv4 CIDR 匹配（也支持单 IP）
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        list($subnet, $bits) = explode('/', $cidr, 2);
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * 拒绝响应：HTTP 403 + FORBIDDEN + 结构化 reason
     *
     * @param \Illuminate\Http\Request $request
     * @param string $reason
     * @param string $msg
     * @param array $extra
     * @return \Illuminate\Http\JsonResponse
     */
    private function reject($request, string $reason, string $msg, array $extra = [])
    {
        $data = ['reason' => $reason ?: 'FORBIDDEN'];
        if (isset($extra['skew']) && $extra['skew'] > 0) {
            $data['skewSeconds'] = (int) $extra['skew'];
        }

        $this->audit($request, $reason, $data);

        return \App\Services\PlatformResponse::fail(
            \App\Services\PlatformResponse::FORBIDDEN,
            $msg,
            $data,
            403
        );
    }

    /**
     * 安全事件审计（不记录密钥材料）
     *
     * @param \Illuminate\Http\Request $request
     * @param string $reason
     * @param array $data
     * @return void
     */
    private function audit($request, string $reason, array $data): void
    {
        try {
            $line = [
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'event' => 'gateway_reject',
                'reason' => $reason,
                'path' => '/' . ltrim($request->path(), '/'),
                'method' => $request->getMethod(),
                'ip' => $request->getClientIp(),
                'hasSign' => $request->headers->has('X-Internal-Sign'),
                'skewSeconds' => $data['skewSeconds'] ?? 0,
            ];
            @file_put_contents(
                storage_path('logs/platform-security.log'),
                json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // 审计失败不影响拒绝本身
        }
    }

    /**
     * CORS 预检
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    private function preflight($request)
    {
        $response = new \Illuminate\Http\Response('', 204);
        self::applyCors($request, $response);
        return $response;
    }

    /**
     * 裁决并写入 CORS 响应头
     *
     * §6.8：Allow-Origin 不能为 * 且同时带 Credentials，必须回显具体来源。
     *
     * 两种终态都必须给出**确定值**，不能"不处理"：
     * `Fruitcake\Cors\HandleCors` 在 `RequestHandled` 事件里会补一次头，
     * 只在响应中尚无 `Access-Control-Allow-Origin` 时才动手；一旦我们什么都不写，
     * 它就会补上 `*`，把白名单直接废掉。因此未命中白名单时写 `null`——
     * 这是 CORS 规范里的"不透明来源"，任何正常页面都匹配不上，浏览器必然拦截，
     * 同时又让上述兜底逻辑无话可说。
     *
     * 声明为 public static 是为了让 GatewayCors（全局中间件）复用同一套策略。
     *
     * @param \Illuminate\Http\Request $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     * @return void
     */
    public static function applyCors($request, $response): void
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin === '') {
            return;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) config('platform.gateway.cors_origins', ''))));

        if (!$allowed || !in_array($origin, $allowed, true)) {
            $response->headers->set('Access-Control-Allow-Origin', 'null');
            $response->headers->remove('Access-Control-Allow-Credentials');
            $response->headers->remove('Access-Control-Allow-Methods');
            $response->headers->remove('Access-Control-Allow-Headers');
            $response->headers->remove('Access-Control-Max-Age');
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Request-Id, X-App-Code, Idempotency-Key, X-CSRF-Token, token');
        $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id');
        $response->headers->set('Access-Control-Max-Age', '600');
    }

    /**
     * @param string $reason
     * @return string
     */
    private function reasonMessage(string $reason): string
    {
        switch ($reason) {
            case GatewaySigner::REASON_SIGN_MISSING:
                return '缺少 X-Internal-Sign，疑似绕过网关直连';
            case GatewaySigner::REASON_SIGN_MISMATCH:
                return '可信头签名校验失败';
            case GatewaySigner::REASON_CLOCK_SKEW:
                return '请求时间戳超出允许窗口';
            case GatewaySigner::REASON_TIMESTAMP_MISSING:
                return '缺少 X-Timestamp';
            case GatewaySigner::REASON_SECRET_MISSING:
                return '服务端未配置网关内部密钥，拒绝未验签请求';
            case GatewaySigner::REASON_SECRET_MALFORMED:
                return '网关内部密钥格式不合法（含空白字符或长度过短），请检查配置';
            default:
                return '无权访问';
        }
    }

    /**
     * @return bool
     */
    private function allowDirectAccess(): bool
    {
        return strtolower((string) config('platform.gateway.direct_access', 'reject')) === 'allow';
    }

    /**
     * 是否本地/测试环境
     *
     * 只有这两种环境允许"没有密钥也放行"的降级：
     *   - local    —— 前端联调、离线演示
     *   - testing  —— 测试进程
     * 生产与预发布一律走拒绝分支，避免"allow 忘了改回 reject"把可信头交给网络隔离兜底。
     *
     * @return bool
     */
    private function localEnvironment(): bool
    {
        try {
            return app()->environment(['local', 'testing']);
        } catch (\Throwable $e) {
            // 容器未就绪等极端情况：按"非本地"处理，宁可拒绝
            return false;
        }
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

    /**
     * @param string $key
     * @param string $message
     * @return void
     */
    private function warnOnce(string $key, string $message): void
    {
        if (isset(self::$warned[$key])) {
            return;
        }
        self::$warned[$key] = true;
        try {
            @file_put_contents(
                storage_path('logs/platform-security.log'),
                json_encode([
                    'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                    'event' => 'gateway_warning',
                    'warning' => $key,
                    'message' => $message,
                ], JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
        }
    }
}
