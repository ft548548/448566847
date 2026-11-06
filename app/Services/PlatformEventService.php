<?php

namespace App\Services;

use App\Models\ExamAdmissionProjection;
use App\Models\ExamEventInbox;
use App\Models\PlatformEventOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 跨平台事件编排（发件箱 + 收件箱）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0：
 *   §8.4.2 封套 10 个必填字段，任一不满足即拒收并告警，不要"尽力解析"
 *   §8.4.3 事件到本地投影的字段映射
 *   §8.5   按 eventId 幂等消费
 *   §10.5.1 消费逻辑：校验 → 查 inbox → 事务（inbox + 投影）→ 异常回滚
 *
 * 出站（本平台 → 广场/档案）本期只落库不投递（DV-11）：enqueue 写 status=0，
 * 投递器接入后按 status=0 拉取，业务代码无需改动。
 */
class PlatformEventService
{
    /** 入站事件目录（§8.4.2 当前只接受这两个） */
    public const INBOUND_TYPES = [
        'exam.passed',
        'exam.failed',
    ];

    /** 出站事件目录（预留，待与广场/档案登记） */
    public const EVENT_PROJECT_COMPLETED = 'project.completed';
    public const EVENT_PROJECT_SUBMITTED = 'project.submitted';
    public const EVENT_RECRUITMENT_CONVERTED = 'recruitment.converted';

    /**
     * 写入发件箱
     *
     * @param string $eventType
     * @param string $sourceEntityId
     * @param array $data
     * @param string $actorId subject 或 system
     * @param string $requestId
     * @param string $correlationId
     * @return PlatformEventOutbox|null
     */
    public function enqueue(
        string $eventType,
        string $sourceEntityId,
        array $data,
        string $actorId = 'system',
        string $requestId = '',
        string $correlationId = ''
    ): ?PlatformEventOutbox {
        try {
            $now = Carbon::now('UTC');
            return PlatformEventOutbox::create([
                'event_id' => $this->newEventId(),
                'event_type' => $eventType,
                'event_version' => '1.0',
                'source_platform' => (string) config('platform.app_code', 'workshop'),
                'source_entity_id' => $sourceEntityId,
                'actor_id' => $actorId !== '' ? $actorId : 'system',
                'correlation_id' => $correlationId !== '' ? $correlationId : $sourceEntityId,
                'request_id' => $requestId,
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => PlatformEventOutbox::STATUS_PENDING,
                'attempts' => 0,
                'occurred_at' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 发件箱写入失败不得阻断业务主流程（本期也没有投递器）
            Log::warning('[event] enqueue failed: ' . $e->getMessage(), [
                'eventType' => $eventType,
                'sourceEntityId' => $sourceEntityId,
            ]);
            return null;
        }
    }

    /**
     * 消费入站事件
     *
     * @param array $envelope
     * @param string $requestId 网关请求的追踪号（与封套里的 requestId 不同：前者是本平台收到请求的号）
     * @return array{accepted:bool,code:string,message:string,eventId:string,duplicated:bool}
     */
    public function consume(array $envelope, string $requestId = ''): array
    {
        $check = $this->validateEnvelope($envelope);
        if (!$check['ok']) {
            Log::warning('[event] envelope rejected', ['missing' => $check['missing'], 'reason' => $check['reason']]);
            return [
                'accepted' => false,
                'code' => PlatformResponse::VALIDATION_ERROR,
                'message' => $check['reason'],
                'eventId' => (string) ($envelope['eventId'] ?? ''),
                'duplicated' => false,
                'missing' => $check['missing'],
            ];
        }

        $eventId = (string) $envelope['eventId'];

        $exists = ExamEventInbox::whereEventId($eventId)->first();
        if ($exists) {
            // 幂等：已收过直接返回成功，不重复更新投影
            return [
                'accepted' => true,
                'code' => PlatformResponse::OK,
                'message' => 'duplicated',
                'eventId' => $eventId,
                'duplicated' => true,
            ];
        }

        try {
            DB::transaction(function () use ($envelope, $eventId, $requestId) {
                ExamEventInbox::create([
                    'event_id' => $eventId,
                    'event_type' => (string) $envelope['eventType'],
                    'event_version' => (string) $envelope['eventVersion'],
                    'source_platform' => (string) $envelope['sourcePlatform'],
                    'source_entity_id' => (string) $envelope['sourceEntityId'],
                    'actor_id' => (string) $envelope['actorId'],
                    'correlation_id' => (string) $envelope['correlationId'],
                    'request_id' => $requestId !== '' ? $requestId : (string) $envelope['requestId'],
                    'occurred_at' => $this->toUtc($envelope['occurredAt']),
                    'received_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                    'status' => ExamEventInbox::STATUS_DONE,
                    'last_error' => '',
                ]);

                $this->project($envelope);
            });
        } catch (\Throwable $e) {
            Log::error('[event] consume failed: ' . $e->getMessage(), ['eventId' => $eventId]);
            return [
                'accepted' => false,
                'code' => PlatformResponse::INTERNAL_ERROR,
                'message' => '事件处理失败',
                'eventId' => $eventId,
                'duplicated' => false,
            ];
        }

        // 事件到达即主动失效缓存，让下一次查询走回源（§10.5.2）
        $this->forgetCache($envelope);

        return [
            'accepted' => true,
            'code' => PlatformResponse::OK,
            'message' => 'accepted',
            'eventId' => $eventId,
            'duplicated' => false,
        ];
    }

    /**
     * 事件 → 投影（§8.4.3）
     *
     * @param array $envelope
     * @return void
     */
    private function project(array $envelope): void
    {
        $data = $envelope['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        // 入站事件的身份字段：优先新契约 forum_uid / uid，兼容旧字段 userId（均为论坛 uid 原值）
        $subject = (string) ($data['forum_uid'] ?? $data['uid'] ?? $data['userId'] ?? '');
        $courseId = (string) ($data['courseId'] ?? '');
        if ($subject === '' || $courseId === '') {
            // 缺关键字段的事件不允许进投影：进了就是一条无法对账的脏数据
            throw new \RuntimeException('事件 data 缺少 forum_uid 或 courseId');
        }

        $eventType = (string) $envelope['eventType'];
        $passed = $eventType === 'exam.passed';
        $occurredAt = $this->toUtc($envelope['occurredAt']);
        $expiresAt = Carbon::parse($occurredAt)->addSeconds(
            min(1800, max(60, (int) config('platform.exam.projection_ttl', 300)))
        )->format('Y-m-d H:i:s');

        $row = ExamAdmissionProjection::whereSubject($subject)->whereCourseId($courseId)->first();

        if ($row && $row->granted_at && strtotime((string) $row->granted_at) > strtotime($occurredAt)) {
            // 唯一索引 (subject, course_id) 只保留一行，以 granted_at 较晚者为准
            return;
        }

        if (!$row) {
            $row = new ExamAdmissionProjection();
            $row->subject = $subject;
            $row->course_id = $courseId;
        }

        $row->eligible = $passed ? 1 : 0;
        $row->reason = $passed ? ExamClient::REASON_PASSED : ExamClient::REASON_FAILED;
        $row->score = isset($data['score']) ? (int) $data['score'] : null;
        $row->rule_version = (string) ($data['ruleVersion'] ?? $data['rule_version'] ?? '');
        $row->granted_at = $occurredAt;
        $row->source = ExamAdmissionProjection::SOURCE_EVENT;
        $row->last_event_id = (string) $envelope['eventId'];
        $row->expires_at = $expiresAt;
        $row->save();
    }

    /**
     * 校验封套 10 个必填字段（§8.4.2）
     *
     * @param array $envelope
     * @return array{ok:bool,reason:string,missing:array}
     */
    private function validateEnvelope(array $envelope): array
    {
        $required = [
            'eventId',
            'eventType',
            'eventVersion',
            'occurredAt',
            'sourcePlatform',
            'sourceEntityId',
            'actorId',
            'correlationId',
            'requestId',
            'data',
        ];

        $missing = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $envelope) || $envelope[$field] === '' || $envelope[$field] === null) {
                $missing[] = $field;
            }
        }
        if ($missing) {
            return ['ok' => false, 'reason' => '封套字段缺失：' . implode(',', $missing), 'missing' => $missing];
        }

        if (!in_array((string) $envelope['eventType'], self::INBOUND_TYPES, true)) {
            return ['ok' => false, 'reason' => '事件类型不在目录内：' . $envelope['eventType'], 'missing' => []];
        }

        if ((string) $envelope['eventVersion'] !== '1.0') {
            return ['ok' => false, 'reason' => '不支持的事件版本：' . $envelope['eventVersion'], 'missing' => []];
        }

        if ((string) $envelope['sourcePlatform'] !== 'exam') {
            return ['ok' => false, 'reason' => 'sourcePlatform 必须为 exam', 'missing' => []];
        }

        if (!is_array($envelope['data'])) {
            return ['ok' => false, 'reason' => 'data 必须是对象', 'missing' => []];
        }

        return ['ok' => true, 'reason' => '', 'missing' => []];
    }

