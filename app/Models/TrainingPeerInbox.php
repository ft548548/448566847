<?php

namespace App\Models;

/**
 * App\Models\TrainingPeerInbox
 *
 * 入站事件收件箱（《三平台对接方案 V2.0》§6.3）
 *
 * (source_platform, event_id) 唯一键是幂等的物理保证：重复投递在数据库层
 * 就插不进去，不依赖应用层"先查后插"这种有竞态的做法。
 *
 * @property int $id
 * @property string $source_platform
 * @property string $event_id
 * @property string $event_type
 * @property string|null $payload
 * @property int $status 1=已处理 2=处理失败
 * @property string $request_id
 * @property string $last_error
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TrainingPeerInbox extends AbstractModel
{
    const STATUS_DONE = 1;
    const STATUS_FAILED = 2;

    protected $table = 'training_peer_inbox';

    protected $fillable = [
        'source_platform',
        'event_id',
        'event_type',
        'payload',
        'status',
        'request_id',
        'last_error',
        'received_at',
    ];

    protected $dates = [
        'received_at',
        'created_at',
        'updated_at',
    ];
}
