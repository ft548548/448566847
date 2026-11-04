<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\AbstractModel;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectRecruitment;
use App\Models\ProjectRecruitmentApplication;
use App\Models\ProjectRecruitmentMember;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Module\Base;
use Carbon\Carbon;
use Request;

/**
 * 项目大厅（招募域）
 *
 * 领域边界：
 *   - 招募阶段独立于项目立项：不写 ProjectUser、不建项目，
 *     因此不影响既有 project 模块的任何约束。
 *   - 成队「转正」时，才由发布者把招募映射进 Project（建项目 + 加成员）。
 *
 * 权限口径（与 Dootask 既有约定一致）：
 *   - 发布/报名/浏览只要求登录（User::auth() 无参）。
 *   - 管理（审批/加人/踢人/状态流转/转正）只认 publisher_id ——
 *     管理员若非发布者，同样不能管理别人的招募。
 */
class RecruitmentController extends AbstractController
{
    /**
     * 状态机：允许的流转目标
     */
    const TRANSITIONS = [
        ProjectRecruitment::STATUS_RECRUITING => [
            ProjectRecruitment::STATUS_FULL,
            ProjectRecruitment::STATUS_IN_PROGRESS,
            ProjectRecruitment::STATUS_CLOSED,
        ],
        ProjectRecruitment::STATUS_FULL => [
            ProjectRecruitment::STATUS_RECRUITING,
            ProjectRecruitment::STATUS_IN_PROGRESS,
            ProjectRecruitment::STATUS_CLOSED,
        ],
        ProjectRecruitment::STATUS_IN_PROGRESS => [
            ProjectRecruitment::STATUS_COMPLETED,
            ProjectRecruitment::STATUS_CLOSED,
        ],
        ProjectRecruitment::STATUS_COMPLETED => [],
        ProjectRecruitment::STATUS_CLOSED => [],
    ];

    /**
     * 报名状态机：允许的流转目标
     */
    const APPLICATION_TRANSITIONS = [
        ProjectRecruitmentApplication::STATUS_PENDING => [
            ProjectRecruitmentApplication::STATUS_APPROVED,
            ProjectRecruitmentApplication::STATUS_REJECTED,
            ProjectRecruitmentApplication::STATUS_WITHDRAWN,
        ],
        ProjectRecruitmentApplication::STATUS_APPROVED => [],
        ProjectRecruitmentApplication::STATUS_REJECTED => [
            ProjectRecruitmentApplication::STATUS_PENDING,
        ],
        ProjectRecruitmentApplication::STATUS_WITHDRAWN => [
            ProjectRecruitmentApplication::STATUS_PENDING,
        ],
    ];

    /**
     * 校验状态流转合法性，非法则抛异常
     */
    private function assertTransition($from, $to, array $transitions)
    {
        if ($from === $to) {
            return;
        }
        $allowed = $transitions[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new ApiException("非法状态流转：{$from} -> {$to}", [], -1);
        }
    }

    /**
     * 加载招募，并校验当前用户是发布者（数据范围守卫）
     * 只认 publisher_id，管理员也不例外。
     */
    private function assertPublisher(User $user, $recruitmentId): ProjectRecruitment
    {
        $recruitment = ProjectRecruitment::find($recruitmentId);
        if (!$recruitment) {
            throw new ApiException('招募不存在', [], -1);
        }
        if (intval($recruitment->publisher_id) !== intval($user->userid)) {
            throw new ApiException('只有发布者可以执行此操作', [], -1);
        }
        return $recruitment;
    }

