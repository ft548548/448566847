<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\PlatformEventOutbox;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\GatewaySigner;
use App\Services\ShowcaseClient;
use Tests\TestCase;

/**
 * 成果广场对接层测试（Work 映射 / 成员 sub 映射 / 作品推送占位）
 *
 * 覆盖《实训平台_统一身份与网关接入说明》V3.0 §6.3 与 §10.4：
 *   - 项目 → Work 的字段映射，sourceEntityId 用 `project_{id}` 而不是本地自增裸值
 *   - 成员 sub 映射：未绑定统一身份的成员必须显式标 unbound，绝不用 userid 顶替
 *   - 无绑定作者时推送必须 409，不能推一个没有作者的作品
 *   - 未配置广场地址时自动 mock，且响应里 mock=true，避免把演示当真实
 *
 * 推送会写发件箱（本期只落库不投递，DV-18），用例按 id 水位清理本次新增行。
 *
 * 运行：PHP_BIN=D:/dt-run/php/php.exe npm run test -- --filter ShowcaseBridgeTest
 */
class ShowcaseBridgeTest extends TestCase
{
    private const SECRET = 'test-gateway-secret-0001';

    /**
     * 种子项目，owner 为 uid 5（论坛真实账号，成员表里唯一一人）
     *
     * 项目 12 已随测试数据清理而消失；改用仍存在的 21（pre_project_users: 21 → userid 5, owner=1）。
     */
    private const PROJECT_ID = 21;

