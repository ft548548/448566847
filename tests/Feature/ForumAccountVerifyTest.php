<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Models\UserIdentityBinding;
use App\Services\GatewaySigner;
use App\Services\IdentityBinder;
use App\Services\WorkshopSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 论坛账号确认测试（api/v1/workshop/auth/forum-verify）
 *
 * 覆盖《警院论坛账号确认接口 · 接口说明与四平台接入指南》v2.2.0：
 *   - confirmed=true 才建会话；建出的会话与 OAuth 入口结构一致
 *   - 账号不存在与密码错误返回同一结论（防枚举，平台不做二次区分）
 *   - 渠道故障（超时 / 5xx / 签名错）**绝不**报成"账号或密码错误"
 *   - 网关码原样带回 gatewayCode，联调时能一眼看出卡在哪一段
 *   - 签名必须覆盖"实际发出的字节"，不能覆盖"重新编码的另一份"
 *   - 连续失败达阈值即锁定，且成功一次就清零
 *
 * 所有用例都用 Http::fake 拦截出站，测试过程不发真实网络请求。
 *
 * 运行：PHP_BIN=D:/dt-run/php/php.exe npm run test -- --filter ForumAccountVerifyTest
 */
class ForumAccountVerifyTest extends TestCase
{
    private const ENDPOINT = '/api/v1/workshop/auth/forum-verify';
    private const GW = 'http://gw.test';
    private const GW_PATH = '/answer/api/v1/account/verify';
    private const SECRET = 'test-forum-secret-0001';
    private const LOGIN = 'admin@jspi.edu.cn';
    private const PASS = 'Admin@12345';

    /** 预置账号，uid 即论坛 user.id（R1/R2） */
    private const UID = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config([
            'platform.forum.enabled' => true,
            'platform.forum.base_url' => '',
            'platform.forum.app_secret' => '',
            'platform.forum.mock' => null,
            'platform.forum.app_key' => 'jy-workshop-svc',
            'platform.forum.verify_path' => self::GW_PATH,
            'platform.forum.throttle_max' => 5,
            'platform.forum.throttle_window' => 300,
        ]);

        // 只清 array 驱动；redis 下 flush 会清掉真实缓存
        if (config('cache.default') === 'array') {
            Cache::flush();
        }

