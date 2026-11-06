<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * 服务端会话（Redis 存储 + HttpOnly Cookie）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §4.3.2、§4.5。
 *
 * 三条纪律：
 *   1. 令牌只存在于服务端：缓存里是 accessCipher / refreshCipher，浏览器只有 sid；
 *   2. 空闲 30 分钟、绝对 8 小时，滑动刷新但不突破绝对上限（前端不可延长）；
 *   3. 登录成功即换新 sid —— 会话固定防护，旧 sid 立即删除。
 *
 * 存储键与文档一致：sess:{sid}、auth:csrf:{sid}。
 */
class WorkshopSession
{
    /** 会话数据键（§4.3.2） */
    private const SESSION_PREFIX = 'sess:';

    /** CSRF 双提交令牌键（§4.3.2） */
    private const CSRF_PREFIX = 'auth:csrf:';

    /** 授权 state 键（§4.3.2） */
    private const STATE_PREFIX = 'auth:state:';

    /** state 有效期：10 分钟（§4.4.2 第 2 步） */
    private const STATE_TTL = 600;

    /**
     * Cookie 名称
     *
     * HTTPS 且有配置 __Host- 前缀时使用规范名；本地 HTTP 调试降级为无前缀名，
     * 否则浏览器会直接丢弃该 Cookie，表现为"登录成功但立刻又要求登录"。
     *
     * @return string
     */
    public static function cookieName(): string
    {
        $name = (string) config('platform.session.cookie', 'workshop_sid');
        if (self::secure() && strpos($name, '__Host-') !== 0 && $name === 'workshop_sid') {
            return '__Host-workshop_sid';
        }
        return $name;
    }

    /**
     * 是否为 HTTPS 部署（决定 __Host- 前缀与 Secure 属性）
     *
     * @return bool
     */
    public static function secure(): bool
    {
        $value = config('platform.session.secure', false);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * 创建新会话
     *
     * @param array $payload userid / subject / roles / client_id / mode
     * @return string sid
     */
    public static function create(array $payload): string
    {
        $sid = bin2hex(random_bytes(32));
        $now = time();

        $data = array_merge([
            'userid' => 0,
            'subject' => '',
            'roles' => [],
            'client_id' => '',
            'mode' => 'user',
        ], $payload);

        $data['created_at'] = $now;
        $data['last_seen_at'] = $now;

        Cache::put(self::SESSION_PREFIX . $sid, $data, self::idleTtl());
        Cache::put(self::CSRF_PREFIX . $sid, bin2hex(random_bytes(16)), self::absTtl());

        return $sid;
    }

    /**
     * 读取会话（同时滑动刷新空闲时间）
     *
     * @param string $sid
     * @return array|null
     */
    public static function read(string $sid): ?array
    {
        if ($sid === '') {
            return null;
        }

        $data = Cache::get(self::SESSION_PREFIX . $sid);
        if (!is_array($data) || empty($data['created_at'])) {
            return null;
        }

        $now = time();
        if ($now - (int) $data['created_at'] > self::absTtl()) {
            // 绝对上限到点，无论是否活跃都必须失效
            self::destroy($sid);
            return null;
        }

        $data['last_seen_at'] = $now;
        $remaining = (int) $data['created_at'] + self::absTtl() - $now;
        Cache::put(self::SESSION_PREFIX . $sid, $data, max(1, min(self::idleTtl(), $remaining)));

        return $data;
    }

    /**
     * 更新会话数据（不改变 sid 与创建时间）
     *
     * @param string $sid
     * @param array $payload
     * @return void
     */
    public static function update(string $sid, array $payload): void
    {
        $data = self::read($sid);
        if (!$data) {
            return;
        }
        $data = array_merge($data, $payload);
        $remaining = (int) $data['created_at'] + self::absTtl() - time();
        Cache::put(self::SESSION_PREFIX . $sid, $data, max(1, min(self::idleTtl(), $remaining)));
    }

    /**
     * 销毁会话
     *
     * @param string $sid
     * @return void
     */
    public static function destroy(string $sid): void
    {
        if ($sid === '') {
            return;
        }
        Cache::forget(self::SESSION_PREFIX . $sid);
        Cache::forget(self::CSRF_PREFIX . $sid);
    }

    /**
     * 取会话绑定的 CSRF 令牌（不存在则补发）
     *
     * @param string $sid
     * @return string
     */
    public static function csrfToken(string $sid): string
    {
        $key = self::CSRF_PREFIX . $sid;
        $token = Cache::get($key);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            Cache::put($key, $token, self::absTtl());
        }
        return $token;
    }

    /**
     * 校验 CSRF 双提交令牌（§4.5：非 GET 请求必校验）
     *
     * @param string $sid
     * @param string|null $token
     * @return bool
     */
    public static function verifyCsrf(string $sid, ?string $token): bool
    {
        if ($sid === '' || $token === null || $token === '') {
            return false;
        }
        $expected = Cache::get(self::CSRF_PREFIX . $sid);
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /**
     * 写入授权 state（OAuth 防伪）
     *
     * @param array $payload redirect / verifier / created_at
     * @return string
     */
    public static function putState(array $payload): string
    {
        $state = bin2hex(random_bytes(32));
        $payload['created_at'] = time();
        Cache::put(self::STATE_PREFIX . $state, $payload, self::STATE_TTL);
        return $state;
    }

    /**
     * 取出并删除 state（一次性）
     *
     * @param string $state
     * @return array|null
     */
    public static function pullState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }
        $key = self::STATE_PREFIX . $state;
        $data = Cache::get($key);
        Cache::forget($key);
        return is_array($data) ? $data : null;
    }

    /**
     * 从请求解析会话
     *
     * @param \Illuminate\Http\Request $request
     * @return array{sid:string,data:array|null}
     */
    public static function resolve($request): array
    {
        $sid = (string) $request->cookie(self::cookieName(), '');
        if ($sid === '') {
            return ['sid' => '', 'data' => null];
        }
        $data = self::read($sid);
        return ['sid' => $sid, 'data' => $data];
    }

    /**
     * 下发会话 Cookie
     *
     * @param Response $response
     * @param string $sid
     * @return Response
     */
    public static function attachCookie(Response $response, string $sid): Response
    {
        $response->headers->setCookie(new Cookie(
            self::cookieName(),
            $sid,
            time() + self::absTtl(),
            '/',
            null,
            self::secure(),
            true,
            false,
            (string) config('platform.session.same_site', 'Lax')
        ));
        return $response;
    }

    /**
     * 清除会话 Cookie
     *
     * @param Response $response
     * @return Response
     */
    public static function clearCookie(Response $response): Response
    {
        $response->headers->setCookie(new Cookie(
            self::cookieName(),
            '',
            time() - 3600,
            '/',
            null,
            self::secure(),
            true,
            false,
            (string) config('platform.session.same_site', 'Lax')
        ));
        return $response;
    }

    /**
     * 空闲超时（秒）
     *
     * @return int
     */
    public static function idleTtl(): int
    {
        return max(60, (int) config('platform.session.idle_ttl', 1800));
    }

    /**
     * 绝对上限（秒）
     *
     * @return int
     */
    public static function absTtl(): int
    {
        return max(self::idleTtl(), (int) config('platform.session.abs_ttl', 28800));
    }
}
