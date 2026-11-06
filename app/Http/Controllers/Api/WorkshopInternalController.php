<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\PlatformEventService;
use App\Services\PlatformResponse;
use App\Services\ShowcaseClient;
use Illuminate\Http\Request;

/**
 * 实训平台服务间接口（/api/v1/workshop/internal/*）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §3.1、§5.2、§8.1。
 *
 * 定位：**只给其他平台的服务端调用**，不对浏览器开放。
 * 鉴权：网关 L2 签名（X-App-Key / X-Timestamp / X-Nonce / X-Signature），
 *       由 GatewayTrust 中间件统一校验；本控制器再要求 X-Auth-Mode=service。
 *
 * 三类消费方：
 *   - 成果广场（showcase）：读项目 → 作品映射、请求推送作品
 *   - 成长档案：读项目参与记录与被消费者
 *   - 考试平台（exam）：投递 exam.passed / exam.failed 事件
 *
 * 设计纪律：所有对外身份一律用 subject（sub），本地 userid 不出平台。
 * 未绑定 sub 的成员返回 subject=null 且 unbound=true，绝不拿 userid 顶替。
 */
class WorkshopInternalController extends AbstractController
{
    /**
     * 项目摘要
     *
     * GET /api/v1/workshop/internal/projects/{id}
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function projectSummary(Request $request, $id)
    {
        if ($denied = $this->requireService($request)) {
            return $denied;
        }

        $project = Project::find(intval($id));
        if (!$project) {
            return PlatformResponse::fail(
                PlatformResponse::NOT_FOUND,
                '项目不存在',
                ['reason' => 'PROJECT_NOT_FOUND'],
                404
            );
        }

        $memberCount = ProjectUser::whereProjectId($project->id)->count();

        return PlatformResponse::ok([
            'projectId' => (int) $project->id,
            'name' => (string) $project->name,
            'summary' => mb_substr(strip_tags((string) $project->desc), 0, 500),
            // 业务状态：归档即"已完成"；已提交为独立状态（本平台定义）
            'status' => $this->projectStatus($project),
            'ownerSubject' => $this->subjectOf((int) $project->userid),
            'memberCount' => $memberCount,
            'createdAt' => $this->iso($project->created_at),
            'completedAt' => $this->iso($project->archived_at),
            'submittedAt' => $this->iso($project->submitted_at),
            'updatedAt' => $this->iso($project->updated_at),
            'sourcePlatform' => (string) config('platform.app_code', 'workshop'),
        ]);
    }

    /**
     * 项目 → 作品草稿（成果广场专用）
     *
     * GET /api/v1/workshop/internal/projects/{id}/work
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function projectWork(Request $request, $id)
    {
        if ($denied = $this->requireService($request)) {
            return $denied;
        }

        $project = Project::find(intval($id));
        if (!$project) {
            return PlatformResponse::fail(
                PlatformResponse::NOT_FOUND,
                '项目不存在',
                ['reason' => 'PROJECT_NOT_FOUND'],
                404
            );
        }

        $authors = $this->subjectsOfProject((int) $project->id);
        $work = (new ShowcaseClient())->buildWorkFromProject($project, $authors['subjects']);
        $work['memberCount'] = $authors['memberCount'];
        $work['unboundMembers'] = $authors['unbound'];

        return PlatformResponse::ok($work);
    }

    /**
     * 项目成员与 sub 映射（成长档案专用）
     *
     * GET /api/v1/workshop/internal/projects/{id}/members
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function projectMembers(Request $request, $id)
    {
        if ($denied = $this->requireService($request)) {
            return $denied;
        }

        $project = Project::find(intval($id));
        if (!$project) {
            return PlatformResponse::fail(
                PlatformResponse::NOT_FOUND,
                '项目不存在',
                ['reason' => 'PROJECT_NOT_FOUND'],
                404
            );
        }

        $rows = ProjectUser::whereProjectId($project->id)->orderBy('id')->get();
        $members = [];
        foreach ($rows as $row) {
            $subject = $this->subjectOf((int) $row->userid);
            $members[] = [
                'subject' => $subject,
                // 未绑定统一身份的成员必须显式标注，下游据此跳过而不是猜
                'unbound' => $subject === null,
                'role' => (int) $row->owner === 1 ? 'owner' : 'member',
                'joinedAt' => $this->iso($row->created_at),
            ];
        }

        return PlatformResponse::ok([
            'projectId' => (int) $project->id,
            'members' => $members,
        ]);
    }

    /**
     * 请求把项目推送到成果广场（占位实现）
     *
     * POST /api/v1/workshop/internal/projects/{id}/publish
     *
     * 由广场或管理端触发；本平台只做映射与出站调用，不判定"作品是否合格"。
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function publishWork(Request $request, $id)
    {
        if ($denied = $this->requireService($request)) {
            return $denied;
        }

        $project = Project::find(intval($id));
        if (!$project) {
            return PlatformResponse::fail(
                PlatformResponse::NOT_FOUND,
                '项目不存在',
                ['reason' => 'PROJECT_NOT_FOUND'],
                404
            );
        }

        $authors = $this->subjectsOfProject((int) $project->id);
        if (empty($authors['subjects'])) {
            return PlatformResponse::fail(
                PlatformResponse::CONFLICT,
                '项目成员均未绑定统一身份，无法作为作品作者推送',
                ['reason' => 'NO_BOUND_AUTHOR'],
                409
            );
        }

        $client = new ShowcaseClient();
        $work = $client->buildWorkFromProject($project, $authors['subjects']);
        $result = $client->publishWork($work, PlatformResponse::requestId());

        if (!$result['ok']) {
            return PlatformResponse::fail(
                $result['code'] ?: PlatformResponse::SERVICE_UNAVAILABLE,
                $result['message'] ?: '成果广场暂不可用',
                ['reason' => 'SHOWCASE_PUBLISH_FAILED'],
                null
            );
        }

        // 出站成功后记录事件，便于后续对账（本期不投递，DV-11）
        (new PlatformEventService())->enqueue(
            PlatformEventService::EVENT_PROJECT_COMPLETED,
            'project_' . (int) $project->id,
            ['projectId' => (int) $project->id, 'workId' => $result['workId']],
            'service',
            PlatformResponse::requestId()
        );

        return PlatformResponse::ok([
            'projectId' => (int) $project->id,
            'workId' => $result['workId'],
            'status' => $result['status'],
            'mock' => (bool) $result['mock'],
        ], '已推送至成果广场');
    }

    /**
     * 接收考试平台事件（exam.passed / exam.failed）
     *
     * POST /api/v1/workshop/internal/events
     *
     * 封套 10 个必填字段任一缺失即拒收；eventId 幂等（§8.4.2、§8.5）。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function receiveEvent(Request $request)
    {
        if ($denied = $this->requireService($request)) {
            return $denied;
        }

        $envelope = $request->json()->all();
        if (!is_array($envelope) || !$envelope) {
            // 兼容 application/json 之外的情况（联调期常见）
            $envelope = $request->all();
        }

        $result = (new PlatformEventService())->consume($envelope, PlatformResponse::requestId());

        if (!$result['accepted']) {
            return PlatformResponse::fail(
                $result['code'] ?: PlatformResponse::VALIDATION_ERROR,
                $result['message'] ?: '事件被拒收',
                array_filter([
                    'reason' => 'EVENT_REJECTED',
                    'eventId' => $result['eventId'] ?? '',
                    'missing' => $result['missing'] ?? [],
                ]),
                null
            );
        }

        return PlatformResponse::ok([
            'eventId' => $result['eventId'],
            'duplicated' => (bool) $result['duplicated'],
        ], $result['duplicated'] ? '事件已处理（幂等）' : '事件已接收');
    }

    /**
     * 服务态校验（§5.3.2：internal 接口只接受 service 模式）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function requireService(Request $request)
    {
        // 通过内部签名校验的调用视为服务态；X-Auth-Mode 未显式给出时按 service 处理
        $trusted = (bool) $request->attributes->get('gateway.trusted', false);
        if (!$trusted) {
            return PlatformResponse::fail(
                PlatformResponse::FORBIDDEN,
                '内部接口只接受经网关签名的服务调用',
                ['reason' => 'SERVICE_ONLY'],
                403
            );
        }

        $mode = strtolower(trim((string) $request->headers->get('X-Auth-Mode', 'service')));
        if ($mode !== 'service') {
            return PlatformResponse::fail(
                PlatformResponse::FORBIDDEN,
                '内部接口不接受用户态调用',
                ['reason' => 'USER_MODE_REJECTED'],
                403
            );
        }

        return null;
    }

    /**
     * userid → subject（未绑定返回 null，不做任何兜底加工）
     *
     * @param int $userid
     * @return string|null
     */
    private function subjectOf(int $userid): ?string
    {
        if ($userid <= 0) {
            return null;
        }
        // 本地主键即 forum_uid（R1/R2），不再依赖绑定表反查——
        // 否则某行缺绑定会把真实作者误判为 unbound，凭空丢作者。
        $user = User::whereUserid($userid)->first();
        if (!$user) {
            return null;
        }
        return (string) $userid;
    }

