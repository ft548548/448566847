<?php

namespace App\Services;

use App\Models\TrainingExamScore;
use App\Models\TrainingPeerInbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 三平台入站事件消费（《三平台对接方案 V2.0》§4.1、§6.3、§7）
 *
 * 本平台只接收一种事件：exam.result.published（考试平台 → 实训平台）。
 *
 * 与 V3.0 的 PlatformEventService 是两套契约，故不复用：
 *   V3.0 封套要求 10 个字段（含 actorId / correlationId / sourceEntityId），
 *   三平台 V2.0 封套只有 6 个。用旧校验会把合法的新事件全部拒掉。
 *
 * 幂等与覆盖：
 *   -(source_platform, event_id) 唯一键挡住重复投递；
 *   - 成绩按 (userId, examId, taskId) 唯一，最新覆盖。
 *     覆盖前比较 takenAt：乱序到达的旧成绩不会把新成绩冲掉（补考场景必需）。
 *   - 缺少 taskId 直接拒收：方案红线要求成绩必须归属任务，
 *     没有 taskId 的成绩进了闸门只会造成"归属错任务"。
 */
class PeerInboxService
{
    /** 本平台接受的事件目录（方案 §10.1） */
    public const INBOUND_TYPES = [
        'exam.result.published',
    ];

    /** 封套必填字段（方案 §4.1、清单 §3.3 示例） */
    private const ENVELOPE_FIELDS = [
        'eventId',
        'eventType',
        'eventVersion',
        'occurredAt',
        'sourcePlatform',
        'data',
    ];