    /**
     * 序列化招募（skills JSON 转数组，附带成员数与当前用户报名状态）
     */
    private function serialize(ProjectRecruitment $recruitment, User $user)
    {
        $data = $recruitment->toArray();
        $skills = Base::json2array($recruitment->skills);
        $data['skills'] = is_array($skills) ? $skills : [];
        // 发布者昵称
        $publisher = User::find($recruitment->publisher_id);
        $data['publisher_nickname'] = $publisher ? $publisher->nickname : '';
        // 在队人数（active 成员）
        $data['member_count'] = ProjectRecruitmentMember::whereRecruitmentId($recruitment->id)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->count();
        // 当前用户是否已报名
        $myApp = ProjectRecruitmentApplication::whereRecruitmentId($recruitment->id)
            ->whereApplicantId($user->userid)
            ->first();
        $data['my_application'] = $myApp ? $myApp->status : null;
        // 当前用户是否已入队
        $data['is_member'] = ProjectRecruitmentMember::whereRecruitmentId($recruitment->id)
            ->whereUserid($user->userid)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->exists();
        // 来源项目信息（用户把项目加入招募大厅时展示）
        if (intval($recruitment->source_project_id) > 0) {
            $sourceProject = Project::find($recruitment->source_project_id);
            if ($sourceProject) {
                $sourceOwner = User::find($sourceProject->owner_userid);
                $data['source_project'] = [
                    'id' => $sourceProject->id,
                    'name' => $sourceProject->name,
                    'owner_userid' => intval($sourceProject->owner_userid),
                    'owner_nickname' => $sourceOwner ? $sourceOwner->nickname : '',
                    // 当前用户是否为来源项目成员（决定「去项目/进入项目群聊」入口是否可见）
                    'is_source_member' => ProjectUser::whereProjectId($sourceProject->id)
                        ->whereUserid($user->userid)
                        ->exists(),
                ];
            } else {
                $data['source_project'] = null;
            }
        } else {
            $data['source_project'] = null;
        }
        return $data;
    }

