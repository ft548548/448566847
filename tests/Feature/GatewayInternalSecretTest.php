<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Services\GatewaySigner;
use Tests\TestCase;

/**
 * 入站内部签名密钥的读取、校验与异常分支（本平台作为网关上游）
 *
 * 覆盖《实训平台_统一身份与网关接入说明》V3.0 §5.5 与《统一 API 网关部署报告》§2.2：
 *   - 密钥按平台独立，只认 `GATEWAY_INTERNAL_SECRET`（或 `_FILE` 指向的文件）
 *   - 验签通过才信任 `X-User-Id` 等七头；签名错、被篡改一律 403
 *   - 配置错误（带空格 / 被换行截断）与签名错误必须分开报，否则排查方向会跑偏
 *   - 非本地环境不允许"空密钥 + allow"降级
 *
 * 测试用的是假密钥，不是生产密钥——仓库里不存放任何真实凭据。
 *
 * 运行：PHP_BIN=D:/dt-run/php/php.exe npm run test -- --filter GatewayInternalSecretTest
 */
class GatewayInternalSecretTest extends TestCase
{
    /** 假密钥：64 位 hex，形态与网关下发的一致 */
    private const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const INTERNAL_PROJECT = '/api/v1/workshop/internal/projects/21';

    private const HEALTH = '/api/v1/workshop/health';

    /** @var string 文件注入用例的临时文件 */
    private $secretFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config([
            'platform.gateway.verify_sign' => true,
            'platform.gateway.internal_secret' => self::SECRET,
            'platform.gateway.internal_secret_file' => '',
            'platform.gateway.direct_access' => 'reject',
            'platform.gateway.trusted_cidr' => '',
            'platform.gateway.skew' => 300,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->secretFile !== '' && is_file($this->secretFile)) {
            @unlink($this->secretFile);
        }
        $this->secretFile = '';
        parent::tearDown();
    }

    // —————————————————————————— 正常通路 ——————————————————————————

    public function test_signed_request_reaches_business_layer(): void
    {
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders());

