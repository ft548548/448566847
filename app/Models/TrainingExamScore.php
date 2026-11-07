<?php

namespace App\Models;

/**
 * App\Models\TrainingExamScore
 *
 * 考试成绩库（《三平台对接方案 V2.0》§7）
 *
 * 只从考试平台流入，自学平台不接收成绩。唯一键 (subject, exam_id, task_id)
 * 实现"最新覆盖"：补考后新成绩覆盖旧行，闸门每次读到的都是最新一次结果。
 *
 * @property int $id
 * @property string $subject 十进制 uid
 * @property string $exam_id
 * @property string $task_id
 * @property string $course_id
 * @property int|null $score
 * @property int $max_score
 * @property bool $passed
 * @property \Illuminate\Support\Carbon|null $taken_at
 * @property string $rule_version
 * @property string $event_id
 * @property string $source_platform
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TrainingExamScore extends AbstractModel
{
    protected $table = 'training_exam_scores';

    protected $fillable = [
        'subject',
        'exam_id',
        'task_id',
        'course_id',
        'score',
        'max_score',
        'passed',
        'taken_at',
        'rule_version',
        'event_id',
        'source_platform',
    ];

    protected $casts = [
        'score' => 'integer',
        'max_score' => 'integer',
        'passed' => 'boolean',
    ];

    protected $dates = [
        'taken_at',
        'created_at',
        'updated_at',
    ];
}