    /**
     * @api {post} api/recruitment/add          01. 发布项目招募（统一入口）
     *
     * @apiDescription 需要token身份。合并原「发布招募」与「添加项目」：
     *   招募必须先关联项目（新建项目 或 选已有项目），二者共享同一项目数据。
     *
     * @apiParam {String} project_mode       项目来源：create（新建项目）| existing（选已有项目）
     * @apiParam {String} [name]             project_mode=create 时必填，项目名称（2~32字，同时作为招募标题）
     * @apiParam {String} [desc]             project_mode=create 时可选，项目介绍（≤255字，同时作为招募简介）
     * @apiParam {Number} [project_id]       project_mode=existing 时必填，已参与项目的ID
     * @apiParam {String} [title]            招募标题（默认取项目名称，2~32字）
     * @apiParam {String} [description]      招募简介（默认取项目描述）
     * @apiParam {Array|String} [skills]     所需技能标签（1~8个）
     * @apiParam {Number} headcount          需招募的队员人数（1~100）
     * @apiParam {String} [deadline]         报名截止时间
     */
    public function add()
    {
        $user = User::auth();
        $projectMode = trim(Request::input('project_mode', 'create'));

        // ── 第一步：确定关联项目（新建 or 选已有）────────────────────────
        $project = null;
        if ($projectMode === 'create') {
            $name = trim(Request::input('name', ''));
            $desc = trim(Request::input('desc', ''));
            if (mb_strlen($name) < 2) {
                return Base::retError('项目名称不可以少于2个字');
            }
            if (mb_strlen($name) > 32) {
                return Base::retError('项目名称最多只能设置32个字');
            }
            if (mb_strlen($desc) > 255) {
                return Base::retError('项目介绍最多只能设置255个字');
            }
            $project = $this->createProject($user, $name, $desc);
        } elseif ($projectMode === 'existing') {
            $projectId = intval(Request::input('project_id', 0));
            if ($projectId <= 0) {
                return Base::retError('请选择项目');
            }
            $project = Project::whereId($projectId)->first();
            if (!$project) {
                return Base::retError('项目不存在');
            }
            // 成员校验：仅项目成员可将该项目加入招募大厅（避免越权枚举他人项目）
            $isMember = ProjectUser::whereProjectId($projectId)
                ->whereUserid($user->userid)
                ->exists();
            if (!$isMember) {
                return Base::retError('只有项目成员可以发布该项目的招募');
            }
        } else {
            return Base::retError('project_mode 参数无效');
        }

        // 一个项目只能发布一条招募（按 source_project_id 去重）
        if (ProjectRecruitment::whereSourceProjectId($project->id)->exists()) {
            return Base::retError('该项目已在招募大厅中');
        }

        // ── 第二步：招募信息（默认从项目带出，避免重复录入）──────────────
        $title = trim(Request::input('title', ''));
        $description = trim(Request::input('description', ''));
        $headcount = intval(Request::input('headcount', 0));

        // 标题/简介默认取项目数据
        if ($title === '') {
            $title = $project->name;
        }
        if ($description === '') {
            $description = $project->desc;
        }
        if (mb_strlen($title) < 2) {
            return Base::retError('招募标题不可以少于2个字');
        }
        if (mb_strlen($title) > 32) {
            $title = mb_substr($title, 0, 32);
        }
        // 招募人数默认取项目当前成员数（至少1人）
        if ($headcount <= 0) {
            $memberNum = ProjectUser::whereProjectId($project->id)->count();
            $headcount = max(1, min(100, $memberNum));
        }
        if ($headcount < 1 || $headcount > 100) {
            return Base::retError('招募人数需在 1~100 之间');
        }

        // 技能标签：兼容数组或逗号分隔字符串，去重清洗，1~8 个
        $rawSkills = Request::input('skills', []);
        if (is_string($rawSkills)) {
            $rawSkills = explode(',', $rawSkills);
        }
        $skills = [];
        foreach ((array)$rawSkills as $skill) {
            $skill = trim((string)$skill);
            if ($skill && !in_array($skill, $skills, true)) {
                $skills[] = $skill;
            }
        }
        if (count($skills) > 8) {
            return Base::retError('技能标签最多只能设置8个');
        }

        $deadline = trim(Request::input('deadline', ''));

        // 创建招募（publisher=操作者，source_project_id 回填来源项目）
        $recruitment = ProjectRecruitment::createInstance([
            'title' => $title,
            'description' => $description,
            'skills' => Base::array2json($skills),
            'headcount' => $headcount,
            'status' => ProjectRecruitment::STATUS_RECRUITING,
            'deadline' => $deadline ?: null,
            'publisher_id' => $user->userid,
            'source_project_id' => $project->id,
        ]);
        $recruitment->save();

        // 项目日志
        $project->addLog("项目发布招募「{$title}」");

        return Base::retSuccess('发布成功', $this->serialize($recruitment, $user));
    }

    /**
     * 新建项目（复用 project/add 的核心逻辑，简化版）
     */
    private function createProject(User $user, $name, $desc): Project
    {
        $project = Project::createInstance([
            'name' => $name,
            'desc' => $desc,
            'userid' => $user->userid,
        ]);
        AbstractModel::transaction(function () use ($project) {
            $project->save();
            ProjectUser::createInstance([
                'project_id' => $project->id,
                'userid' => $project->userid,
                'owner' => 1,
            ])->save();
            // 默认列表
            ProjectColumn::createInstance([
                'project_id' => $project->id,
                'name' => 'Default',
                'sort' => 0,
            ])->save();
            // 项目群聊
            $dialog = WebSocketDialog::createGroup(null, $project->userid, 'project');
            if (empty($dialog)) {
                throw new ApiException('创建项目聊天室失败');
            }
            $project->dialog_id = $dialog->id;
            $project->save();
        });
        $project->addLog("创建项目");
        return $project;
    }

