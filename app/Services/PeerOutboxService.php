<?php

namespace App\Services;

use App\Models\TrainingPeerOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 三平台出站发件箱（《三平台对接方案 V2.0》§6.1、§6.2）
 *
 * 业务事务内只写本表，投递由 peer:dispatch 定时做。这样"发布任务"这个
 * 业务动作不会因为 learning/exam 暂时不可用而失败——方案明确要求
 * "前置行为永不因下游不可用而回滚"。
 *
 * 投递结果的判定（§6.1 表 11），这里最容易写错的是第二行：
 *
 *   2xx + JSON         → 已投递
 *   2xx + HTML         → **判失败并退避**
 *   4xx(400/401/403/404/422) → 契约错误，不重试，死信 + 告警
 *   429/408/5xx/网络   → 退避重试
 *
 * 为什么 2xx + HTML 要判失败：网关对未注册路径会回落 Dashboard 单页，
 * 同样返回 200。只看状态码会得到"全部投递成功"的假象，而事件一条都没落地。
 */
class PeerOutboxService
{
    /** 契约错误：重试没有意义，直接死信 */
    private const FATAL_STATUS = [400, 401, 403, 404, 422];

    /**
     * 写入发件箱（可批量目标）
     *
     * @param string $eventType training.task.published / training.task.updated
     * @param array $data
     * @param array $targets ['learning', 'exam']
     * @return array 生成的 eventId => target 列表
     */
    public function enqueue(string $eventType, array $data, array $targets): array
    {
        $eventId = $this->newEventId();
        $occurredAt = Carbon::now('UTC')->format('c');
        $source = (string) config('platform.peers.self', 'training');

        $envelope = [
            'eventId' => $eventId,
            'eventType' => $eventType,
            'eventVersion' => '1.0',
            'sourcePlatform' => $source,
            'occurredAt' => $occurredAt,
            'data' => $data,
        ];
        $payload = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $created = [];
        foreach ($targets as $target) {
            try {
                // 同一条事件推给多个平台时共用一个 eventId：对端按 eventId 去重，
                // 而 taskId 级归并依赖同一个 id，拆成多个 id 会让对账对不上。
                TrainingPeerOutbox::create([
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'target_platform' => $target,
                    'payload' => $payload,
                    'status' => TrainingPeerOutbox::STATUS_PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                ]);
                $created[] = $target;
            } catch (\Throwable $e) {
                Log::warning('[peer] outbox enqueue failed: ' . $e->getMessage(), [
                    'eventType' => $eventType,
                    'target' => $target,
                ]);
            }
        }

        return ['eventId' => $eventId, 'targets' => $created];
    }

