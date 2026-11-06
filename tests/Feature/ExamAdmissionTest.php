<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\ExamAdmissionProjection;
use App\Models\ProjectRecruitment;
use App\Models\User;
use App\Models\UserIdentityBinding;
use App\Services\ExamClient;
use App\Services\GatewaySigner;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 考试准入测试（api/v1/workshop/recruitments/{id}/admission）
 *
 * 覆盖《实训平台_统一身份与网关接入说明》V3.0 §8.3：
 *   - 未绑定课程 → 不校验，reason=not_required，且不触发回源
 *   - 已绑定课程 → 必须回源；投影命中则走快路径
 *   - 回源超时 / 连不上 → reason=pending + HTTP 200，**绝不判为不合格**
 *   - 无 sub、非发布者、招募不存在 → 409 / 403 / 404，各有独立 reason
 *
 * 招募 8 是种子数据（publisher=admin，course_id 为空），用例只临时改它的
 * course_id，finally 里恢复，不留脏数据。
 *
 * 运行：PHP_BIN=D:/dt-run/php/php.exe npm run test -- --filter ExamAdmissionTest
 */
class ExamAdmissionTest extends TestCase
{
    private const SECRET = 'test-gateway-secret-0001';

    /**
     * 统一身份 sub = 论坛 uid（十进制字符串，R1/R2 不做任何变换）
     *
     * 旧值 usr_Test_Exam_0001 是非十进制串，新契约下网关路径会判定"不是合法 uid"
     * 直接 401；改用预置的真实论坛账号 uid 5。
     */
    private const SUBJECT = '5';

    private const COURSE = 'course_test_exam_01';

    /**
     * publisher_id=5，course_id 为空
     *
     * 必须与本用例的 SUBJECT 同值，否则准入接口会先判 403 ADMISSION_FORBIDDEN
     * （非发布者且非 admin），根本走不到准入逻辑。
     * 招募 8 的发布者是 uid 1，账号体系按论坛 uid 重建后已不再适配本用例。
     */
    private const RECRUITMENT_ID = 16;

    /** publisher_id=5，同样为 SUBJECT 自己发布的另一条招募 */
    private const OTHERS_RECRUITMENT_ID = 15;

    /** publisher_id=1，用于 403 用例：请求方 uid 5 既非发布者也无 admin 角色 */
    private const FOREIGN_RECRUITMENT_ID = 8;

    private ?string $originalCourseId = null;

    /** 临时造的用户，tearDown 删除 */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->resetAuthCache();

        config([
            'platform.gateway.verify_sign' => true,
            'platform.gateway.internal_secret' => self::SECRET,
            'platform.gateway.direct_access' => 'reject',
            // 未配置 base_url → 自动 mock，避免测试产生真实出站请求
            'platform.exam.base_url' => '',
            'platform.exam.mock' => null,
            'platform.exam.mock_eligible' => true,
            'platform.exam.cache_enabled' => true,
            'platform.exam.projection_ttl' => 300,
        ]);

