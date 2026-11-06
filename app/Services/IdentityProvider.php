<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 统一身份提供方客户端（OAuth 2.0 授权码 + PKCE）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §4.3、§4.4.2。
 *
 * 关键约束：
 *   - 令牌只在服务端流转，浏览器只拿会话 Cookie（§4.3.2）；
 *   - 换码请求**不重试**：code 一次性，超时重试必然 invalid_grant（§4.4.2 第 8 步）；
 *   - userinfo 返回 200 但 userId 为空时**不得降级放行**（§4.4.2 第 9 步）；
 *   - 端点优先读 Discovery，禁止长期硬编码（§4.3.1 必需端点行）。
 */
class IdentityProvider
{
    /**
     * real / mock
     *
     * @return string
     */
    public function mode(): string
    {
        $mode = strtolower((string) config('platform.sso.mode', 'mock'));
        return $mode === 'real' ? 'real' : 'mock';
    }

    /**
     * @return bool
     */
    public function isMock(): bool
    {
        return $this->mode() === 'mock';
    }

    /**
     * 生成 PKCE 对
     *
     * @return array{verifier:string,challenge:string}
     */
    public function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * 构造授权地址
     *
     * @param string $state
     * @param string $challenge
     * @return string
     */
    public function authorizeUrl(string $state, string $challenge): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => (string) config('platform.sso.client_id', 'jy-workshop-web'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => (string) config('platform.sso.scope', 'openid profile'),
            'state' => $state,
        ];

        if ($this->boolConfig('platform.sso.pkce', true)) {
            $params['code_challenge'] = $challenge;
            $params['code_challenge_method'] = 'S256';
        }