    /**
     * @api {get} api/recruitment/lists         02. 项目大厅列表
     *
     * @apiDescription 需要token身份。支持状态/技能/关键词筛选 + 分页
     * @apiParam {String} [status]   状态筛选
     * @apiParam {String} [skill]    技能筛选
     * @apiParam {String} [keyword]  关键词（标题/简介）
     */
    public function lists()
    {
        $user = User::auth();
        $status = trim(Request::input('status', ''));
        $skill = trim(Request::input('skill', ''));
        $keyword = trim(Request::input('keyword', ''));

        $builder = ProjectRecruitment::query();
        if ($status) {
            $builder->whereStatus($status);
        }
        if ($keyword) {
            $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }
        $list = $builder->orderByDesc('id')->paginate(Base::getPaginate(100, 50));
        // 技能筛选在 PHP 侧过滤（JSON 字段不易在 SQL 里精确匹配）
        if ($skill) {
            $filtered = $list->getCollection()->filter(function (ProjectRecruitment $r) use ($skill) {
                $skills = Base::json2array($r->skills);
                return is_array($skills) && in_array($skill, $skills, true);
            });
            $list->setCollection($filtered->values());
        }
        $list->transform(function (ProjectRecruitment $r) use ($user) {
            return $this->serialize($r, $user);
        });

        return Base::retSuccess('success', $list->toArray());
    }