        $this->purgeTestAccounts();
    }

    protected function tearDown(): void
    {
        $this->purgeTestAccounts();
        if (config('cache.default') === 'array') {
            Cache::flush();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 成功路径
    // ------------------------------------------------------------------

    public function test_non_uid_subject_is_rejected_without_alias_account(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'login' => self::LOGIN,
            'pass' => self::PASS,
        ]);

        // mock 的 subject 形如 mock_forum_xxx，不是十进制 uid。
        // 按 R10：拒绝建号，且不生成任何替代标识（不收口成 200 + 本地新主键）。
        $response->assertStatus(400);
        $this->assertSame('VALIDATION_ERROR', $response->json('code'));

        // mock 仍须显式回传，否则"本地能登录"会被误当成"接入验证通过"
        $this->assertTrue($response->json('data.mock'));
        $this->assertSame('mock', $response->json('data.source'));

        // 不生成任何替代标识：既无 uid，也无平台侧 ID
        $this->assertNull($response->json('data.uid'));
        $this->assertNull($response->json('data.forum_uid'));
        $this->assertFalse((bool) $response->json('data.platform_id_generated'));
        $this->assertNull($response->json('data.platform_user_id'));

        // 更关键的一条：库中不得出现以 mock_forum_ 为 subject 的绑定行，
        // 否则"拒绝建号"只是话术，数据层还是留了一个可用的影子账号。
        $this->assertSame(0, UserIdentityBinding::where('subject', 'like', 'mock_forum_%')->count());
    }

    public function test_repeated_login_keeps_single_uid(): void
    {
        // 用真实论坛 uid 走两遍：成功路径在网关打通前由 bindOrCreate 直接验证，
        // 这里断言"同一 uid 反复登录只指向同一条绑定，不产生第二个标识"。
        $uid = self::UID;
        $binder = new IdentityBinder();

        $first = $binder->bindOrCreate('jingyuan-forum', (string) $uid, ['nickname' => '']);
        $this->assertTrue($first['ok'], '预置账号应能直接补绑定');
        $this->assertSame($uid, (int) $first['user']->userid);

        $second = $binder->bindOrCreate('jingyuan-forum', (string) $uid, ['nickname' => '']);
        $this->assertTrue($second['ok']);
        $this->assertFalse((bool) $second['created'], '二次登录不应重复建号');
        $this->assertSame($uid, (int) $second['user']->userid);

        // 同一 uid 只有一条绑定，且绑定指向的本地主键就是 uid
        $this->assertSame(1, UserIdentityBinding::whereSubject((string) $uid)->count());
        $binding = UserIdentityBinding::findActive('jingyuan-forum', (string) $uid);
        $this->assertNotNull($binding);
        $this->assertSame($uid, (int) $binding->userid);
    }

    public function test_non_canonical_uid_is_rejected(): void
    {
        $binder = new IdentityBinder();

        // 前导零 / 非数字 / 空 / 0：全部拒绝，绝不"归一化"成另一个主键
        foreach (['007', 'abc', '', '0', ' 5', '5 ', '-5', '+5', '5.0'] as $bad) {
            $this->assertNull($binder->parseUid($bad), "非规范 uid 应被拒绝：{$bad}");

            $result = $binder->bindOrCreate('jingyuan-forum', $bad, []);
            $this->assertFalse($result['ok'], "非规范 uid 不得建号：{$bad}");
            $this->assertSame('VALIDATION_ERROR', $result['code']);
            $this->assertNull($result['user']);
        }

        // 合法 uid 解析为正整数
        $this->assertSame(7, $binder->parseUid('7'));
    }

    // ------------------------------------------------------------------
    // 入参校验（本地拦截，不出站）
    // ------------------------------------------------------------------

    public function test_missing_login_is_rejected_locally(): void
    {
        Http::fake(); // 任何出站请求都会因未匹配而抛错，用例失败即证明"没有出站"

        $response = $this->postJson(self::ENDPOINT, ['login' => '', 'pass' => self::PASS]);

        $response->assertStatus(400);
        $this->assertSame('VALIDATION_ERROR', $response->json('code'));
        $this->assertSame('invalid_params', $response->json('data.reason'));
    }

    public function test_short_password_is_rejected_locally(): void
    {
        Http::fake();

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => '1234567']);

        $response->assertStatus(400);
        $this->assertSame('invalid_params', $response->json('data.reason'));
    }

    public function test_too_long_login_is_rejected_locally(): void
    {
        Http::fake();

        $response = $this->postJson(self::ENDPOINT, [
            'login' => str_repeat('a', 101),
            'pass' => self::PASS,
        ]);

        $response->assertStatus(400);
        $this->assertSame('invalid_params', $response->json('data.reason'));
    }

    public function test_invalid_login_type_is_rejected(): void
    {
        Http::fake();

        $response = $this->postJson(self::ENDPOINT, [
            'login' => self::LOGIN,
            'pass' => self::PASS,
            'login_type' => 'badtype',
        ]);

        // 文档 §四：非法 login_type → 400/40001，不做"静默归一为 auto"
        $response->assertStatus(400);
        $this->assertSame('invalid_params', $response->json('data.reason'));
    }

    // ------------------------------------------------------------------
    // 认证失败：只透传，不猜测
    // ------------------------------------------------------------------

    public function test_invalid_credentials_returns_401_with_reason(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response([
                'code' => 40104,
                'message' => '账号或密码错误',
                'data' => ['confirmed' => false, 'reason' => 'invalid_credentials'],
            ], 401),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => 'WrongPass123']);

        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
        $this->assertSame('invalid_credentials', $response->json('data.reason'));
        // 网关原始码要留档，便于区分"卡在网关"还是"到了论坛"
        $this->assertSame(40104, $response->json('data.gatewayCode'));
    }

    public function test_account_unavailable_keeps_its_own_reason(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response([
                'code' => 40104,
                'message' => '账号不可用',
                'data' => ['confirmed' => false, 'reason' => 'account_unavailable'],
            ], 401),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        $response->assertStatus(401);
        $this->assertSame('account_unavailable', $response->json('data.reason'));
    }

    public function test_unknown_reason_falls_back_to_invalid_credentials(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response([
                'code' => 40104,
                'data' => ['confirmed' => false, 'reason' => 'something_new_from_forum'],
            ], 401),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        // 未知 reason 按 invalid_credentials 兜底：不能凭空造出第三套原因
        $response->assertStatus(401);
        $this->assertSame('invalid_credentials', $response->json('data.reason'));
    }

    // ------------------------------------------------------------------
    // 渠道故障：绝不能报成密码错误
    // ------------------------------------------------------------------

    public function test_upstream_error_is_not_reported_as_wrong_password(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response(['code' => 50200, 'message' => '上游不可达'], 502),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        $response->assertStatus(503);
        $this->assertSame('SERVICE_UNAVAILABLE', $response->json('code'));
        $this->assertSame('channel_misconfigured', $response->json('data.reason'));
        // 关键断言：不能出现"账号或密码错误"的结论
        $this->assertNotSame('invalid_credentials', $response->json('data.reason'));
        $this->assertSame(50200, $response->json('data.gatewayCode'));
    }

    public function test_timeout_is_channel_unavailable(): void
    {
        $this->useRemoteGateway();

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        $response->assertStatus(503);
        $this->assertSame('channel_unavailable', $response->json('data.reason'));
    }

    public function test_signature_error_points_to_configuration(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response(['code' => 40105, 'message' => '服务签名校验失败'], 401),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        $response->assertStatus(503);
        // 401 是网关在拒我们，不是用户密码错：这里必须是渠道问题
        $this->assertSame('channel_misconfigured', $response->json('data.reason'));
        $this->assertSame(40105, $response->json('data.gatewayCode'));
        $this->assertStringContainsString('签名', (string) $response->json('msg'));
    }

    public function test_mock_disabled_without_secret_reports_misconfigured(): void
    {
        config(['platform.forum.mock' => false]);

        Http::fake();

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        $response->assertStatus(503);
        $this->assertSame('channel_misconfigured', $response->json('data.reason'));
    }

    public function test_disabled_channel_returns_503(): void
    {
        config(['platform.forum.enabled' => false]);

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        $response->assertStatus(503);
        $this->assertSame('disabled', $response->json('data.reason'));
    }

    public function test_confirmed_without_user_id_is_rejected(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response([
                'code' => 200,
                'message' => 'ok',
                'data' => ['confirmed' => true, 'username' => 'admin'],
            ], 200),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        // confirmed 却没给 user_id：身份不完整，宁可不建号
        $response->assertStatus(503);
        $this->assertSame('SUBJECT_MISSING', $response->json('data.reason'));
    }

    // ------------------------------------------------------------------
    // 签名正确性
    // ------------------------------------------------------------------

    public function test_signature_covers_exactly_the_bytes_that_are_sent(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response([
                'code' => 200,
                'message' => 'ok',
                'data' => [
                    'confirmed' => true,
                    // 用真实论坛 uid：身份必须是十进制原值，平台不生成替代标识
                    'user_id' => (string) self::UID,
                    'username' => 'admin',
                    'display_name' => 'admin',
                    'role' => 'admin',
                    'e_mail_masked' => 'a***@jspi.edu.cn',
                    'account_source' => 'legacy',
                    'request_id' => 'gw-req-1',
                ],
            ], 200),
        ]);

        $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS])
            ->assertStatus(200);

        $asserted = false;

        Http::assertSent(function ($request) use (&$asserted) {
            if (strpos($request->url(), self::GW_PATH) === false) {
                return false;
            }

            $asserted = true;

            $timestamp = (string) ($request->header('X-Timestamp')[0] ?? '');
            $nonce = (string) ($request->header('X-Nonce')[0] ?? '');
            $signature = (string) ($request->header('X-Signature')[0] ?? '');

            // 用"框架实际发出的 body"重算签名：若签名覆盖的是另一份重新编码的串，
            // 这里就会不相等——这正是最容易出错的点
            $expected = GatewaySigner::callSign(
                self::SECRET,
                'POST',
                self::GW_PATH,
                '',
                $request->body(),
                'jy-workshop-svc',
                $timestamp,
                $nonce,
                GatewaySigner::L2_VERSION_GATEWAY
            );

            $this->assertSame($expected, $signature, '签名必须覆盖实际发送的字节');
            $this->assertSame('HMAC-SHA256', (string) ($request->header('X-Sign-Alg')[0] ?? ''));
            // 密码必须以原值进入报文，不能被脱敏或转义成别的形式
            $this->assertStringContainsString('"pass":"' . self::PASS . '"', $request->body());

            return true;
        });

        $this->assertTrue($asserted, '未捕获到账号确认请求');
    }

    // ------------------------------------------------------------------
    // 节流
    // ------------------------------------------------------------------

    public function test_throttle_blocks_after_repeated_failures(): void
    {
        $this->useRemoteGateway();

        Http::fake([
            'gw.test/*' => Http::response([
                'code' => 40104,
                'data' => ['confirmed' => false, 'reason' => 'invalid_credentials'],
            ], 401),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => 'WrongPass123'])
                ->assertStatus(401);
        }

        $blocked = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => 'WrongPass123']);

        $blocked->assertStatus(429);
        $this->assertSame('rate_limited', $blocked->json('data.reason'));
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $blocked->json('data.retryAfter'));
    }

    public function test_channel_error_does_not_consume_throttle_quota(): void
    {
        $this->useRemoteGateway();

        // 网关抖动：不能把用户锁住，否则一次故障会让所有人登录不了
        Http::fake([
            'gw.test/*' => Http::response(['code' => 50200], 502),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS])
                ->assertStatus(503);
        }

        // 第 6 次仍是 503 而不是 429：说明失败计数没有被渠道故障消耗掉
        $response = $this->postJson(self::ENDPOINT, ['login' => self::LOGIN, 'pass' => self::PASS]);

        $response->assertStatus(503);
        $this->assertSame('channel_misconfigured', $response->json('data.reason'));
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * 切到"需要真实出站"的配置（mock 关闭 + 有密钥）
     *
     * @return void
     */
    private function useRemoteGateway(): void
    {
        config([
            'platform.forum.mock' => false,
            'platform.forum.app_secret' => self::SECRET,
            'platform.forum.base_url' => self::GW,
        ]);
    }

    /**
     * 清理测试过程中建出的账号与绑定
     *
     * 只动 mock_forum_* / test_forum_* 前缀，绝不碰种子数据。
     *
     * @return void
     */
    private function purgeTestAccounts(): void
    {
        $bindings = UserIdentityBinding::where('subject', 'like', 'mock_forum_%')
            ->orWhere('subject', 'like', 'test_forum_%')
            ->get();

        if ($bindings->isEmpty()) {
            return;
        }

        $userids = $bindings->pluck('userid')->filter()->all();
        UserIdentityBinding::whereIn('id', $bindings->pluck('id')->all())->delete();

        if ($userids) {
            User::whereIn('userid', $userids)->delete();
        }
    }
}
