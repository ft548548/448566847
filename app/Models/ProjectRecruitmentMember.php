<?php

namespace App\Models;

/**
 * App\Models\ProjectRecruitmentMember
 *
 * 项目大厅 · 队员（active|removed|exited）
 *
 * @property int $id
 * @property int|null $recruitment_id 招募ID
 * @property int|null $userid 队员 userid
 * @property string|null $status 状态：active|removed|exited
 * @property \Illuminate\Support\Carbon|null $joined_at 入队时间
 * @property \Illuminate\Support\Carbon|null $left_at 退出/移除时间
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProjectRecruitmentMember extends AbstractModel
{
    // 状态常量
    const STATUS_ACTIVE = 'active';
    const STATUS_REMOVED = 'removed';
    const STATUS_EXITED = 'exited';

    /**
     * 时间字段（timestamp 类型，自动 Carbon 序列化）
     */
    protected $dates = [
        'created_at',
        'updated_at',
        'joined_at',
        'left_at',
    ];

    /**
     * 队员（User）
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'userid', 'userid');
    }
}