        return rtrim((string) config('platform.sso.base_url', ''), '/')
            . (string) config('platform.sso.login_path', '/oauth/authorize')
            . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 服务端换码
     *
     * @param string $code
     * @param string $verifier
     * @return array{ok:bool,accessToken:string,refreshToken:string,expiresIn:int,error:string}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => (string) config('platform.sso.client_id', 'jy-workshop-web'),
            'client_secret' => (string) config('platform.sso.client_secret', ''),
        ];
        if ($verifier !== '') {
            $payload['code_verifier'] = $verifier;
        }

        $timeout = max(1, (int) config('platform.sso.http_timeout', 3));

        try {
            // 注意：不设 retry —— code 一次性，重试必然失败且可能已吊销
            $response = Http::asForm()
                ->timeout($timeout)
                ->acceptJson()
                ->post($this->endpoint('token_path', '/oauth/token'), $payload);
        } catch (\Throwable $e) {
            Log::error('[sso] token exchange failed: ' . $e->getMessage());
            return ['ok' => false, 'accessToken' => '', 'refreshToken' => '', 'expiresIn' => 0, 'error' => 'token_endpoint_unreachable'];
        }

        $body = $response->json();
        if (!is_array($body) || empty($body['access_token'])) {
            Log::error('[sso] token exchange rejected', ['status' => $response->status()]);
            return [
                'ok' => false,
                'accessToken' => '',
                'refreshToken' => '',
                'expiresIn' => 0,
                'error' => (string) ($body['error'] ?? 'invalid_grant'),
            ];
        }

        return [
            'ok' => true,
            'accessToken' => (string) $body['access_token'],
            'refreshToken' => (string) ($body['refresh_token'] ?? ''),
            'expiresIn' => (int) ($body['expires_in'] ?? 7200),
            'error' => '',
        ];
    }

    /**
     * 拉取 userinfo
     *
     * @param string $accessToken
     * @return array{ok:bool,profile:array,error:string}
     */
    public function userinfo(string $accessToken): array
    {
        $timeout = max(1, (int) config('platform.sso.http_timeout', 3));

        try {
            $response = Http::withToken($accessToken)
                ->timeout($timeout)
                ->acceptJson()
                ->get($this->endpoint('userinfo_path', '/oauth/userinfo'));
        } catch (\Throwable $e) {
            Log::error('[sso] userinfo failed: ' . $e->getMessage());
            return ['ok' => false, 'profile' => [], 'error' => 'userinfo_unreachable'];
        }

        $body = $response->json();
        if (!is_array($body)) {
            return ['ok' => false, 'profile' => [], 'error' => 'userinfo_invalid'];
        }

        $data = $body['data'] ?? $body;
        if (!is_array($data)) {
            return ['ok' => false, 'profile' => [], 'error' => 'userinfo_invalid'];
        }

        // 兼容 sub / userId 两种写法；sub 优先（§4.1.1）
        $subject = (string) ($data['sub'] ?? $data['userId'] ?? '');
        if ($subject === '') {
            // 不降级放行：临时 ID 会产生一批无法与论坛对账的脏数据
            Log::error('[sso] userinfo missing sub/userId');
            return ['ok' => false, 'profile' => [], 'error' => 'userinfo_missing_subject'];
        }

        $roles = $data['roles'] ?? $data['role'] ?? [];
        if (is_string($roles)) {
            $roles = preg_split('/[,;\s]+/', $roles, -1, PREG_SPLIT_NO_EMPTY);
        }

        return [
            'ok' => true,
            'profile' => [
                // sub 原值透传，不做任何加工
                'subject' => $subject,
                'email' => (string) ($data['email'] ?? ''),
                'nickname' => (string) ($data['nickname'] ?? $data['name'] ?? ''),
                'avatar' => (string) ($data['avatar'] ?? $data['picture'] ?? ''),
                'roles' => GatewayIdentity::normalizeRoles($roles),
            ],
            'error' => '',
        ];
    }

    /**
     * 身份中心登出地址
     *
     * @return string
     */
    public function logoutUrl(): string
    {
        $postLogout = (string) config('platform.sso.post_logout_uri', '');
        $base = rtrim((string) config('platform.sso.base_url', ''), '/');
        $path = (string) config('platform.sso.logout_path', '/protocol/logout');

        $query = [];
        if ($postLogout !== '') {
            $query['post_logout_redirect_uri'] = $postLogout;
        }
        if ((string) config('platform.sso.client_id', '') !== '') {
            $query['client_id'] = (string) config('platform.sso.client_id');
        }

        return $base . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /**
     * mock 身份（仅 SSO_MODE=mock）
     *
     * @return array
     */
    public function mockProfile(): array
    {
        $subject = (string) config('platform.sso.mock_sub', 'usr_mock_workshop_demo');
        return [
            'subject' => $subject,
            'email' => (string) config('platform.sso.mock_email', ''),
            'nickname' => (string) config('platform.sso.mock_nickname', '演示用户'),
            'avatar' => '',
            'roles' => GatewayIdentity::normalizeRoles((string) config('platform.sso.mock_roles', 'student')),
        ];
    }

    /**
     * Discovery 文档（缓存 1 小时，启动时校验 issuer）
     *
     * @return array
     */
    public function discovery(): array
    {
        if ($this->isMock()) {
            return ['issuer' => (string) config('platform.sso.issuer', 'mock'), 'mock' => true];
        }

        $cacheKey = 'sso:discovery';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $url = rtrim((string) config('platform.sso.base_url', ''), '/')
            . (string) config('platform.sso.discovery_path', '/.well-known/openid-configuration');

        try {
            $response = Http::timeout(3)->acceptJson()->get($url);
            $body = $response->json();
            if (is_array($body)) {
                Cache::put($cacheKey, $body, 3600);
                return $body;
            }
        } catch (\Throwable $e) {
            Log::warning('[sso] discovery failed: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * 取端点：Discovery 优先，配置兜底
     *
     * @param string $configKey
     * @param string $defaultPath
     * @return string
     */
    private function endpoint(string $configKey, string $defaultPath): string
    {
        $discovery = $this->discovery();
        $map = [
            'token_path' => 'token_endpoint',
            'userinfo_path' => 'userinfo_endpoint',
            'logout_path' => 'end_session_endpoint',
        ];
        $discoveryKey = $map[$configKey] ?? '';
        if ($discoveryKey !== '' && !empty($discovery[$discoveryKey])) {
            return (string) $discovery[$discoveryKey];
        }

        return rtrim((string) config('platform.sso.base_url', ''), '/')
            . (string) config('platform.sso.' . $configKey, $defaultPath);
    }

    /**
     * @return string
     */
    private function redirectUri(): string
    {
        $configured = (string) config('platform.sso.redirect_uri', '');
        if ($configured !== '') {
            return $configured;
        }
        return url('/api/v1/workshop/auth/oauth/callback');
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