    /**
     * 失效准入缓存（事件到达后）
     *
     * @param array $envelope
     * @return void
     */
    private function forgetCache(array $envelope): void
    {
        try {
            $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            $subject = (string) ($data['forum_uid'] ?? $data['uid'] ?? $data['userId'] ?? '');
            $courseId = (string) ($data['courseId'] ?? '');
            if ($subject !== '' && $courseId !== '') {
                \Illuminate\Support\Facades\Cache::forget('admission:' . $subject . ':' . $courseId);
            }
        } catch (\Throwable $e) {
            // 缓存失效失败不影响消费结果
        }
    }

    /**
     * RFC 3339（UTC）→ DATETIME
     *
     * @param string $value
     * @return string
     */
    private function toUtc(string $value): string
    {
        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return Carbon::now('UTC')->format('Y-m-d H:i:s');
        }
    }

    /**
     * 生成事件 ID（ULID，时间有序便于排查）
     *
     * 不用 `Str::ulid()`：那是 Laravel 9 才有的方法，本项目是 8.48，
     * 调用会抛 BadMethodCallException；而 enqueue() 为了不阻断业务把异常吞掉了，
     * 结果就是"事件一条都没写进发件箱"却毫无报错。这里手写实现。
     *
     * 结构：48 位毫秒时间戳 + 80 位随机数，Crockford Base32 编码为 26 字符。
     * 前缀是时间，因此按 eventId 排序即按发生时间排序。
     *
     * @return string
     */
    private function newEventId(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        // pack('J') 为 64 位大端整数，取后 6 字节得到 48 位毫秒时间戳
        $time = (int) floor(microtime(true) * 1000);
        $binary = substr(pack('J', $time), 2, 6) . random_bytes(10);

        $encoded = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $len = strlen($binary); $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($binary[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bits)) & 0x1F];
        }

        return 'evt_' . strtolower($encoded);
    }
}
