<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\PlatformEventOutbox;
use App\Models\User;
use App\Models\UserIdentityBinding;
use App\Services\GatewaySigner;
use Tests\TestCase;

/**
 * 网关信任层与 v1 接口测试（/api/v1/workshop/*）
 *
 * 覆盖《实训平台_统一身份与网关接入说明》V3.0：
 *   §5.4  公开路径白名单（精确匹配）
 *   §5.5  L1 可信头验签（缺签/错签/过期/未验签不认 X-User-Id）
 *   §5.3.2 internal 只接受 service 模式
 *   §6.7  Idempotency-Key 必需、回放、追踪号不复用
 *   §6.8  CORS 回显具体来源
 *
 * 测试用固定的 admin 账号做身份落点，临时绑定行在 tearDown 按 subject 删除，
 * 不依赖也不污染既有数据。
 *
 * 运行：PHP_BIN=D:/dt-run/php/php.exe npm run test -- --filter WorkshopGatewayTest
 */
class WorkshopGatewayTest extends TestCase
{
    /** 测试用网关内部密钥（只存在于测试进程配置中） */
    private const SECRET = 'test-gateway-secret-0001';

    /** 测试用论坛 sub：故意带下划线与大小写，验证"原值存储、不做加工" */
    private const SUBJECT = 'usr_Test_Gateway_0001';

    /**
     * 已存在的项目（种子数据，project 21 属预置账号 uid=5）
     *
     * 原锚定 project 12，但账号表按论坛 uid 重建后该行已不存在，
     * 400 之前的断言会先撞 PROJECT_NOT_FOUND，与身份逻辑无关。
     */
    private const PROJECT_ID = 21;

    private const HEALTH = '/api/v1/workshop/health';
    private const USERINFO = '/api/v1/workshop/auth/userinfo';
    private const INTERNAL_PROJECT = '/api/v1/workshop/internal/projects/' . self::PROJECT_ID;
    private const INTERNAL_PUBLISH = '/api/v1/workshop/internal/projects/' . self::PROJECT_ID . '/publish';

    /** 用例开始前 outbox 的最大 id，用于只清理本次新增的行 */
    private int $outboxFloor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->resetAuthCache();

        config([
            'platform.gateway.verify_sign' => true,
            'platform.gateway.internal_secret' => self::SECRET,
            'platform.gateway.direct_access' => 'reject',
            'platform.gateway.trusted_cidr' => '',
            'platform.gateway.cors_origins' => '',
            'platform.gateway.skew' => 300,
            'platform.idempotency.enabled' => true,
        ]);