    /**
     * 消费一个入站事件
     *
     * @param array $envelope
     * @param string $source 中间件已验证的通道编码（与封套 sourcePlatform 需一致）
     * @param string $requestId
     * @return array{accepted:bool,code:string,message:string,eventId:string,duplicated:bool,overwritten:bool}
     */
    public function consume(array $envelope, string $source = '', string $requestId = ''): array
    {
        $check = $this->validateEnvelope($envelope, $source);
        if (!$check['ok']) {
            Log::warning('[peer] envelope rejected', [
                'reason' => $check['reason'],
                'eventId' => (string) ($envelope['eventId'] ?? ''),
                'source' => $source,
            ]);
            return [
                'accepted' => false,
                'code' => $check['code'],
                'message' => $check['reason'],
                'eventId' => (string) ($envelope['eventId'] ?? ''),
                'duplicated' => false,
                'overwritten' => false,
            ];
        }

        $eventId = (string) $envelope['eventId'];
        $sourcePlatform = (string) $envelope['sourcePlatform'];

        $exists = TrainingPeerInbox::where('source_platform', $sourcePlatform)
            ->where('event_id', $eventId)
            ->first();

        if ($exists) {
            return [
                'accepted' => true,
                'code' => PlatformResponse::OK,
                'message' => 'duplicated',
                'eventId' => $eventId,
                'duplicated' => true,
                'overwritten' => false,
            ];
        }

        $overwritten = false;
        try {
            DB::transaction(function () use ($envelope, $eventId, $requestId, $sourcePlatform, &$overwritten) {
                TrainingPeerInbox::create([
                    'source_platform' => $sourcePlatform,
                    'event_id' => $eventId,
                    'event_type' => (string) $envelope['eventType'],
                    'payload' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => TrainingPeerInbox::STATUS_DONE,
                    'request_id' => $requestId,
                    'received_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                ]);

                $overwritten = $this->projectScore($envelope);
            });
        } catch (\Throwable $e) {
            Log::error('[peer] consume failed: ' . $e->getMessage(), ['eventId' => $eventId]);
            return [
                'accepted' => false,
                'code' => PlatformResponse::INTERNAL_ERROR,
                'message' => '事件处理失败',
                'eventId' => $eventId,
                'duplicated' => false,
                'overwritten' => false,
            ];
        }

        return [
            'accepted' => true,
            'code' => PlatformResponse::OK,
            'message' => 'accepted',
            'eventId' => $eventId,
            'duplicated' => false,
            'overwritten' => $overwritten,
        ];
    }

    /**
     * 成绩落库（最新覆盖）
     *
     * @param array $envelope
     * @return bool 是否覆盖了已有记录
     */
    private function projectScore(array $envelope): bool
    {
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        $uid = PeerIdentity::uid((string) ($data['userId'] ?? ''));
        $examId = PeerIdentity::examId($data['examId'] ?? '');
        $taskId = PeerIdentity::taskId($data['taskId'] ?? '');

        if ($uid === '' || $examId === '' || $taskId === '') {
            // 理论上前置校验已挡住，这里是第二道闸：宁可整笔回滚，也不写脏行
            throw new \RuntimeException('成绩事件缺少 userId / examId / taskId');
        }

        $courseId = PeerIdentity::courseId($data['courseId'] ?? '');
        $takenAt = $this->toUtc((string) ($data['takenAt'] ?? ''));
        $eventId = (string) $envelope['eventId'];

        $row = TrainingExamScore::where('subject', $uid)
            ->where('exam_id', $examId)
            ->where('task_id', $taskId)
            ->first();

        $overwritten = false;

        if ($row) {
            // 乱序保护：旧成绩不能覆盖新成绩。
            // 补考刷新依赖这条——否则一次重投的历史事件会把 93 分打回 85。
            if ($row->taken_at !== null && strtotime((string) $row->taken_at) > strtotime($takenAt)) {
                return false;
            }
            $overwritten = true;
        } else {
            $row = new TrainingExamScore();
            $row->subject = $uid;
            $row->exam_id = $examId;
            $row->task_id = $taskId;
        }

        $row->course_id = $courseId;
        $row->score = isset($data['score']) && $data['score'] !== null ? (int) $data['score'] : null;
        $row->max_score = isset($data['maxScore']) ? (int) $data['maxScore'] : 100;
        $row->passed = !empty($data['passed']);
        $row->taken_at = $takenAt;
        $row->rule_version = (string) ($data['ruleVersion'] ?? config('platform.training.rule_version', 'v1'));
        $row->event_id = $eventId;
        $row->source_platform = (string) $envelope['sourcePlatform'];
        $row->save();

        // 闸门不缓存，但这里清一次缓存键，防止将来有人加了缓存忘记失效
        $this->forgetCache($uid, $taskId);

        return $overwritten;
    }

    /**
     * 封套校验
     *
     * @param array $envelope
     * @param string $source
     * @return array{ok:bool,code:string,reason:string}
     */
    private function validateEnvelope(array $envelope, string $source): array
    {
        if (!$envelope) {
            return ['ok' => false, 'code' => PlatformResponse::VALIDATION_ERROR, 'reason' => '请求体为空'];
        }

        $missing = [];
        foreach (self::ENVELOPE_FIELDS as $field) {
            if (!array_key_exists($field, $envelope) || $envelope[$field] === '' || $envelope[$field] === null) {
                $missing[] = $field;
            }
        }
        if ($missing) {
            return [
                'ok' => false,
                'code' => PlatformResponse::VALIDATION_ERROR,
                'reason' => '封套字段缺失：' . implode(',', $missing),
            ];
        }

        $eventType = (string) $envelope['eventType'];
        if (!in_array($eventType, self::INBOUND_TYPES, true)) {
            // 422：事件不在目录内，不重试（清单 §5）
            return [
                'ok' => false,
                'code' => 'EVENT_NOT_ALLOWED',
                'reason' => '事件类型不在目录内：' . $eventType,
            ];
        }

        if ((string) $envelope['eventVersion'] !== '1.0') {
            return [
                'ok' => false,
                'code' => 'EVENT_NOT_ALLOWED',
                'reason' => '不支持的事件版本：' . $envelope['eventVersion'],
            ];
        }

        $sourcePlatform = (string) $envelope['sourcePlatform'];
        if ($sourcePlatform !== 'exam') {
            return [
                'ok' => false,
                'code' => 'EVENT_NOT_ALLOWED',
                'reason' => '本平台只接收 sourcePlatform=exam 的成绩事件',
            ];
        }

        // 通道与封套自述必须一致：不认"用 learning 的密钥发 exam 的事件"
        if ($source !== '' && $source !== $sourcePlatform) {
            return [
                'ok' => false,
                'code' => 'PEER_SCOPE_DENIED',
                'reason' => '通道 ' . $source . ' 与封套 sourcePlatform ' . $sourcePlatform . ' 不一致',
            ];
        }

        if (!is_array($envelope['data'])) {
            return ['ok' => false, 'code' => PlatformResponse::VALIDATION_ERROR, 'reason' => 'data 必须是对象'];
        }

        $data = $envelope['data'];
        if (PeerIdentity::uid((string) ($data['userId'] ?? '')) === '') {
            return ['ok' => false, 'code' => PlatformResponse::VALIDATION_ERROR, 'reason' => 'data.userId 缺失'];
        }
        if (PeerIdentity::examId($data['examId'] ?? '') === '') {
            return ['ok' => false, 'code' => PlatformResponse::VALIDATION_ERROR, 'reason' => 'data.examId 缺失'];
        }
        if (PeerIdentity::taskId($data['taskId'] ?? '') === '') {
            // 红线：没有 taskId 的成绩无法归属任务，收下就是一条脏数据
            return [
                'ok' => false,
                'code' => PlatformResponse::VALIDATION_ERROR,
                'reason' => 'data.taskId 缺失：成绩必须归属实训任务',
            ];
        }

        return ['ok' => true, 'code' => PlatformResponse::OK, 'reason' => ''];
    }

    /**
     * RFC 3339 → UTC DATETIME
     *
     * @param string $value
     * @return string
     */
    private function toUtc(string $value): string
    {
        if ($value === '') {
            return Carbon::now('UTC')->format('Y-m-d H:i:s');
        }
        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return Carbon::now('UTC')->format('Y-m-d H:i:s');
        }
    }

    /**
     * @param string $uid
     * @param string $taskId
     * @return void
     */
    private function forgetCache(string $uid, string $taskId): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget('training:eligibility:' . $uid . ':' . $taskId);
        } catch (\Throwable $e) {
            // 缓存失效失败不影响消费结果
        }
    }
}