    /**
     * @api {get} api/recruitment/one           03. 招募详情
     *
     * @apiDescription 需要token身份。含队伍成员与报名列表（发布者可见全部报名）
     * @apiParam {Number} recruitment_id  招募ID
     */
    public function one()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));
        $recruitment = ProjectRecruitment::find($recruitmentId);
        if (!$recruitment) {
            return Base::retError('招募不存在');
        }

        $data = $this->serialize($recruitment, $user);

        // 队员列表
        $data['members'] = ProjectRecruitmentMember::whereRecruitmentId($recruitmentId)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(function (ProjectRecruitmentMember $m) {
                return [
                    'id' => $m->id,
                    'userid' => $m->userid,
                    'nickname' => $m->user ? $m->user->nickname : '',
                    'userimg' => $m->user ? $m->user->userimg : '',
                    'joined_at' => $m->joined_at,
                ];
            });

        // 报名列表：发布者可见全部，其他人仅见自己的
        $isPublisher = intval($recruitment->publisher_id) === intval($user->userid);
        $appBuilder = ProjectRecruitmentApplication::whereRecruitmentId($recruitmentId)
            ->with('applicant');
        if (!$isPublisher) {
            $appBuilder->whereApplicantId($user->userid);
        }
        $data['applications'] = $appBuilder->orderBy('id')->get()->map(function (ProjectRecruitmentApplication $a) {
            return [
                'id' => $a->id,
                'applicant_id' => $a->applicant_id,
                'nickname' => $a->applicant ? $a->applicant->nickname : '',
                'message' => $a->message,
                'status' => $a->status,
                'review_note' => $a->review_note,
                'created_at' => $a->created_at,
            ];
        });

        $data['is_publisher'] = $isPublisher;

        return Base::retSuccess('success', $data);
    }

    /**
     * @api {post} api/recruitment/apply        04. 报名
     *
     * @apiDescription 需要token身份。满员/关闭/完成/过截止/重复/自报均拒绝
     * @apiParam {Number} recruitment_id  招募ID
     * @apiParam {String} [message]       报名留言
     */
    public function apply()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));
        $message = trim(Request::input('message', ''));

        $recruitment = ProjectRecruitment::find($recruitmentId);
        if (!$recruitment) {
            return Base::retError('招募不存在');
        }
        // 发布者本人不能报名
        if (intval($recruitment->publisher_id) === intval($user->userid)) {
            return Base::retError('发布者不能报名自己的招募');
        }
        // 状态：仅 recruiting 可报名
        if ($recruitment->status !== ProjectRecruitment::STATUS_RECRUITING) {
            return Base::retError('当前招募不可报名');
        }
        // 截止时间
        if ($recruitment->deadline && time() > strtotime($recruitment->deadline)) {
            return Base::retError('报名已截止');
        }
        // 是否已在队
        $inTeam = ProjectRecruitmentMember::whereRecruitmentId($recruitmentId)
            ->whereUserid($user->userid)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->exists();
        if ($inTeam) {
            return Base::retError('你已在队伍中');
        }
        // 重复报名：在办（pending/approved）拒绝，终态（rejected/withdrawn）复位
        $app = ProjectRecruitmentApplication::whereRecruitmentId($recruitmentId)
            ->whereApplicantId($user->userid)
            ->first();
        if ($app) {
            if (in_array($app->status, [
                ProjectRecruitmentApplication::STATUS_PENDING,
                ProjectRecruitmentApplication::STATUS_APPROVED,
            ], true)) {
                return Base::retError('你已报名，请勿重复提交');
            }
            // 终态复位为 pending
            $app->status = ProjectRecruitmentApplication::STATUS_PENDING;
            $app->message = $message;
            $app->reviewer_id = 0;
            $app->reviewed_at = null;
            $app->review_note = '';
            $app->save();
            return Base::retSuccess('报名成功', $app->toArray());
        }

        $app = ProjectRecruitmentApplication::createInstance([
            'recruitment_id' => $recruitmentId,
            'applicant_id' => $user->userid,
            'message' => $message,
            'status' => ProjectRecruitmentApplication::STATUS_PENDING,
        ]);
        $app->save();

        return Base::retSuccess('报名成功', $app->toArray());
    }

    /**
     * @api {post} api/recruitment/review/apply 05. 审批报名（通过/拒绝）
     *
     * @apiDescription 仅发布者。通过即入队并触发满员判定
     * @apiParam {Number} recruitment_id  招募ID
     * @apiParam {Number} application_id  报名ID
     * @apiParam {String} result          approve|reject
     * @apiParam {String} [note]          审批备注
     */
    public function review__apply()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));
        $applicationId = intval(Request::input('application_id', 0));
        $result = trim(Request::input('result', ''));
        $note = trim(Request::input('note', ''));

        $recruitment = $this->assertPublisher($user, $recruitmentId);
        $app = ProjectRecruitmentApplication::whereId($applicationId)
            ->whereRecruitmentId($recruitmentId)
            ->first();
        if (!$app) {
            return Base::retError('报名记录不存在');
        }

        if ($result === 'approve') {
            $this->assertTransition($app->status, ProjectRecruitmentApplication::STATUS_APPROVED, self::APPLICATION_TRANSITIONS);
            $app->status = ProjectRecruitmentApplication::STATUS_APPROVED;
            $app->reviewer_id = $user->userid;
            $app->reviewed_at = Carbon::now();
            $app->review_note = $note;
            $app->save();
            // 入队
            $this->addMember($recruitment, $app->applicant_id);
            // 满员判定
            $this->syncFullStatus($recruitment);
            return Base::retSuccess('已通过并加入队伍');
        }

        if ($result === 'reject') {
            $this->assertTransition($app->status, ProjectRecruitmentApplication::STATUS_REJECTED, self::APPLICATION_TRANSITIONS);
            $app->status = ProjectRecruitmentApplication::STATUS_REJECTED;
            $app->reviewer_id = $user->userid;
            $app->reviewed_at = Carbon::now();
            $app->review_note = $note;
            $app->save();
            return Base::retSuccess('已拒绝');
        }

        return Base::retError('result 参数无效');
    }

    /**
     * @api {post} api/recruitment/member/add   06. 加人（发布者直接加人）
     *
     * @apiDescription 仅发布者
     * @apiParam {Number} recruitment_id  招募ID
     * @apiParam {Number} userid          要加入的 userid
     */
    public function member__add()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));
        $targetUserid = intval(Request::input('userid', 0));

        $recruitment = $this->assertPublisher($user, $recruitmentId);
        if ($targetUserid <= 0) {
            return Base::retError('userid 参数无效');
        }
        if ($targetUserid === intval($user->userid)) {
            return Base::retError('发布者已在队伍中');
        }
        $this->addMember($recruitment, $targetUserid);
        $this->syncFullStatus($recruitment);
        return Base::retSuccess('添加成功');
    }

    /**
     * @api {post} api/recruitment/member/remove 07. 踢人
     *
     * @apiDescription 仅发布者。踢人后人数回落触发 full -> recruiting
     * @apiParam {Number} recruitment_id  招募ID
     * @apiParam {Number} member_id       成员关系ID
     */
    public function member__remove()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));
        $memberId = intval(Request::input('member_id', 0));

        $recruitment = $this->assertPublisher($user, $recruitmentId);
        $member = ProjectRecruitmentMember::whereId($memberId)
            ->whereRecruitmentId($recruitmentId)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->first();
        if (!$member) {
            return Base::retError('成员不存在');
        }
        $member->status = ProjectRecruitmentMember::STATUS_REMOVED;
        $member->left_at = Carbon::now();
        $member->save();

        // 人数回落：full -> recruiting
        if ($recruitment->status === ProjectRecruitment::STATUS_FULL) {
            $recruitment->status = ProjectRecruitment::STATUS_RECRUITING;
            $recruitment->save();
        }
        return Base::retSuccess('移除成功');
    }

    /**
     * @api {post} api/recruitment/status       08. 状态流转
     *
     * @apiDescription 仅发布者。action: start|complete|close
     * @apiParam {Number} recruitment_id  招募ID
     * @apiParam {String} action          start|complete|close
     * @apiParam {String} [reason]        关闭原因
     */
    public function status()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));
        $action = trim(Request::input('action', ''));
        $reason = trim(Request::input('reason', ''));

        $recruitment = $this->assertPublisher($user, $recruitmentId);

        switch ($action) {
            case 'start':
                $this->assertTransition($recruitment->status, ProjectRecruitment::STATUS_IN_PROGRESS, self::TRANSITIONS);
                $recruitment->status = ProjectRecruitment::STATUS_IN_PROGRESS;
                $recruitment->started_at = Carbon::now();
                break;
            case 'complete':
                $this->assertTransition($recruitment->status, ProjectRecruitment::STATUS_COMPLETED, self::TRANSITIONS);
                $recruitment->status = ProjectRecruitment::STATUS_COMPLETED;
                $recruitment->completed_at = Carbon::now();
                break;
            case 'close':
                $this->assertTransition($recruitment->status, ProjectRecruitment::STATUS_CLOSED, self::TRANSITIONS);
                $recruitment->status = ProjectRecruitment::STATUS_CLOSED;
                $recruitment->closed_at = Carbon::now();
                $recruitment->close_reason = $reason;
                break;
            default:
                return Base::retError('action 参数无效');
        }
        $recruitment->save();

        return Base::retSuccess('操作成功', $this->serialize($recruitment, $user));
    }

    /**
     * @api {post} api/recruitment/remove      09b. 删除招募
     *
     * @apiDescription 仅发布者。软删除招募，同时清空成员与报名记录
     * @apiParam {Number} recruitment_id  招募ID
     */
    public function remove()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));

        $recruitment = $this->assertPublisher($user, $recruitmentId);

        AbstractModel::transaction(function () use ($recruitment) {
            // 清空成员与报名（软删除关联数据，保证数据一致性）
            ProjectRecruitmentMember::whereRecruitmentId($recruitment->id)->delete();
            ProjectRecruitmentApplication::whereRecruitmentId($recruitment->id)->delete();
            // 软删除招募主体
            $recruitment->delete();
        });

        return Base::retSuccess('删除成功');
    }

    /**
     * @api {post} api/recruitment/convert      09. 成队转正
     *
     * @apiDescription 仅发布者。转正 = 新建项目 + 把招募成员写入项目成员
     * @apiParam {Number} recruitment_id  招募ID
     * @apiParam {String} name            项目名称
     */
    public function convert()
    {
        $user = User::auth();
        $recruitmentId = intval(Request::input('recruitment_id', 0));
        $name = trim(Request::input('name', ''));

        $recruitment = $this->assertPublisher($user, $recruitmentId);

        // 已转正不可重复
        if (intval($recruitment->project_id) > 0) {
            return Base::retError('该项目已转正');
        }
        // 仅 recruiting / full 可转正
        if (!in_array($recruitment->status, [
            ProjectRecruitment::STATUS_RECRUITING,
            ProjectRecruitment::STATUS_FULL,
        ], true)) {
            return Base::retError('当前状态不可转正');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 32) {
            return Base::retError('项目名称需在 2~32 字之间');
        }

        // 队员（active）
        $members = ProjectRecruitmentMember::whereRecruitmentId($recruitmentId)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->get();

        $project = null;
        AbstractModel::transaction(function () use (&$project, $recruitment, $user, $name, $members) {
            // 1. 建项目（复用既有项目模型，创建者即发布者）
            $project = Project::createInstance([
                'name' => $name,
                'desc' => $recruitment->description,
                'userid' => $user->userid,
            ]);
            $project->save();

            // 2. 发布者作为项目负责人
            ProjectUser::createInstance([
                'project_id' => $project->id,
                'userid' => $user->userid,
                'owner' => 1,
            ])->save();

            // 3. 招募成员一并入项目（普通成员）
            foreach ($members as $member) {
                if (intval($member->userid) === intval($user->userid)) {
                    continue;
                }
                ProjectUser::createInstance([
                    'project_id' => $project->id,
                    'userid' => $member->userid,
                    'owner' => 0,
                ])->save();
            }

            // 4. 回填招募 -> 项目指针 + 置 in_progress
            $recruitment->project_id = $project->id;
            $recruitment->status = ProjectRecruitment::STATUS_IN_PROGRESS;
            $recruitment->started_at = Carbon::now();
            $recruitment->save();
        });

        // 项目日志
        $project->addLog("由项目大厅「{$recruitment->title}」转正立项");

        return Base::retSuccess('转正成功', [
            'project_id' => $project->id,
            'recruitment_id' => $recruitmentId,
        ]);
    }

    // ── 私有辅助 ─────────────────────────────────────────────

    /**
     * 把某用户加入招募队伍（幂等：已在队则跳过）
     */
    private function addMember(ProjectRecruitment $recruitment, $userid)
    {
        $userid = intval($userid);
        $exists = ProjectRecruitmentMember::whereRecruitmentId($recruitment->id)
            ->whereUserid($userid)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->exists();
        if ($exists) {
            return;
        }
        // 终态记录复用复位
        $member = ProjectRecruitmentMember::whereRecruitmentId($recruitment->id)
            ->whereUserid($userid)
            ->first();
        if ($member) {
            $member->status = ProjectRecruitmentMember::STATUS_ACTIVE;
            $member->joined_at = Carbon::now();
            $member->left_at = null;
            $member->save();
            return;
        }
        ProjectRecruitmentMember::createInstance([
            'recruitment_id' => $recruitment->id,
            'userid' => $userid,
            'status' => ProjectRecruitmentMember::STATUS_ACTIVE,
            'joined_at' => Carbon::now(),
        ])->save();
    }

    /**
     * 满员判定：active 成员数达到 headcount -> full；未满 -> recruiting（仅当当前为 recruiting/full）
     */
    private function syncFullStatus(ProjectRecruitment $recruitment)
    {
        if (!in_array($recruitment->status, [
            ProjectRecruitment::STATUS_RECRUITING,
            ProjectRecruitment::STATUS_FULL,
        ], true)) {
            return;
        }
        $count = ProjectRecruitmentMember::whereRecruitmentId($recruitment->id)
            ->whereStatus(ProjectRecruitmentMember::STATUS_ACTIVE)
            ->count();
        if ($count >= intval($recruitment->headcount)) {
            if ($recruitment->status !== ProjectRecruitment::STATUS_FULL) {
                $recruitment->status = ProjectRecruitment::STATUS_FULL;
                $recruitment->save();
            }
        } else {
            if ($recruitment->status !== ProjectRecruitment::STATUS_RECRUITING) {
                $recruitment->status = ProjectRecruitment::STATUS_RECRUITING;
                $recruitment->save();
            }
        }
    }
}