        $this->bindSubjectToPresetUid();
    }

    protected function tearDown(): void
    {
        $this->restoreCourseId();
        UserIdentityBinding::whereSubject(self::SUBJECT)->delete();
        ExamAdmissionProjection::whereSubject(self::SUBJECT)->delete();

        foreach ($this->createdUserIds as $userid) {
            UserIdentityBinding::whereUserid($userid)->delete();
            User::whereUserid($userid)->delete();
        }
        $this->createdUserIds = [];

        // 只清 array 驱动（测试进程内缓存）；redis 驱动下不能 flush，那会清掉真实环境的缓存
        if (config('cache.default') === 'array') {
            Cache::flush();
        }

        parent::tearDown();
    }

    private function resetAuthCache(): void
    {
        if (isset($GLOBALS['_A'])) {
            unset($GLOBALS['_A']['__static_auth']);
        }
    }

    /**
     * 为预置 uid 5 准备一条绑定记录（userid 与 subject 必须同值）
     *
     * 新契约下绑定表已退化为一致性校验：userid ≠ uid 会被判未绑定并留 warning，
     * 所以这里不再把 userid 写成别的账号，而是严格写 uid 自身。
     */
    private function bindSubjectToPresetUid(): void
    {
        $user = User::whereUserid((int) self::SUBJECT)->first();
        $this->assertNotNull($user, '数据库缺少 uid ' . self::SUBJECT . '，无法测试');

        UserIdentityBinding::whereSubject(self::SUBJECT)->delete();
        UserIdentityBinding::create([
            'issuer' => (string) config('platform.sso.issuer'),
            'subject' => self::SUBJECT,
            'userid' => (int) self::SUBJECT,
            'status' => UserIdentityBinding::STATUS_ACTIVE,
            'bound_at' => now(),
        ]);
    }

    /**
     * 临时给招募绑定课程，tearDown 恢复
     */
    private function bindCourse(string $courseId = self::COURSE): void
    {
        $recruitment = ProjectRecruitment::find(self::RECRUITMENT_ID);
        $this->assertNotNull($recruitment, '数据库缺少招募 ' . self::RECRUITMENT_ID);

        $this->originalCourseId = (string) $recruitment->course_id;
        $recruitment->course_id = $courseId;
        $recruitment->save();
    }

    private function restoreCourseId(): void
    {
        if ($this->originalCourseId === null) {
            return;
        }
        $recruitment = ProjectRecruitment::find(self::RECRUITMENT_ID);
        if ($recruitment) {
            $recruitment->course_id = $this->originalCourseId;
            $recruitment->save();
        }
        $this->originalCourseId = null;
    }

    /**
     * 网关注入头 + L1 签名（服务态）
     */
    private function signedHeaders(array $overrides = []): array
    {
        $headers = array_merge([
            'X-User-Id' => self::SUBJECT,
            'X-User-Role' => 'student',
            'X-Client-Id' => 'test-client',
            'X-Token-Exp' => '',
            'X-Request-Id' => 'trace-' . bin2hex(random_bytes(4)),
            'X-Auth-Mode' => 'user',
            'X-Timestamp' => (string) time(),
        ], $overrides);

        $headers['X-Internal-Sign'] = GatewaySigner::trustSign($headers, self::SECRET);

        return $headers;
    }

    private function admissionUrl(int $id = self::RECRUITMENT_ID): string
    {
        return '/api/v1/workshop/recruitments/' . $id . '/admission';
    }

    // —————————————————————————— 不校验准入 ——————————————————————————

    public function test_recruitment_without_course_does_not_require_admission(): void
    {
        $response = $this->getJson($this->admissionUrl(), $this->signedHeaders());

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.required'));
        $this->assertTrue($response->json('data.eligible'), '未绑定课程必须直接放行');
        $this->assertSame('not_required', $response->json('data.reason'));
        $this->assertSame('local', $response->json('data.source'));
        // 这条路径不应产生任何投影或缓存
        $this->assertSame(0, ExamAdmissionProjection::whereSubject(self::SUBJECT)->count());
    }

    // —————————————————————————— 回源 / 投影 ——————————————————————————

    public function test_bound_course_requires_admission_and_falls_back_to_mock(): void
    {
        $this->bindCourse();

        $response = $this->getJson($this->admissionUrl(), $this->signedHeaders());

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.required'));
        $this->assertSame(self::COURSE, $response->json('data.courseId'));
        $this->assertSame(self::SUBJECT, $response->json('data.subject'));
        $this->assertTrue($response->json('data.eligible'));
        $this->assertSame('passed', $response->json('data.reason'));
        // 明确标注 mock，避免联调时把演示结论当真实回源
        $this->assertSame('mock', $response->json('data.source'));
        $this->assertTrue($response->json('data.mock'));
    }

    public function test_projection_short_circuits_the_remote_call(): void
    {
        $this->bindCourse();

        ExamAdmissionProjection::updateOrCreate(
            ['subject' => self::SUBJECT, 'course_id' => self::COURSE],
            [
                'eligible' => 0,
                'reason' => ExamClient::REASON_FAILED,
                'score' => 51,
                'rule_version' => 'rule-test-1',
                'granted_at' => now()->subMinute(),
                'source' => ExamAdmissionProjection::SOURCE_EVENT,
                'last_event_id' => 'evt-test-projection',
                'expires_at' => now()->addMinutes(5),
            ]
        );

        $response = $this->getJson($this->admissionUrl(), $this->signedHeaders());

        $response->assertStatus(200);
        $this->assertSame('projection', $response->json('data.source'));
        $this->assertFalse($response->json('data.eligible'), '投影结论必须被直接采用，不走 mock');
        $this->assertSame('failed', $response->json('data.reason'));
        $this->assertSame(51, $response->json('data.score'));
        $this->assertSame('rule-test-1', $response->json('data.ruleVersion'));
    }

    public function test_expired_projection_is_ignored(): void
    {
        $this->bindCourse();

        ExamAdmissionProjection::updateOrCreate(
            ['subject' => self::SUBJECT, 'course_id' => self::COURSE],
            [
                'eligible' => 0,
                'reason' => ExamClient::REASON_FAILED,
                'rule_version' => '',
                'source' => ExamAdmissionProjection::SOURCE_EVENT,
                'last_event_id' => 'evt-test-expired',
                'expires_at' => now()->subMinute(),
            ]
        );

        $response = $this->getJson($this->admissionUrl(), $this->signedHeaders());

        $response->assertStatus(200);
        // 过期的投影不得继续影响判定，应回到回源分支
        $this->assertNotSame('projection', $response->json('data.source'));
        $this->assertSame('mock', $response->json('data.source'));
    }

    // —————————————————————————— 降级纪律（服务层） ——————————————————————————

    public function test_empty_course_skips_remote_call(): void
    {
        $result = (new ExamClient())->admission(self::SUBJECT, '');

        $this->assertTrue($result['eligible']);
        $this->assertSame(ExamClient::REASON_PASSED, $result['reason']);
    }

    public function test_unreachable_exam_platform_degrades_to_pending(): void
    {
        // 指向一个必然连不上的地址，验证"超时≠不合格"这条硬纪律
        config([
            'platform.exam.base_url' => 'http://127.0.0.1:9',
            'platform.exam.timeout_ms' => 200,
            'platform.exam.mock' => false,
        ]);

        $result = (new ExamClient())->admission(self::SUBJECT, self::COURSE);

        $this->assertFalse($result['eligible'], 'pending 时 eligible 必须为 false');
        $this->assertSame(ExamClient::REASON_PENDING, $result['reason'], '回源失败必须降级为 pending，不能判不合格');
        $this->assertSame(ExamClient::SOURCE_REMOTE, $result['source']);
    }

    public function test_check_allows_pending_is_false_but_is_not_a_rejection_reason(): void
    {
        config([
            'platform.exam.base_url' => 'http://127.0.0.1:9',
            'platform.exam.timeout_ms' => 200,
            'platform.exam.mock' => false,
        ]);

        $checked = (new ExamClient())->check(self::SUBJECT, self::COURSE);

        $this->assertFalse($checked['allow'], 'pending 时不应放行报名');
        $this->assertSame(ExamClient::REASON_PENDING, $checked['result']['reason']);
        // 但文案必须是"请稍后重试"，不是"不合格"
        $this->assertStringContainsString('稍后', ExamClient::reasonMessage(ExamClient::REASON_PENDING));
    }

    public function test_reason_enum_has_a_distinct_message_for_each_value(): void
    {
        $reasons = [
            ExamClient::REASON_PASSED,
            ExamClient::REASON_NOT_STARTED,
            ExamClient::REASON_FAILED,
            ExamClient::REASON_VIOLATION,
            ExamClient::REASON_PENDING,
            ExamClient::REASON_PREREQUISITE_MISSING,
        ];

        $messages = [];
        foreach ($reasons as $reason) {
            $message = ExamClient::reasonMessage($reason, '课程名');
            $this->assertNotSame('', $message, "reason={$reason} 缺少文案");
            $messages[] = $message;
        }

        $this->assertCount(count($reasons), array_unique($messages), '六个 reason 的文案必须互不相同');
    }

    // —————————————————————————— 异常分支 ——————————————————————————

    public function test_missing_recruitment_returns_404(): void
    {
        $response = $this->getJson($this->admissionUrl(99999999), $this->signedHeaders());

        $response->assertStatus(404);
        $this->assertSame('NOT_FOUND', $response->json('code'));
        $this->assertSame('RECRUITMENT_NOT_FOUND', $response->json('data.reason'));
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        // 带合法签名但 X-User-Id 为空 = 网关认得这是内部调用，但没有身份
        $response = $this->getJson($this->admissionUrl(), $this->signedHeaders(['X-User-Id' => '']));

        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    public function test_non_publisher_without_admin_role_is_forbidden(): void
    {
        // 存量 token 兜底只在"尚未接网关"（direct_access=allow）的过渡期可用，
        // 一旦验签开启，未签名的直连会在中间件层就被 403 掉，走不到身份解析。
        config(['platform.gateway.direct_access' => 'allow']);

        // 招募 8 的发布者是 uid 1，这里用 uid 5 请求：既不是发布者、也无 admin 身份
        $publisher = (int) ProjectRecruitment::find(self::FOREIGN_RECRUITMENT_ID)->publisher_id;
        $this->assertNotSame((int) self::SUBJECT, $publisher, '用例前提：请求方不得是发布者');

        $requester = User::whereUserid((int) self::SUBJECT)->first();
        $this->assertNotNull($requester, '数据库缺少 uid ' . self::SUBJECT . '，无法测试');

        $response = $this->getJson(
            $this->admissionUrl(self::FOREIGN_RECRUITMENT_ID),
            ['token' => User::token($requester)]
        );

        $response->assertStatus(403);
        $this->assertSame('ADMISSION_FORBIDDEN', $response->json('data.reason'));
    }

    public function test_account_without_subject_cannot_query_admission(): void
    {
        // 必须同时打开 direct_access 与关掉 verify_sign，空 X-Internal-Sign 才不会被
        // 中间件层直接 403 掉，从而真正走到 token 兜底 + 409 分支。
        config([
            'platform.gateway.direct_access' => 'allow',
            'platform.gateway.verify_sign' => false,
        ]);
        $this->bindCourse();

        // 新契约下 X-User-Id 即 uid，任何能登录的账号都会带 subject，
        // 409 SUBJECT_NOT_BOUND 已不可由正常账号触发。这里直接构造一个
        // subject 为空的会话态，验证准入接口对该残缺态必须明确拒绝而不是拿 userid 顶替。
        $session = \App\Services\WorkshopSession::create([
            'userid' => (int) self::SUBJECT,
            'subject' => '',
            'roles' => [],
            'client_id' => 'test-client',
            'mode' => 'user',
        ]);

        // getJson() 走 prepareCookiesForJsonRequest()，withCredentials 为假时直接返回空数组，
        // cookie 根本不会进入请求（表现为 401 而非 409）。必须显式 withCredentials()。
        // workshop 路由挂在 web 组下，含 EncryptCookies，故用加密版 withCookie()。
        $response = $this->withCredentials()
            ->withCookie(\App\Services\WorkshopSession::cookieName(), $session)
            ->getJson($this->admissionUrl());

        $response->assertStatus(409);
        $this->assertSame('CONFLICT', $response->json('code'));
        $this->assertSame('SUBJECT_NOT_BOUND', $response->json('data.reason'));
    }
}