    /**
     * 投递一批待发事件
     *
     * @param int $limit
     * @return array 统计
     */
    public function dispatch(int $limit = 50): array
    {
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $rows = TrainingPeerOutbox::where('status', TrainingPeerOutbox::STATUS_PENDING)
            ->where(function ($query) use ($now) {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $stats = ['picked' => $rows->count(), 'delivered' => 0, 'retry' => 0, 'dead' => 0];

        foreach ($rows as $row) {
            $outcome = $this->deliver($row);
            $stats[$outcome]++;
        }

        return $stats;
    }

    /**
     * 投递单条
     *
     * @param TrainingPeerOutbox $row
     * @return string delivered|retry|dead
     */
    public function deliver(TrainingPeerOutbox $row): string
    {
        $target = (string) $row->target_platform;
        $channel = config('platform.peers.channels.' . $target);

        if (!is_array($channel)) {
            return $this->fail($row, '目标通道未登记：' . $target, true);
        }

        $baseUrl = rtrim((string) ($channel['base_url'] ?? ''), '/');
        $secret = (string) ($channel['secret'] ?? '');
        if ($baseUrl === '' || $secret === '') {
            // 未配置不判死信：等配置补齐后应能自动补发
            return $this->fail($row, '通道未配置 base_url 或 secret：' . $target, false);
        }

        $path = (string) ($channel['events_path'] ?? '');
        $url = $baseUrl . $path;
        $body = (string) $row->payload;
        $requestId = PeerSigner::newRequestId();
        $headers = PeerSigner::headers($secret, (string) config('platform.peers.self', 'training'), $body, $requestId);
        $timeout = max(0.5, ((int) config('platform.peers.timeout_ms', 3000)) / 1000);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            // 网络失败一律退避，不判契约错误——对端可能只是重启
            return $this->fail($row, '请求异常：' . mb_substr($e->getMessage(), 0, 180), false);
        }

        $status = $response->status();
        $contentType = strtolower((string) $response->header('Content-Type'));
        $raw = (string) $response->body();

        if ($status >= 200 && $status < 300) {
            // 2xx 也要看正文：网关兜底页同样回 200
            if (strpos($contentType, 'json') === false) {
                return $this->fail($row, '返回非 JSON（Content-Type: ' . ($contentType ?: '空') . '），按失败处理', false, true);
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $this->fail($row, '2xx 但正文无法解析为 JSON，按失败处理', false, true);
            }

            $row->status = TrainingPeerOutbox::STATUS_DELIVERED;
            $row->delivered_at = Carbon::now('UTC')->format('Y-m-d H:i:s');
            $row->attempts = (int) $row->attempts + 1;
            $row->last_error = '';
            $row->save();

            return 'delivered';
        }

        if (in_array($status, self::FATAL_STATUS, true)) {
            return $this->fail($row, 'HTTP ' . $status . '：' . mb_substr($raw, 0, 180), true);
        }

        return $this->fail($row, 'HTTP ' . $status . '：' . mb_substr($raw, 0, 180), false);
    }

    /**
     * 记一次失败并决定重试还是死信
     *
     * @param TrainingPeerOutbox $row
     * @param string $error
     * @param bool $fatal 契约错误，不再重试
     * @param bool $fakeSuccess 2xx 但正文不可信（网关兜底页），不得计入成功
     * @return string retry|dead
     */
    private function fail(TrainingPeerOutbox $row, string $error, bool $fatal, bool $fakeSuccess = false): string
    {
        $attempts = (int) $row->attempts + 1;
        $max = max(1, (int) config('platform.peers.max_attempts', 6));

        $row->attempts = $attempts;
        $row->last_error = mb_substr($error, 0, 250);

        if ($fatal || $attempts >= $max) {
            $row->status = TrainingPeerOutbox::STATUS_DEAD;
            $row->save();

            Log::warning('[peer] event dead-lettered', [
                'eventId' => (string) $row->event_id,
                'target' => (string) $row->target_platform,
                'attempts' => $attempts,
                'error' => $error,
                'fakeSuccess' => $fakeSuccess,
            ]);

            return 'dead';
        }

        $row->status = TrainingPeerOutbox::STATUS_PENDING;
        $row->next_attempt_at = $this->nextAttemptAt($attempts);
        $row->save();

        return 'retry';
    }

    /**
     * 退避时间：1s→5s→30s→2min→10min→1h（§6.1 表 11）
     *
     * @param int $attempts 已失败次数（从 1 开始）
     * @return string
     */
    private function nextAttemptAt(int $attempts): string
    {
        $backoff = config('platform.peers.retry_backoff', [1, 5, 30, 120, 600, 3600]);
        if (!is_array($backoff) || $backoff === []) {
            $backoff = [1, 5, 30, 120, 600, 3600];
        }
        $index = min($attempts - 1, count($backoff) - 1);
        $delay = max(1, (int) $backoff[$index]);

        return Carbon::now('UTC')->addSeconds($delay)->format('Y-m-d H:i:s');
    }

    /**
     * 生成事件 ID：evt_ + 时间前缀的可排序随机串
     *
     * 不依赖 Laravel 的 Str::ulid()（8.x 没有），手写保证跨版本可用。
     *
     * @return string
     */
    private function newEventId(): string
    {
        $time = sprintf('%013d', (int) floor(microtime(true) * 1000));
        return 'evt_' . $time . bin2hex(random_bytes(8));
    }
}