        $this->outboxFloor = (int) PlatformEventOutbox::max('id');
    }

    protected function tearDown(): void
    {
        UserIdentityBinding::whereSubject(self::SUBJECT)->delete();

        // 只清本次新增的发件箱行（按 id 水位）；表原本为空时 floor=0，即清空本次全部新增
        PlatformEventOutbox::where('id', '>', $this->outboxFloor)->delete();

        parent::tearDown();
    }

    /**
     * User::authInfo() 会把登录用户缓存在全局 $_A['__static_auth'] 里，
     * 单进程跑全部用例时前一个用例的身份会污染后一个用例。
     */
    private function resetAuthCache(): void
    {
        if (isset($GLOBALS['_A'])) {
            unset($GLOBALS['_A']['__static_auth']);
        }
    }

    /**
     * 构造一组"网关注入"的请求头并按 §5.5.1 签名
     *
     * @param array $overrides 覆盖默认头（改完会重新签名）
     * @param string $secret 签名用密钥，默认正确密钥
     * @param int|null $timestamp 时间戳，默认当前时间
     * @return array
     */
    private function signedHeaders(array $overrides = [], string $secret = self::SECRET, ?int $timestamp = null): array
    {
        $headers = array_merge([
            'X-User-Id' => '',
            'X-User-Role' => '',
            'X-Client-Id' => 'test-client',
            'X-Token-Exp' => '',
            'X-Request-Id' => 'trace-' . bin2hex(random_bytes(4)),
            'X-Auth-Mode' => 'service',
            'X-Timestamp' => (string) ($timestamp ?? time()),
        ], $overrides);

        $headers['X-Internal-Sign'] = GatewaySigner::trustSign($headers, $secret);

        return $headers;
    }

    // —————————————————————————— 公开路径 ——————————————————————————

    public function test_health_is_public_and_reports_capabilities(): void
    {
        $response = $this->getJson(self::HEALTH);

        $response->assertStatus(200);
        $this->assertSame('OK', $response->json('code'));
        $this->assertSame(1, $response->json('ret'));
        $this->assertContains($response->json('data.status'), ['ok', 'degraded']);

        $capabilities = (array) $response->json('data.capabilities');
        foreach (['ssoMode', 'examMock', 'showcaseMock', 'signVerify', 'cookieName', 'idempotency'] as $key) {
            $this->assertArrayHasKey($key, $capabilities, "健康检查缺能力项 {$key}");
        }

        // 自述里不得出现密钥、连接串
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(self::SECRET, $body, '健康检查泄露了网关密钥');
    }

    public function test_v1_envelope_contains_both_new_and_legacy_fields(): void
    {
        $response = $this->getJson(self::HEALTH, ['X-Request-Id' => 'trace-envelope-01']);

        // v1 信封：code/requestId/message 追加，ret/msg/data 保留
        $response->assertJsonStructure(['ret', 'msg', 'data', 'code', 'requestId', 'message']);
        $this->assertSame('trace-envelope-01', $response->json('requestId'));
        $this->assertSame($response->json('msg'), $response->json('message'), 'message 必须是 msg 的别名');
    }

    // —————————————————————————— 身份 ——————————————————————————

    public function test_userinfo_without_credentials_returns_401(): void
    {
        // 过渡期直连态（direct_access=allow）：请求能进来，但没有任何身份
        config(['platform.gateway.direct_access' => 'allow']);

        $response = $this->getJson(self::USERINFO);

        // v1 用真实 401（与存量 200 的约定不同，见 deviations DV-04）
        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
        $this->assertSame('AUTH_REQUIRED', $response->json('data.reason'));
    }

    public function test_signed_request_without_subject_is_still_anonymous(): void
    {
        // 验签通过但没有 sub：不能"因为签名可信就把请求当成某个用户"
        $response = $this->getJson(self::USERINFO, $this->signedHeaders(['X-User-Id' => '']));

        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    public function test_forged_identity_header_is_ignored_without_signature(): void
    {
        // 允许直连（本地演示态）：请求能进来，但 X-User-Id 绝不被采信
        config(['platform.gateway.direct_access' => 'allow']);

        $response = $this->getJson(self::USERINFO, ['X-User-Id' => self::SUBJECT]);

        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    public function test_non_uid_subject_is_not_trusted(): void
    {
        // X-User-Id 必须是论坛 uid 十进制原值（R1/R2）。
        // 送一个非数字 sub，验证"非 uid 一律不认"：不采信为已登录身份。
        $response = $this->getJson(self::USERINFO, $this->signedHeaders([
            'X-User-Id' => self::SUBJECT,
            'X-User-Role' => 'student,teacher',
            'X-Auth-Mode' => 'user',
        ]));

        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    public function test_trusted_uid_header_maps_to_same_local_userid(): void
    {
        $uid = 5;
        $this->assertNotNull(User::whereUserid($uid)->first(), '预置账号 uid=5 缺失');

        $response = $this->getJson(self::USERINFO, $this->signedHeaders([
            'X-User-Id' => (string) $uid,
            'X-User-Role' => 'student,teacher',
            'X-Auth-Mode' => 'user',
        ]));

        $response->assertStatus(200);
        // 对外身份与本地主键是同一个值，均为论坛 uid 原值
        $this->assertSame((string) $uid, (string) $response->json('data.uid'));
        $this->assertSame((string) $uid, (string) $response->json('data.forum_uid'));
        $this->assertSame('forum', $response->json('data.uid_source'));
        $this->assertNull($response->json('data.platform_user_id'));
        $this->assertFalse((bool) $response->json('data.platform_id_generated'));
        $this->assertSame('gateway', $response->json('data.source'));
        $this->assertSame(['student', 'teacher'], $response->json('data.roles'));
    }

    public function test_public_path_never_trusts_identity_header(): void
    {
        // health 在白名单内 → 不做验签，也就没有 trusted 标记
        config(['platform.gateway.direct_access' => 'allow']);

        $response = $this->getJson(self::HEALTH, ['X-User-Id' => self::SUBJECT]);

        $response->assertStatus(200);
        // 即便带了合法 sub 也不应有身份回显（健康检查本来就不返回身份，这里验证不被污染）
        $this->assertNull($response->json('data.uid'));
        $this->assertNull($response->json('data.forum_uid'));
    }

    // —————————————————————————— 验签 ——————————————————————————

    public function test_missing_signature_is_rejected(): void
    {
        $response = $this->getJson(self::INTERNAL_PROJECT, ['X-Timestamp' => (string) time()]);

        $response->assertStatus(403);
        $this->assertSame('FORBIDDEN', $response->json('code'));
        $this->assertSame('SIGN_MISSING', $response->json('data.reason'));
    }

    public function test_wrong_signature_is_rejected(): void
    {
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders([], 'wrong-secret'));

        $response->assertStatus(403);
        $this->assertSame('SIGN_MISMATCH', $response->json('data.reason'));
    }

    public function test_expired_timestamp_is_rejected_with_skew(): void
    {
        $response = $this->getJson(
            self::INTERNAL_PROJECT,
            $this->signedHeaders([], self::SECRET, time() - 3600)
        );

        $response->assertStatus(403);
        $this->assertSame('CLOCK_SKEW', $response->json('data.reason'));
        $this->assertGreaterThan(300, (int) $response->json('data.skewSeconds'));
    }

    public function test_valid_signature_is_accepted_and_request_id_is_echoed(): void
    {
        $traceId = 'trace-internal-ok-01';
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders([
            'X-Request-Id' => $traceId,
        ]));

        $response->assertStatus(200);
        $this->assertSame(self::PROJECT_ID, $response->json('data.projectId'));
        $this->assertSame('workshop', $response->json('data.sourcePlatform'));
        // requestId 双向透传（§6.4.3 规则四）
        $this->assertSame($traceId, $response->json('requestId'));
    }

    public function test_untrusted_direct_call_cannot_reach_internal_api(): void
    {
        // 直连放行但未验签 → 内部接口必须以 SERVICE_ONLY 拒绝
        config(['platform.gateway.direct_access' => 'allow']);

        $response = $this->getJson(self::INTERNAL_PROJECT);

        $response->assertStatus(403);
        $this->assertSame('SERVICE_ONLY', $response->json('data.reason'));
    }

    public function test_user_mode_cannot_call_internal_api(): void
    {
        $response = $this->getJson(self::INTERNAL_PROJECT, $this->signedHeaders([
            'X-User-Id' => self::SUBJECT,
            'X-User-Role' => 'student',
            'X-Auth-Mode' => 'user',
        ]));

        $response->assertStatus(403);
        $this->assertSame('USER_MODE_REJECTED', $response->json('data.reason'));
    }

    public function test_secret_missing_fails_closed(): void
    {
        // 没配密钥时不能"默认通过"，必须拒绝（fail-closed）
        config(['platform.gateway.internal_secret' => '']);

        $response = $this->getJson(self::INTERNAL_PROJECT, ['X-Timestamp' => (string) time()]);

        $response->assertStatus(403);
        $this->assertSame('SECRET_MISSING', $response->json('data.reason'));
    }

    // —————————————————————————— 幂等 ——————————————————————————

    public function test_idempotency_key_is_required_for_writes(): void
    {
        $response = $this->postJson(self::INTERNAL_PUBLISH, [], $this->signedHeaders());

        $response->assertStatus(400);
        $this->assertSame('VALIDATION_ERROR', $response->json('code'));
        $this->assertSame('IDEMPOTENCY_KEY_REQUIRED', $response->json('data.reason'));
    }

    public function test_illegal_idempotency_key_is_rejected(): void
    {
        $response = $this->postJson(self::INTERNAL_PUBLISH, [], $this->signedHeaders([
            'Idempotency-Key' => 'short',
        ]));

        $response->assertStatus(400);
        $this->assertSame('IDEMPOTENCY_KEY_INVALID', $response->json('data.reason'));
    }

    public function test_idempotent_replay_returns_cached_result_with_new_request_id(): void
    {
        $key = 'idem-' . bin2hex(random_bytes(6));

        // 两次调用带不同的入站追踪号：重放必须回显"本次"的号，而不是缓存里那次的
        $firstHeaders = $this->signedHeaders(['Idempotency-Key' => $key, 'X-Request-Id' => 'trace-idem-first']);
        $secondHeaders = $this->signedHeaders(['Idempotency-Key' => $key, 'X-Request-Id' => 'trace-idem-second']);

        $first = $this->postJson(self::INTERNAL_PUBLISH, [], $firstHeaders);
        $first->assertStatus(200);
        $this->assertSame(1, $first->json('ret'));
        $workId = (string) $first->json('data.workId');
        $this->assertStringStartsWith('work_mock_', $workId, '未配置广场地址时应走 mock');
        $this->assertNull($first->json('idempotentReplay'));
        $this->assertSame('trace-idem-first', $first->json('requestId'));

        // 同一个键再投一次：命中缓存，业务结果一致但不重复执行
        $second = $this->postJson(self::INTERNAL_PUBLISH, [], $secondHeaders);
        $second->assertStatus(200);
        $this->assertTrue($second->json('idempotentReplay'));
        $this->assertSame($workId, $second->json('data.workId'));

        // 重放的是业务结果，不是追踪号：requestId 必须是本次的
        $this->assertSame('trace-idem-second', $second->json('requestId'));
        // 只落一条发件箱记录，证明第二次没有真正执行
        $this->assertSame(
            1,
            PlatformEventOutbox::where('source_entity_id', 'project_' . self::PROJECT_ID)
                ->where('id', '>', $this->outboxFloor)
                ->count(),
            '幂等重放不应重复写发件箱'
        );
    }

    // —————————————————————————— CORS ——————————————————————————

    public function test_cors_preflight_echoes_allowed_origin(): void
    {
        config(['platform.gateway.cors_origins' => 'https://workshop.example.edu']);

        // OPTIONS 的 Origin 必须走 $server（HTTP_ORIGIN）：TestCase::call 不合并 defaultHeaders
        $response = $this->call('OPTIONS', self::INTERNAL_PROJECT, [], [], [], [
            'HTTP_ORIGIN' => 'https://workshop.example.edu',
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://workshop.example.edu', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_cors_does_not_echo_unknown_origin(): void
    {
        config(['platform.gateway.cors_origins' => 'https://workshop.example.edu']);

        $response = $this->getJson(self::HEALTH, ['Origin' => 'https://evil.example.com']);

        // 未命中白名单时写 `null`（规范里的"不透明来源"），浏览器必然拦截；
        // 关键是绝不能是 `*`——那等于放开给所有站点。
        $this->assertSame('null', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }
}
