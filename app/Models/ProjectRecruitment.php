<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\Models\ProjectRecruitment
 *
 * 项目大厅 · 招募主体（独立招募域）
 *
 * @property int $id
 * @property string|null $title 招募标题
 * @property string|null $description 招募简介
 * @property string|null $skills 所需技能（JSON 数组字符串）
 * @property int|null $headcount 需招募的队员人数（不含发起人）
 * @property string|null $status 状态：recruiting|full|in_progress|completed|closed
 * @property \Illuminate\Support\Carbon|null $deadline 报名截止时间
 * @property \Illuminate\Support\Carbon|null $started_at 成队转正时间
 * @property \Illuminate\Support\Carbon|null $completed_at 完成时间
 * @property \Illuminate\Support\Carbon|null $closed_at 关闭时间
 * @property string|null $close_reason 关闭原因
 * @property int|null $project_id 成队转正后回填的项目ID
 * @property int|null $source_project_id 来源项目ID（用户把自己创建的项目加入招募大厅时回填）
 * @property int|null $publisher_id 发布者 userid
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class ProjectRecruitment extends AbstractModel
{
    use SoftDeletes;

    // 状态常量
    const STATUS_RECRUITING = 'recruiting';
    const STATUS_FULL = 'full';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CLOSED = 'closed';

    protected $hidden = [
        'deleted_at',
    ];

    /**
     * 时间字段（timestamp 类型，自动 Carbon 序列化）
     */
    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
        'deadline',
        'started_at',
        'completed_at',
        'closed_at',
    ];

    /**
     * 发布者（User）
     */
    public function publisher(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'userid', 'publisher_id');
    }

    /**
     * 队员
     */
    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectRecruitmentMember::class, 'recruitment_id', 'id');
    }

    /**
     * 报名
     */
    public function applications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectRecruitmentApplication::class, 'recruitment_id', 'id');
    }
}
