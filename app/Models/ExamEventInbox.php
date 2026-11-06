<?php

namespace App\Models;

/**
 * App\Models\ExamEventInbox
 *
 * 考试事件收件箱（§8.5）——按 eventId 主键去重，承载消费幂等。
 *
 * 封套 10 个必填字段任一缺失即拒收并告警，不要"尽力解析"：
 * 封套不完整的消息一旦入库，后续无法补救（§8.4.2）。
 *
 * @property string $event_id 全局唯一
 * @property string $event_type exam.passed / exam.failed
 * @property string $event_version 当前只接受 1.0
 * @property string $source_platform 必须是 exam
 * @property string $source_entity_id
 * @property string $actor_id
 * @property string $correlation_id
 * @property string $request_id
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property int $status 1 已处理 2 处理失败
 * @property string $last_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ExamEventInbox extends AbstractModel
{
    const STATUS_DONE = 1;
    const STATUS_FAILED = 2;

    protected $table = 'exam_event_inbox';

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'event_type',
        'event_version',
        'source_platform',
        'source_entity_id',
        'actor_id',
        'correlation_id',
        'request_id',
        'occurred_at',
        'received_at',
        'status',
        'last_error',
    ];

    protected $dates = [
        'occurred_at',
        'received_at',
        'created_at',
        'updated_at',
    ];
}
