<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

/**
 * 「提交项目」功能测试（api/project/submit）
 *
 * 业务规则：
 * - 仅已完成（已归档，archived_at 非空）的项目允许提交
 * - 提交成功后 submitted_at / submitted_userid 正确写入并持久化
 * - 重复提交被拒绝
 *
 * 测试通过 API 全链路进行（创建 → 归档 → 提交 → 校验持久化 → 清理），
 * 临时项目在 tearDown 中兜底删除，不留脏数据。
 *
 * 运行：PHP_BIN=D:/dt-run/php/php.exe npm run test -- --filter ProjectSubmitTest
 */
class ProjectSubmitTest extends TestCase
{
    private ?string $token = null;
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 测试进程没有 session/cookie，关闭 CSRF 校验（生产环境仍正常启用）
        $this->withoutMiddleware(VerifyCsrfToken::class);
        // User::authInfo() 会把登录用户缓存在全局 $_A['__static_auth'] 里（跨请求复用）。
        // PHPUnit 单进程运行全部用例时，前一个用例的缓存会污染后一个用例，
        // 因此每个用例开始前先清空，保证各用例的认证状态互相独立。
        $this->resetAuthCache();
    }

    /**
     * 清空全局静态认证缓存，使下一次请求重新按 token 解析身份
     */
    private function resetAuthCache(): void
    {
        if (isset($GLOBALS['_A'])) {
            unset($GLOBALS['_A']['__static_auth']);
        }
    }

    protected function tearDown(): void
    {
        // 兜底清理：用例中途失败也要删掉临时项目（deleteProject 会连带清理会话/列表等关联数据）
        foreach ($this->createdIds as $id) {
            $project = Project::withTrashed()->find($id);
            $project?->deleteProject();
        }
        $this->createdIds = [];
        parent::tearDown();
    }

    /**
     * 生成管理员 token（与登录接口生成的格式完全一致）
     */
    private function token(): string
    {
        if ($this->token === null) {
            $user = User::whereEmail('admin@dootask.com')->first();
            $this->assertNotNull($user, '数据库缺少 admin@dootask.com 用户，无法测试');
            $this->token = User::token($user);
        }
        return $this->token;
    }

    /**
     * 通过 API 创建一个临时项目（发起人即项目负责人）
     */
    private function createProject(): int
    {
        $response = $this->postJson('/api/project/add', [
            'name' => '提交功能测试-' . substr(uniqid(), -6),
            'desc' => 'ProjectSubmitTest 临时项目',
        ], ['token' => $this->token()]);

        $id = intval($response->json('data.id'));
        $this->assertGreaterThan(0, $id, '创建临时项目失败: ' . $response->json('msg'));
        $this->createdIds[] = $id;
        return $id;
    }

    public function test_unauthenticated_submit_is_rejected(): void
    {
        $response = $this->postJson('/api/project/submit', ['project_id' => 1]);

        $response->assertStatus(200);
        $this->assertSame(-1, $response->json('ret'));
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    public function test_submit_nonexistent_project_is_rejected(): void
    {
        $response = $this->postJson('/api/project/submit', [
            'project_id' => 99999999,
        ], ['token' => $this->token()]);

        $response->assertStatus(200);
        $this->assertNotSame(1, $response->json('ret'));
        $this->assertStringContainsString('项目不存在', (string) $response->json('msg'));
    }

    public function test_active_project_cannot_be_submitted(): void
    {
        $projectId = $this->createProject();

        // 未完成（未归档）的项目提交 → 明确报错
        $response = $this->postJson('/api/project/submit', [
            'project_id' => $projectId,
        ], ['token' => $this->token()]);

        $response->assertStatus(200);
        $this->assertNotSame(1, $response->json('ret'));
        $this->assertStringContainsString('未归档', (string) $response->json('msg'), '进行中的项目应被拒绝提交');

        // 数据未被污染：submitted_at 仍为空
        $this->assertNull(Project::find($projectId)->submitted_at);
    }

    public function test_completed_project_can_be_submitted_and_persists(): void
    {
        $projectId = $this->createProject();

        // 归档项目（业务上的"已完成"）
        $archived = $this->postJson('/api/project/archived', [
            'project_id' => $projectId,
            'type' => 'add',
        ], ['token' => $this->token()]);
        $this->assertSame(1, $archived->json('ret'), '归档项目失败: ' . $archived->json('msg'));

        // 提交项目
        $response = $this->postJson('/api/project/submit', [
            'project_id' => $projectId,
        ], ['token' => $this->token()]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('ret'));
        $this->assertSame('提交成功', $response->json('msg'));
        $this->assertNotEmpty($response->json('data.submitted_at'));

        // 持久化校验：从数据库重新读取
        $project = Project::find($projectId);
        $this->assertNotNull($project->submitted_at, 'submitted_at 未持久化');
        $admin = User::whereEmail('admin@dootask.com')->first();
        $this->assertSame($admin->userid, intval($project->submitted_userid), 'submitted_userid 不正确');

        // 项目动态已记录
        $this->assertTrue(
            $project->projectLog()->where('detail', '提交项目')->exists(),
            '项目动态缺少"提交项目"记录'
        );
    }

    public function test_resubmit_is_rejected(): void
    {
        $projectId = $this->createProject();

        $this->postJson('/api/project/archived', [
            'project_id' => $projectId,
            'type' => 'add',
        ], ['token' => $this->token()]);

        $first = $this->postJson('/api/project/submit', [
            'project_id' => $projectId,
        ], ['token' => $this->token()]);
        $this->assertSame(1, $first->json('ret'));

        // 重复提交 → 明确报错
        $second = $this->postJson('/api/project/submit', [
            'project_id' => $projectId,
        ], ['token' => $this->token()]);
        $this->assertNotSame(1, $second->json('ret'));
        $this->assertStringContainsString('已提交', (string) $second->json('msg'));
    }
}