    /** 临时项目 id，tearDown 删除 */
    private array $createdIds = [];

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
            // 未配置广场地址 → 自动 mock
            'platform.showcase.base_url' => '',
            'platform.showcase.mock' => null,
            'platform.idempotency.enabled' => true,
        ]);

        $this->outboxFloor = (int) PlatformEventOutbox::max('id');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $project = Project::withTrashed()->find($id);
            $project?->deleteProject();
        }
        $this->createdIds = [];

        // 只清本次新增的发件箱行（按 id 水位）
        PlatformEventOutbox::where('id', '>', $this->outboxFloor)->delete();

        parent::tearDown();
    }

    private function resetAuthCache(): void
    {
        if (isset($GLOBALS['_A'])) {
            unset($GLOBALS['_A']['__static_auth']);
        }
    }

    private function signedHeaders(array $overrides = []): array
    {
        $headers = array_merge([
            'X-User-Id' => 'svc_exam',
            'X-User-Role' => 'service',
            'X-Client-Id' => 'svc-exam',
            'X-Token-Exp' => '',
            'X-Request-Id' => 'trace-' . bin2hex(random_bytes(4)),
            'X-Auth-Mode' => 'service',
            'X-Timestamp' => (string) time(),
        ], $overrides);

        $headers['X-Internal-Sign'] = GatewaySigner::trustSign($headers, self::SECRET);

        return $headers;
    }

    /**
     * 造一个成员全无论坛 uid 的临时项目（用于 409 用例）
     *
     * 新契约下 subjectOf() 只认 pre_users 里存在的 userid，"未绑定"等价于
     * "该 userid 不是真实论坛 uid"。注意**不能**用 User::createInstance() 造
     * 幽灵用户——那样 pre_users 里就有了这一行，subjectOf() 立刻判定为已绑定，
     * 用例反而永远拿不到 409。正确做法是只写 ProjectUser 成员行，userid
     * 取一个 pre_users 中不存在的值（从当前最大 userid 之上开始找）。
     */
    private function createTempProject(): int
    {
        $suffix = substr(uniqid(), -6);
        $ghost = $this->ghostUserid();

        $project = Project::createInstance([
            'name' => '广场映射测试-' . $suffix,
            'desc' => 'ShowcaseBridgeTest 临时项目',
            'userid' => $ghost,
        ]);
        $project->save();
        $this->createdIds[] = (int) $project->id;

        ProjectUser::createInstance([
            'project_id' => (int) $project->id,
            'userid' => $ghost,
            'owner' => 1,
        ])->save();

        return (int) $project->id;
    }

    /**
     * 取一个 pre_users 中不存在的 userid
     *
     * 账号体系按论坛 uid 重建后，pre_users 只保留真实论坛账号（1/4/5/7/8/9），
     * AUTO_INCREMENT 也在其后。这里从 max(userid)+1000 起找，既不会撞上真实账号，
     * 也不会因自增被后续插入占用而误判。
     */
    private function ghostUserid(): int
    {
        $max = (int) User::max('userid');
        $candidate = $max + 1000;

        while (User::whereUserid($candidate)->exists()) {
            $candidate++;
        }

        return $candidate;
    }

    // —————————————————————————— 纯映射（不依赖数据库） ——————————————————————————

    public function test_work_mapping_follows_contract_naming(): void
    {
        $project = new Project();
        $project->id = 123;
        $project->name = '警务技能训练系统';
        $project->desc = '<p>一个用于<b>实训</b>的项目</p>';

        $work = (new ShowcaseClient())->buildWorkFromProject($project, ['usr_a', '', 'usr_b']);

        $this->assertNull($work['workId'], '发布前 workId 必须为 null，由广场回填');
        $this->assertSame('workshop', $work['sourcePlatform']);
        $this->assertSame('project_123', $work['sourceEntityId'], 'sourceEntityId 必须带前缀，避免与广场本地 id 撞车');
        $this->assertSame('警务技能训练系统', $work['title']);
        // 空 sub 必须被过滤掉，否则广场会收到一个空作者
        $this->assertSame(['usr_a', 'usr_b'], $work['authorSubjects']);
        $this->assertSame('draft', $work['status']);
        $this->assertNull($work['publishedAt']);
    }

    public function test_publish_uses_mock_when_base_url_is_empty(): void
    {
        $client = new ShowcaseClient();
        $result = $client->publishWork(['title' => 'x'], 'trace-test-01');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['mock'], '未配置广场地址时必须明确标注 mock');
        $this->assertStringStartsWith('work_mock_', (string) $result['workId']);
        $this->assertSame('draft', $result['status']);
    }

    // —————————————————————————— 成员 sub 映射 ——————————————————————————

    public function test_members_endpoint_marks_unbound_members_explicitly(): void
    {
        $response = $this->getJson(
            '/api/v1/workshop/internal/projects/' . self::PROJECT_ID . '/members',
            $this->signedHeaders()
        );

        $response->assertStatus(200);
        $this->assertSame(self::PROJECT_ID, $response->json('data.projectId'));

        $members = (array) $response->json('data.members');
        $this->assertNotEmpty($members, '种子项目应有成员');

        $ownerCount = 0;
        foreach ($members as $member) {
            $this->assertArrayHasKey('subject', $member);
            $this->assertArrayHasKey('unbound', $member);
            $this->assertArrayHasKey('role', $member);

            // 纪律：subject 为 null ⟺ unbound=true；绝不允许用 userid 顶替 sub
            $this->assertSame(
                $member['subject'] === null,
                $member['unbound'],
                'subject 与 unbound 标记必须自洽'
            );
            $this->assertContains($member['role'], ['owner', 'member']);
            if ($member['role'] === 'owner') {
                $ownerCount++;
            }
        }

        $this->assertSame(1, $ownerCount, '一个项目只能有一个 owner');
    }

    public function test_work_endpoint_derives_authors_from_bound_members(): void
    {
        $membersResponse = $this->getJson(
            '/api/v1/workshop/internal/projects/' . self::PROJECT_ID . '/members',
            $this->signedHeaders()
        );
        $members = (array) $membersResponse->json('data.members');
        $bound = array_values(array_filter(array_map(
            static fn ($m) => $m['subject'],
            $members
        )));

        $response = $this->getJson(
            '/api/v1/workshop/internal/projects/' . self::PROJECT_ID . '/work',
            $this->signedHeaders()
        );

        $response->assertStatus(200);
        $this->assertSame('project_' . self::PROJECT_ID, $response->json('data.sourceEntityId'));
        $this->assertSame('workshop', $response->json('data.sourcePlatform'));
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertNull($response->json('data.workId'));

        // 作者列表必须与"已绑定成员"一一对应，不多不少
        $this->assertEqualsCanonicalizing($bound, (array) $response->json('data.authorSubjects'));
        $this->assertSame(count($members), (int) $response->json('data.memberCount'));
        $this->assertSame(count($members) - count($bound), (int) $response->json('data.unboundMembers'));
    }

    // —————————————————————————— 推送 ——————————————————————————

    public function test_publish_returns_work_id_and_records_outbox(): void
    {
        $response = $this->postJson(
            '/api/v1/workshop/internal/projects/' . self::PROJECT_ID . '/publish',
            [],
            $this->signedHeaders(['Idempotency-Key' => 'showcase-' . bin2hex(random_bytes(6))])
        );

        $response->assertStatus(200);
        $this->assertSame(self::PROJECT_ID, $response->json('data.projectId'));
        $this->assertStringStartsWith('work_mock_', (string) $response->json('data.workId'));
        $this->assertTrue($response->json('data.mock'));

        // 出站成功后必须留痕，否则后续无法对账（本期不投递，见 DV-18）
        $this->assertSame(
            1,
            PlatformEventOutbox::where('source_entity_id', 'project_' . self::PROJECT_ID)
                ->where('id', '>', $this->outboxFloor)
                ->count(),
            '推送成功后应写一条发件箱记录'
        );
    }

    public function test_publish_rejects_project_without_bound_author(): void
    {
        $projectId = $this->createTempProject();

        $response = $this->postJson(
            '/api/v1/workshop/internal/projects/' . $projectId . '/publish',
            [],
            $this->signedHeaders(['Idempotency-Key' => 'showcase-' . bin2hex(random_bytes(6))])
        );

        $response->assertStatus(409);
        $this->assertSame('CONFLICT', $response->json('code'));
        $this->assertSame('NO_BOUND_AUTHOR', $response->json('data.reason'));
    }

    public function test_publish_requires_service_mode(): void
    {
        $response = $this->postJson(
            '/api/v1/workshop/internal/projects/' . self::PROJECT_ID . '/publish',
            [],
            $this->signedHeaders([
                'X-Auth-Mode' => 'user',
                'X-User-Id' => 'usr_someone',
                'Idempotency-Key' => 'showcase-' . bin2hex(random_bytes(6)),
            ])
        );

        $response->assertStatus(403);
        $this->assertSame('USER_MODE_REJECTED', $response->json('data.reason'));
    }

    public function test_unknown_project_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/workshop/internal/projects/99999999/work',
            $this->signedHeaders()
        );

        $response->assertStatus(404);
        $this->assertSame('PROJECT_NOT_FOUND', $response->json('data.reason'));
    }
}
