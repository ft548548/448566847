<?php

namespace App\Models;

/**
 * App\Models\ProjectRecruitmentApplication
 *
 * 项目大厅 · 报名（pending|approved|rejected|withdrawn）
 *
 * @property int $id
 * @property int|null $recruitment_id 招募ID
 * @property int|null $applicant_id 报名者 userid
 * @property string|null $message 报名留言
 * @property string|null $status 状态：pending|approved|rejected|withdrawn
 * @property int|null $reviewer_id 审批人 userid
 * @property \Illuminate\Support\Carbon|null $reviewed_at 审批时间
 * @property string|null $review_note 审批备注
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProjectRecruitmentApplication extends AbstractModel
{
    // 状态常量
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * 时间字段（timestamp 类型，自动 Carbon 序列化）
     */
    protected $dates = [
        'created_at',
        'updated_at',
        'reviewed_at',
    ];

    /**
     * 报名者（User）
     */
    public function applicant(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'userid', 'applicant_id');
    }
}
