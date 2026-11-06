<?php

namespace App\Models;

/**
 * App\Models\PlatformEventOutbox
 *
 * 平台事件发件箱（§8.4、DV-11）
 *
 * 本期只落库不投递：业务侧 enqueue 后 status=0，投递器接入后按 status=0 拉取。
 * 这样"事件生产"与"事件投递"解耦，接投递器时无需改业务代码。
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string $event_version
 * @property string $source_platform
 * @property string $source_entity_id
 * @property string $actor_id
 * @property string $correlation_id
 * @property string $request_id
 * @property string|null $payload JSON
 * @property int $status 0 待投递 1 已投递 2 投递失败
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property string $last_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PlatformEventOutbox extends AbstractModel
{
    const STATUS_PENDING = 0;
    const STATUS_DELIVERED = 1;
    const STATUS_FAILED = 2;

    protected $table = 'platform_event_outbox';

    protected $fillable = [
        'event_id',
        'event_type',
        'event_version',
        'source_platform',
        'source_entity_id',
        'actor_id',
        'correlation_id',
        'request_id',
        'payload',
        'status',
        'attempts',
        'occurred_at',
        'delivered_at',
        'last_error',
    ];

    protected $dates = [
        'occurred_at',
        'delivered_at',
        'created_at',
        'updated_at',
    ];
}