        $response->assertStatus(200);
        $this->assertSame('OK', $response->json('code'));
    }

    public function test_valid_signature_is_trusted_even_in_user_mode(): void
    {
        // 验签通过后会继续判 X-Auth-Mode；报 USER_MODE_REJECTED 说明 trusted 已置真，
        // 若验签没通过，这里会先被 SERVICE_ONLY 拦下——两者是不同的失败点
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders(['X-Auth-Mode' => 'user']));

        $response->assertStatus(403);
        $this->assertSame('USER_MODE_REJECTED', $response->json('data.reason'));
    }

    // —————————————————————————— 签名不通过 ——————————————————————————

    public function test_wrong_secret_is_rejected_as_sign_mismatch(): void
    {
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders([], str_repeat('0', 64)));

        $response->assertStatus(403);
        $this->assertSame('SIGN_MISMATCH', $response->json('data.reason'));
    }

    public function test_tampered_identity_header_is_rejected(): void
    {
        $headers = $this->signedHeaders();
        $headers['X-User-Role'] = 'admin';

        $response = $this->getJson(self::INTERNAL_PROJECT, $headers);

        $response->assertStatus(403);
        $this->assertSame('SIGN_MISMATCH', $response->json('data.reason'));
    }

    public function test_expired_timestamp_is_rejected_as_clock_skew(): void
    {
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders([], self::SECRET, time() - 3600));

        $response->assertStatus(403);
        $this->assertSame('CLOCK_SKEW', $response->json('data.reason'));
        $this->assertGreaterThan(0, (int) $response->json('data.skewSeconds'));
    }

    public function test_missing_signature_is_not_trusted(): void
    {
        // direct_access=reject：无签名在信任层就被拦下，到不了业务层，
        // X-User-Id 更不会被当成身份
        $response = $this->getJson(self::INTERNAL_PROJECT, [
            'X-User-Id' => 'svc_fake',
            'X-Auth-Mode' => 'service',
        ]);

        $response->assertStatus(403);
        $this->assertSame('SIGN_MISSING', $response->json('data.reason'));
    }

    // —————————————————————————— 配置形态 ——————————————————————————

    public function test_malformed_secret_is_reported_separately(): void
    {
        // 典型事故：复制时带进空格，签名永远对不上，却报成 SIGN_MISMATCH
        config(['platform.gateway.internal_secret' => 'abc def ghi jkl mno pqr stu vwx yz0']);

        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders());

        $response->assertStatus(403);
        $this->assertSame('SECRET_MALFORMED', $response->json('data.reason'));
    }

    public function test_empty_secret_is_rejected_outside_local_environment(): void
    {
        config([
            'platform.gateway.internal_secret' => '',
            'platform.gateway.direct_access' => 'allow',
        ]);

        // 临时切到 production：local/testing 之外的 "空密钥 + allow" 必须拒绝
        $original = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders());
        } finally {
            $this->app['env'] = $original;
        }

        $response->assertStatus(403);
        $this->assertSame('SECRET_MISSING', $response->json('data.reason'));
    }

    public function test_empty_secret_allows_anonymous_in_local_environment(): void
    {
        config([
            'platform.gateway.internal_secret' => '',
            'platform.gateway.direct_access' => 'allow',
        ]);

        // 本地联调：放行但不信任身份头，因此 internal 仍被 SERVICE_ONLY 拒绝
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders());

        $response->assertStatus(403);
        $this->assertSame('SERVICE_ONLY', $response->json('data.reason'));
    }

    // —————————————————————————— 读取来源 ——————————————————————————

    public function test_secret_can_be_loaded_from_file(): void
    {
        $this->secretFile = tempnam(sys_get_temp_dir(), 'gwsecret');
        // 末尾带换行：模拟 Secret 挂载的常见形态，读取后必须被 trim
        file_put_contents($this->secretFile, self::SECRET . "\n");

        config([
            'platform.gateway.internal_secret' => '',
            'platform.gateway.internal_secret_file' => $this->secretFile,
        ]);

        $this->assertSame(self::SECRET, GatewaySigner::internalSecret());

        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders());
        $response->assertStatus(200);
    }

    public function test_direct_value_wins_over_file(): void
    {
        $this->secretFile = tempnam(sys_get_temp_dir(), 'gwsecret');
        file_put_contents($this->secretFile, str_repeat('f', 64));

        config(['platform.gateway.internal_secret_file' => $this->secretFile]);

        $this->assertSame(self::SECRET, GatewaySigner::internalSecret());
    }

    public function test_missing_file_falls_back_to_empty_without_throwing(): void
    {
        config([
            'platform.gateway.internal_secret' => '',
            'platform.gateway.internal_secret_file' => sys_get_temp_dir() . '/not-exist-' . bin2hex(random_bytes(4)),
        ]);

        // 读不到文件不能抛异常，否则整个 v1 组都会 500
        $this->assertSame('', GatewaySigner::internalSecret());
    }

    // —————————————————————————— 可观测性 ——————————————————————————

    public function test_fingerprint_is_stable_and_does_not_leak_secret(): void
    {
        $fingerprint = GatewaySigner::secretFingerprint(self::SECRET);

        $this->assertSame(12, strlen($fingerprint));
        $this->assertSame($fingerprint, GatewaySigner::secretFingerprint(self::SECRET));
        $this->assertNotSame($fingerprint, GatewaySigner::secretFingerprint(str_repeat('b', 64)));
        $this->assertStringNotContainsString(self::SECRET, $fingerprint);
        $this->assertSame('', GatewaySigner::secretFingerprint(''));
    }

    public function test_health_reports_source_and_validity_without_secret(): void
    {
        $response = $this->getJson(self::HEALTH);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.gateway.internalSecretSet'));
        $this->assertTrue($response->json('data.gateway.internalSecretValid'));
        $this->assertSame('env', $response->json('data.gateway.internalSecretSource'));

        // 公开路径，绝不能回显密钥或指纹
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString(GatewaySigner::secretFingerprint(self::SECRET), $body);
    }

    // —————————————————————————— 辅助 ——————————————————————————

    /**
     * @param array $overrides 覆盖默认头（改完会重新签名）
     * @param string $secret 签名密钥
     * @param int|null $timestamp 时间戳
     * @return array
     */
    private function signedHeaders(array $overrides = [], string $secret = self::SECRET, ?int $timestamp = null): array
    {
        $headers = array_merge([
            'X-User-Id' => 'svc_test_gateway',
            'X-User-Role' => 'service',
            'X-Client-Id' => 'jy-workshop-svc',
            'X-Token-Exp' => (string) (time() + 600),
            'X-Request-Id' => 'trace-' . bin2hex(random_bytes(4)),
            'X-Auth-Mode' => 'service',
            'X-Timestamp' => (string) ($timestamp ?? time()),
        ], $overrides);

        $headers['X-Internal-Sign'] = GatewaySigner::trustSign($headers, $secret);

        return $headers;
    }
}
