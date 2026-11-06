<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Services\WorkshopSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OAuth 授权回调加固测试（api/v1/workshop/auth/oauth/callback）
 *
 * 覆盖本轮改造的四个点：
 *   1. 身份中心回传 error（用户取消授权）必须被识别，不能被误报成「缺少授权码」；
 *   2. 换码失败要区分「够不着」(503) 与「被拒绝」(401)，否则运维会朝错误方向排查；
 *   3. 浏览器拿页面、脚本拿 JSON —— 同一套错误，两种呈现，互不破坏；
 *   4. mock 只能在本机/测试环境生效，生产漏配 SSO_* 时必须 fail closed。
 *
 * 所有出站请求都用 Http::fake 拦截，测试过程不发真实网络请求。
 *
 * 运行：/d/dt-run/php/php.exe vendor/bin/phpunit --filter OAuthCallbackTest
 */
class OAuthCallbackTest extends TestCase
{
    private const CALLBACK = '/api/v1/workshop/auth/oauth/callback';
    private const LOGIN = '/api/v1/workshop/auth/login';
    private const FAILED_PAGE = '/api/v1/workshop/auth/login-failed';

    /** 浏览器默认 Accept */
    private const HTML = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    /** 脚本 / curl 默认 Accept（不带 text/html，因此仍应拿到 JSON） */
    private const ANY = '*/*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config([
            'platform.sso.mode' => 'mock',
            'platform.sso.issuer' => 'jingyuan-forum',
            'platform.sso.base_url' => '',
            'platform.sso.client_id' => 'jy-workshop-web',
            'platform.sso.client_secret' => '',
            'platform.sso.mock_envs' => 'local,testing',
        ]);

        // 只清 array 驱动；redis 下 flush 会清掉真实缓存
        if (config('cache.default') === 'array') {
            Cache::flush();
        }
    }

    protected function tearDown(): void
    {
        if (config('cache.default') === 'array') {
            Cache::flush();
        }
        parent::tearDown();
    }

    /** 造一个有效的 state（模拟 login 已经下发过） */
    private function state(array $extra = []): string
    {
        return WorkshopSession::putState(array_merge([
            'redirect' => '/',
            'verifier' => 'test-verifier',
        ], $extra));
    }

    // ------------------------------------------------------------------
    // 1. 取消授权（error 参数）
    // ------------------------------------------------------------------

    public function test_user_cancelled_authorization_is_not_reported_as_missing_code(): void
    {
        $state = $this->state();

        // 关键回归点：改造前这条会走到「缺少授权码 code」，
        // 把用户主动取消说成技术故障。
        $response = $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?error=access_denied&state=' . $state);

        $response->assertStatus(400);
        $this->assertSame('ACCESS_DENIED', $response->json('data.reason'));
        $this->assertNotSame('CODE_MISSING', $response->json('data.reason'));
    }

    public function test_cancelled_authorization_redirects_browser_to_error_page(): void
    {
        $state = $this->state();

        $response = $this->withHeaders(['Accept' => self::HTML])
            ->get(self::CALLBACK . '?error=access_denied&state=' . $state);

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString(self::FAILED_PAGE, $location);
        $this->assertStringContainsString('reason=ACCESS_DENIED', $location);
    }

    public function test_cancelled_authorization_consumes_state_to_block_replay(): void
    {
        $state = $this->state();

        $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?error=access_denied&state=' . $state)
            ->assertStatus(400);

        // state 必须已被消费：第二次再拿同一个 state 走正常流程应判为失效
        $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?code=abc&state=' . $state)
            ->assertStatus(400)
            ->assertJsonPath('data.reason', 'STATE_INVALID');
    }

    public function test_non_cancel_error_maps_to_authorize_rejected(): void
    {
        $state = $this->state();

        $response = $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?error=invalid_client&state=' . $state);

        $response->assertStatus(400);
        $this->assertSame('AUTHORIZE_REJECTED', $response->json('data.reason'));
        // 上游原文只进 JSON 便于排查，绝不反射进跳转 URL
        $this->assertSame('invalid_client', $response->json('data.upstream'));
    }

    // ------------------------------------------------------------------
    // 2. state / code 校验
    // ------------------------------------------------------------------

    public function test_unknown_state_returns_json_for_scripts(): void
    {
        $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?code=abc&state=not-a-real-state')
            ->assertStatus(400)
            ->assertJsonPath('data.reason', 'STATE_INVALID');
    }

    public function test_unknown_state_redirects_browser_instead_of_dumping_json(): void
    {
        $response = $this->withHeaders(['Accept' => self::HTML])
            ->get(self::CALLBACK . '?code=abc&state=not-a-real-state');

        $response->assertStatus(302);
        $this->assertStringContainsString('reason=STATE_INVALID', (string) $response->headers->get('Location'));
    }

    public function test_valid_state_without_code_reports_code_missing(): void
    {
        $state = $this->state();

        $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?state=' . $state)
            ->assertStatus(400)
            ->assertJsonPath('data.reason', 'CODE_MISSING');
    }

    // ------------------------------------------------------------------
    // 3. 换码失败：503 与 401 必须分开
    // ------------------------------------------------------------------

    /** 切到 real 模式，让代码真正走换码分支 */
    private function realMode(): void
    {
        config([
            'platform.sso.mode' => 'real',
            'platform.sso.base_url' => 'http://idp.test',
            'platform.sso.client_id' => 'jy-workshop-web',
            'platform.sso.client_secret' => 'test-client-secret',
            'platform.sso.token_path' => '/oauth/token',
            'platform.sso.userinfo_path' => '/oauth/userinfo',
            'platform.sso.discovery_path' => '/.well-known/openid-configuration',
            'platform.sso.http_timeout' => 2,
        ]);
        // Discovery 未命中时才能落到配置里的相对路径，这里让它直接失败
        Http::fake([
            'http://idp.test/.well-known/openid-configuration' => Http::response([], 404),
        ]);
    }

    public function test_unreachable_token_endpoint_is_503_not_401(): void
    {
        $this->realMode();
        Http::fake(function () {
            throw new ConnectionException('connect timeout');
        });

        $state = $this->state();
        $response = $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?code=abc&state=' . $state);

        $response->assertStatus(503);
        $this->assertSame('TOKEN_ENDPOINT_UNREACHABLE', $response->json('data.reason'));
    }

    public function test_rejected_code_is_401_and_keeps_upstream_error(): void
    {
        $this->realMode();
        Http::fake([
            'http://idp.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $state = $this->state();
        $response = $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?code=abc&state=' . $state);

        $response->assertStatus(401);
        $this->assertSame('TOKEN_EXCHANGE_FAILED', $response->json('data.reason'));
        $this->assertSame('invalid_client', $response->json('data.error'));
    }

    public function test_token_endpoint_5xx_is_treated_as_unavailable(): void
    {
        $this->realMode();
        Http::fake([
            'http://idp.test/oauth/token' => Http::response('upstream boom', 502),
        ]);

        $state = $this->state();
        $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?code=abc&state=' . $state)
            ->assertStatus(503)
            ->assertJsonPath('data.reason', 'TOKEN_ENDPOINT_UNREACHABLE');
    }

    // ------------------------------------------------------------------
    // 4. mock 环境限制 + 不降级放行
    // ------------------------------------------------------------------

    public function test_mock_login_is_rejected_when_environment_not_allowed(): void
    {
        // 模拟"生产漏配 SSO_*"，此时 SSO_MODE 落到默认 mock
        config(['platform.sso.mock_envs' => 'production']);

        $response = $this->withHeaders(['Accept' => self::ANY])->getJson(self::LOGIN);

        $response->assertStatus(503);
        $this->assertSame('SSO_NOT_CONFIGURED', $response->json('data.reason'));
    }

    public function test_mock_login_still_works_in_testing_environment(): void
    {
        config(['platform.sso.mock_envs' => 'local,testing']);

        $response = $this->get(self::LOGIN);

        $response->assertStatus(302);
        $this->assertStringContainsString('code=mock_', (string) $response->headers->get('Location'));
    }

    public function test_mock_numeric_subject_never_produces_a_session_when_blocked(): void
    {
        config(['platform.sso.mock_envs' => 'production']);

        $state = $this->state();
        $response = $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?code=mock_abc&state=' . $state);

        $response->assertStatus(503);
        $this->assertSame('SSO_NOT_CONFIGURED', $response->json('data.reason'));
        // 关键：被拒绝时绝不能下发会话 Cookie。
        // 注意只断言我们的 workshop_sid —— 响应里那条 `_session` 是 Laravel
        // 自身会话（web 中间件组固有），与登录态无关，不能混为一谈。
        $this->assertStringNotContainsString(
            WorkshopSession::cookieName() . '=',
            (string) $response->headers->get('Set-Cookie'),
            'mock 被拒时不得下发会话 Cookie'
        );
    }

    public function test_non_numeric_mock_subject_fails_closed_without_session(): void
    {
        // mock 允许的环境下，演示身份是 usr_mock_workshop_demo（非十进制）
        // 必须按 uid 契约 R1/R2 拒绝建号，而不是造一个替代标识
        config(['platform.sso.mock_envs' => 'local,testing']);

        $state = $this->state();
        $response = $this->withHeaders(['Accept' => self::ANY])
            ->getJson(self::CALLBACK . '?code=mock_abc&state=' . $state);

        $response->assertStatus(400);
        $this->assertSame('BIND_FAILED', $response->json('data.reason'));
        $this->assertStringNotContainsString(
            WorkshopSession::cookieName() . '=',
            (string) $response->headers->get('Set-Cookie'),
            '建号失败时不得下发会话 Cookie'
        );
    }

    // ------------------------------------------------------------------
    // 5. 错误落地页
    // ------------------------------------------------------------------

    public function test_error_page_renders_known_reason(): void
    {
        $response = $this->get(self::FAILED_PAGE . '?reason=ACCESS_DENIED');

        $response->assertStatus(400);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('ACCESS_DENIED', $response->getContent());
        $this->assertStringContainsString('重新登录', $response->getContent());
    }

    public function test_error_page_uses_503_for_service_down_reasons(): void
    {
        $this->get(self::FAILED_PAGE . '?reason=TOKEN_ENDPOINT_UNREACHABLE')->assertStatus(503);
    }

    public function test_error_page_does_not_reflect_arbitrary_input(): void
    {
        $payload = '<script>alert(1)</script>';

        $response = $this->get(self::FAILED_PAGE . '?reason=' . urlencode($payload));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body, '错误页不得回显请求参数');
        $this->assertStringContainsString('UNKNOWN', $body);
    }

    // ------------------------------------------------------------------
    // 6. health 暴露配置状态（不泄露密钥）
    // ------------------------------------------------------------------

    public function test_health_reports_sso_configuration_state_without_leaking_secret(): void
    {
        config([
            'platform.sso.client_id' => 'jy-workshop-web',
            'platform.sso.client_secret' => 'super-secret-value',
            'platform.sso.base_url' => 'http://idp.test',
        ]);

        $response = $this->get('/api/v1/workshop/health');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.capabilities.ssoConfigured'));
        $this->assertStringNotContainsString('super-secret-value', (string) $response->getContent());
    }

    public function test_health_reports_mock_not_allowed_in_production(): void
    {
        config(['platform.sso.mock_envs' => 'production']);

        $response = $this->get('/api/v1/workshop/health');

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.capabilities.ssoMockAllowed'));
    }
}
