<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSO 就绪自检命令测试（php artisan sso:probe）
 *
 * 这个命令的价值全在"判定准不准"，因此测试重点不是输出文案，而是三条判据：
 *   1. 被 APISIX Dashboard 兜底页吃掉的路径，HTTP 虽然是 200，也必须判成「未注册」；
 *   2. 五条端点真注册时，必须判成 READY，不能因为"无令牌被 401"这类预期行为误报；
 *   3. 把网关 L2 的 AppSecret 误填成 SSO_CLIENT_SECRET 时，必须在切换前就拦下（B-3）。
 *
 * 所有出站请求用 Http::fake 拦截，不发真实网络请求。
 *
 * 运行：/d/dt-run/php/php.exe vendor/bin/phpunit --filter SsoProbeTest
 */
class SsoProbeTest extends TestCase
{
    /** APISIX Dashboard 兜底页的真实特征（与线上一致，两个特征串都必须命中） */
    private const DASHBOARD_HTML = '<!-- Licensed to the Apache Software Foundation (ASF) under one'
        . ' or more contributor license agreements. The ASF licenses this file to You'
        . ' under the Apache License, Version 2.0 -->'
        . '<html><body><noscript>You need to enable JavaScript to run this app.</noscript></body></html>';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform.sso.mode' => 'real',
            'platform.sso.base_url' => 'https://sso.example.com',
            'platform.sso.client_id' => 'jy-workshop-web',
            'platform.sso.client_secret' => 'oauth-client-secret-abc123',
            'platform.sso.redirect_uri' => 'https://workshop.example.com/api/v1/workshop/auth/oauth/callback',
            'platform.sso.mock_envs' => 'local,testing',
            'platform.gateway.base_url' => 'https://sso.example.com',
            'platform.gateway.internal_secret' => '',
            'platform.forum.app_secret' => '',
            'platform.exam.app_secret' => '',
            'platform.showcase.app_secret' => '',
        ]);
    }

    /**
     * 五条端点都真注册 → READY，退出码 0
     */
    public function testReadyWhenAllEndpointsRegistered(): void
    {
        Http::fake([
            '*/oauth/authorize*' => Http::response('<html><body>授权登录页</body></html>', 200, ['Content-Type' => 'text/html']),
            '*/oauth/token*' => Http::response(['error' => 'invalid_request'], 400),
            '*/oauth/userinfo*' => Http::response(['error' => 'invalid_token'], 401),
            '*/protocol/logout*' => Http::response('', 302),
            '*/.well-known/openid-configuration*' => Http::response([
                'issuer' => 'https://sso.example.com',
                'authorization_endpoint' => 'https://sso.example.com/oauth/authorize',
                'token_endpoint' => 'https://sso.example.com/oauth/token',
            ], 200),
        ]);

        $exit = Artisan::call('sso:probe');
        $output = Artisan::output();

        $this->assertSame(0, $exit, '端点齐全时应判定为 READY');
        $this->assertStringContainsString('READY', $output);
        $this->assertStringNotContainsString('未注册', $output);
    }

    /**
     * 全部返回 Dashboard 兜底页 → 必须判成未注册，退出码 1
     *
     * 这是本项目踩过的坑：HTTP 200 让人误以为端点已上线。
     */
    public function testDashboardFallbackIsTreatedAsUnregistered(): void
    {
        Http::fake([
            '*' => Http::response(self::DASHBOARD_HTML, 200, ['Content-Type' => 'text/html;charset=utf-8']),
        ]);

        $exit = Artisan::call('sso:probe');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('BLOCKED', $output);
        $this->assertStringContainsString('未注册', $output);
        $this->assertStringContainsString('APISIX Dashboard', $output);
    }

    /**
     * 客户端密钥与网关 L2 凭据同值 → 切换前拦下
     */
    public function testClientSecretReusingL2SecretIsBlocked(): void
    {
        config([
            'platform.forum.app_secret' => 'oauth-client-secret-abc123',
        ]);

        Http::fake([
            '*' => Http::response(self::DASHBOARD_HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $exit = Artisan::call('sso:probe');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('同值', $output);
        $this->assertStringContainsString('FORUM_APP_SECRET', $output);
    }

    /**
     * token 端点返回 200 却缺 error 字段 → 不是标准 OAuth 实现，不能判通过
     */
    public function testTokenEndpointWithoutStandardErrorIsNotReady(): void
    {
        Http::fake([
            '*/oauth/token*' => Http::response(['ok' => true], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $exit = Artisan::call('sso:probe');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('缺少 error 字段', $output);
    }

    /**
     * --json 必须输出可解析的结论，供 CI 判断
     */
    public function testJsonOutputIsParsable(): void
    {
        Http::fake([
            '*' => Http::response(self::DASHBOARD_HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        Artisan::call('sso:probe', ['--json' => true]);
        $output = trim(Artisan::output());

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'JSON 模式输出必须可直接解析');
        $this->assertFalse($decoded['ready']);
        $this->assertContains('authorize', $decoded['blockers']);
    }

    /**
     * 生产环境把 production 写进 mock_envs → 必须阻塞（免密后门）
     */
    public function testProductionInMockEnvsIsBlocked(): void
    {
        config([
            'platform.sso.mock_envs' => 'local,testing,production',
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'invalid_request'], 400),
        ]);

        Artisan::call('sso:probe');
        $output = Artisan::output();

        $this->assertStringContainsString('免密登录后门', $output);
    }
}
