<?php

namespace App\Models;

/**
 * App\Models\TrainingTaskBinding
 *
 * 实训任务勾选绑定（《三平台对接方案 V2.0》§3.1）
 *
 * 一行 = 一个任务要求的一门课程 + 一门考试。实训平台是这份绑定的唯一权威，
 * 通过 training.task.published 推给自学与考试平台；下游只读缓存，不回写。
 *
 * @property int $id
 * @property string $task_id
 * @property string $course_id 纯数字串，不带 course_ 前缀
 * @property string $exam_id 形如 exam_2
 * @property string $rule_version
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TrainingTaskBinding extends AbstractModel
{
    protected $table = 'training_task_bindings';

    protected $fillable = [
        'task_id',
        'course_id',
        'exam_id',
        'rule_version',
    ];
}