    /**
     * 项目成员的 subject 列表
     *
     * @param int $projectId
     * @return array{subjects:array,unbound:int,memberCount:int}
     */
    private function subjectsOfProject(int $projectId): array
    {
        $rows = ProjectUser::whereProjectId($projectId)->orderBy('id')->get();
        $subjects = [];
        $unbound = 0;

        foreach ($rows as $row) {
            $subject = $this->subjectOf((int) $row->userid);
            if ($subject === null) {
                $unbound++;
                continue;
            }
            if (!in_array($subject, $subjects, true)) {
                $subjects[] = $subject;
            }
        }

        return ['subjects' => $subjects, 'unbound' => $unbound, 'memberCount' => $rows->count()];
    }

    /**
     * 项目状态（本平台语义）
     *
     * DooTask 没有独立状态字段，归档即"已完成"；提交是独立动作。
     *
     * @param Project $project
     * @return string
     */
    private function projectStatus(Project $project): string
    {
        if (!empty($project->submitted_at)) {
            return 'submitted';
        }
        if (!empty($project->archived_at)) {
            return 'completed';
        }
        return 'in_progress';
    }

    /**
     * 时间格式：RFC 3339 带时区（§8.2.3）
     *
     * @param mixed $value
     * @return string|null
     */
    private function iso($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->format('c');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
