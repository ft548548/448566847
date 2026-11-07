<?php

namespace App\Models;

/**
 * App\Models\TrainingPeerOutbox
 *
 * 出站发件箱（《三平台对接方案 V2.0》§6.1）
 *
 * 业务事务内只写本表（status=0），由 peer:dispatch 定时投递。
 * 前置业务行为不会因为下游不可用而回滚——这正是发件箱模式的意义。
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string $target_platform
 * @property string|null $payload
 * @property int $status 0=待投递 1=已投递 2=死信
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $next_attempt_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property string $last_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TrainingPeerOutbox extends AbstractModel
{
    const STATUS_PENDING = 0;
    const STATUS_DELIVERED = 1;
    const STATUS_DEAD = 2;

    protected $table = 'training_peer_outbox';

    protected $fillable = [
        'event_id',
        'event_type',
        'target_platform',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'delivered_at',
        'last_error',
    ];

    protected $dates = [
        'next_attempt_at',
        'delivered_at',
        'created_at',
        'updated_at',
    ];
}
