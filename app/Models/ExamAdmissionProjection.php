<?php

namespace App\Models;

/**
 * App\Models\ExamAdmissionProjection
 *
 * 考试准入投影（§10.4.1）
 *
 * **只加速，不拒绝**：投影可能因事件丢失而不准，拒绝用户必须有实时回源结论。
 * expires_at 到点即失效，过期视为未命中并走回源。
 *
 * @property int $id
 * @property string $subject 论坛 sub 原值
 * @property string $course_id 课程ID，原值存储
 * @property int $eligible 1 通过 0 未通过
 * @property string $reason 对齐考试平台 reason 枚举
 * @property int|null $score 成绩，仅展示
 * @property string $rule_version 规则版本
 * @property \Illuminate\Support\Carbon|null $granted_at 结论时间（UTC）
 * @property int $source 1 回源 2 事件
 * @property string $last_event_id 最近生效的 eventId
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ExamAdmissionProjection extends AbstractModel
{
    const SOURCE_REMOTE = 1;
    const SOURCE_EVENT = 2;

    protected $table = 'exam_admission_projection';

    protected $fillable = [
        'subject',
        'course_id',
        'eligible',
        'reason',
        'score',
        'rule_version',
        'granted_at',
        'source',
        'last_event_id',
        'expires_at',
    ];

    protected $dates = [
        'granted_at',
        'expires_at',
        'created_at',
        'updated_at',
    ];
}
