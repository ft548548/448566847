<?php

namespace App\Http\Controllers\Api;

use App\Models\ProjectRecruitment;
use App\Models\User;
use App\Services\GatewayIdentity;
use App\Services\PeerIdentity;
use App\Services\PlatformResponse;
use App\Services\TaskBindingService;
use Illuminate\Http\Request;

/**
 * 实训任务勾选绑定（《三平台对接方案 V2.0》§3.1）
 *
 * 这是**本平台业务侧入口**，不是对端接口：浏览器/管理端在这里勾选课程与考试，
 * 勾选结果写入绑定表并投递给 learning / exam。真正的服务间通道在
 * TrainingPeerController，两者不要混。
 *
 * 实训任务复用现有的"项目大厅招募"（project_recruitments）：
 *   taskId = task_<recruitment.id>
 * 这样发布、报名、成员等既有流程一行不用改，勾选只是给它挂了准入条件。
 */
class TrainingTaskController extends AbstractController
{
    /**
     * 查看任务绑定
     *
     * GET /api/v1/training/tasks/{taskId}/bindings
     *
     * @param Request $request
     * @param string $taskId
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $taskId)
    {
        $task = PeerIdentity::taskId($taskId);
        $recruitment = $this->findTask($task);
        if (!$recruitment) {
            return PlatformResponse::fail(
                PlatformResponse::NOT_FOUND,
                '实训任务不存在：' . $task,
                ['reason' => 'TASK_NOT_FOUND', 'taskId' => $task],
                404
            );
        }

        $requirements = (new TaskBindingService())->requirementsOf($task);

        return PlatformResponse::ok([
            'taskId' => $task,
            'taskTitle' => (string) $recruitment->title,
            'requirements' => $requirements,
            'minScore' => (int) config('platform.training.min_score', 90),
            'minScoreMode' => (string) config('platform.training.min_score_mode', 'all'),
            'ruleVersion' => (string) config('platform.training.rule_version', 'v1'),
        ]);
    }

    /**
     * 勾选并发布/更新绑定
     *
     * POST /api/v1/training/tasks/{taskId}/bindings
     * body: { requirements: [ { courseId: 2, examId: "exam_2" } ], eventType?: "published|updated" }
     *
     * 权限沿用招募的既有口径：发布者本人或管理员。
     *
     * @param Request $request
     * @param string $taskId
     * @return \Illuminate\Http\JsonResponse
     */
    public function publish(Request $request, $taskId)
    {
        $identity = GatewayIdentity::resolve($request);
        if (!$identity->authenticated()) {
            return PlatformResponse::authRequired('请登录后继续...', ['reason' => 'AUTH_REQUIRED']);
        }

        $task = PeerIdentity::taskId($taskId);
        $recruitment = $this->findTask($task);
        if (!$recruitment) {
            return PlatformResponse::fail(
                PlatformResponse::NOT_FOUND,
                '实训任务不存在：' . $task,
                ['reason' => 'TASK_NOT_FOUND', 'taskId' => $task],
                404
            );
        }

        $isOwner = (int) $recruitment->publisher_id === $identity->userid;
        $isAdmin = $identity->hasRole('admin')
            || ($identity->user && User::identityRaw('admin', $identity->user->identity));
        if (!$isOwner && !$isAdmin) {
            return PlatformResponse::fail(
                PlatformResponse::FORBIDDEN,
                '只有任务发布者或管理员可以修改勾选绑定',
                ['reason' => 'BINDING_FORBIDDEN'],
                403
            );
        }

        $requirements = $request->input('requirements');
        if (!is_array($requirements)) {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                'requirements 必须是数组',
                ['reason' => 'REQUIREMENTS_INVALID'],
                400
            );
        }

        $eventType = strtolower(trim((string) $request->input('eventType', 'published')));
        $eventType = $eventType === 'updated'
            ? TaskBindingService::EVENT_UPDATED
            : TaskBindingService::EVENT_PUBLISHED;

        $service = new TaskBindingService();
        $result = $service->publish(
            $task,
            (string) $recruitment->title,
            $requirements,
            $eventType
        );

        if (!$result['ok']) {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                $result['message'],
                ['reason' => 'BINDING_REJECTED'],
                400
            );
        }

        return PlatformResponse::ok([
            'taskId' => $task,
            'taskTitle' => (string) $recruitment->title,
            'requirements' => $service->requirementsOf($task),
            'eventId' => $result['eventId'],
            'targets' => $result['targets'],
            'minScore' => (int) config('platform.training.min_score', 90),
        ], $result['message']);
    }

    /**
     * task_1001 → ProjectRecruitment
     *
     * @param string $taskId
     * @return ProjectRecruitment|null
     */
    private function findTask(string $taskId): ?ProjectRecruitment
    {
        if (stripos($taskId, 'task_') !== 0) {
            return null;
        }
        $id = (int) substr($taskId, 5);
        if ($id <= 0) {
            return null;
        }
        return ProjectRecruitment::find($id);
    }
}
