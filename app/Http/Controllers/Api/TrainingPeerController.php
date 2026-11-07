<?php

namespace App\Http\Controllers\Api;

use App\Models\TrainingPeerOutbox;
use App\Services\PeerIdentity;
use App\Services\PeerInboxService;
use App\Services\PeerOutboxService;
use App\Services\PeerSigner;
use App\Services\PlatformResponse;
use App\Services\TrainingEligibilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * 三平台对端接口（《三平台对接方案 V2.0》§4.2、§10.2）
 *
 * 本控制器是**实训平台（training）对外的服务面**，只给 learning / exam 调用。
 * 路径前缀两种形态指向同一份逻辑：
 *   /api/v1/training/peers/...          内网直连
 *   /api/v1/training/internal/peers/... 经统一网关（L2 之后再走 peer.auth）
 *
 * 七个端点对应方案 §10.2 最后一行：
 *   GET  /peers                              能力发现
 *   GET  /peers/{source}/handshake           握手自检（免鉴权）
 *   GET  /peers/{source}/students/{userId}/training-eligibility/{taskId}
 *   POST /peers/{source}/events              收 exam.result.published
 *   GET  /peers/{source}/pull                游标补拉
 *   POST /peers/{source}/receipts            回执
 *   POST /peers/{source}/replay              死信重放
 */
class TrainingPeerController extends AbstractController
{
    /**
     * 能力发现（免鉴权）
     *
     * GET /api/v1/training/peers
     *
     * 对端在联调第一步调这里，拿到路径、事件目录与签名规范，
     * 避免"路径猜错 / 事件名拼错"这类来回沟通。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function discover()
    {
        $self = (string) config('platform.peers.self', 'training');

        return PlatformResponse::ok([
            'platform' => $self,
            'platformAlias' => 'workshop',
            'ruleVersion' => (string) config('platform.training.rule_version', 'v1'),
            'auth' => [
                'mode' => (string) config('platform.peers.auth_mode', 'auto'),
                'headers' => [
                    PeerSigner::HEADER_APP_CODE,
                    PeerSigner::HEADER_REQUEST_ID,
                    PeerSigner::HEADER_TIMESTAMP,
                    PeerSigner::HEADER_SIGNATURE,
                ],
                'canonical' => 'appCode \\n requestId \\n timestamp \\n sha256_hex(rawBody)',
                'algorithm' => 'HMAC-SHA256',
                'skewSeconds' => (int) config('platform.peers.skew', 300),
            ],
            'endpoints' => [
                'handshake' => '/api/v1/training/peers/{source}/handshake',
                'eligibility' => '/api/v1/training/peers/{source}/students/{userId}/training-eligibility/{taskId}',
                'events' => '/api/v1/training/peers/{source}/events',
                'pull' => '/api/v1/training/peers/{source}/pull',
                'receipts' => '/api/v1/training/peers/{source}/receipts',
                'replay' => '/api/v1/training/peers/{source}/replay',
            ],
            'inboundEvents' => PeerInboxService::INBOUND_TYPES,
            'outboundEvents' => [
                'training.task.published',
                'training.task.updated',
            ],
            'thresholds' => [
                'minScore' => (int) config('platform.training.min_score', 90),
                'minScoreMode' => (string) config('platform.training.min_score_mode', 'all'),
            ],
        ]);
    }

    /**
     * 握手自检（免鉴权，方案 §4.2）
     *
     * GET /api/v1/training/peers/{source}/handshake
     *
     * 两个判据（清单第一条验收）：
     *   appCodeMatched —— 请求头 X-App-Code 与路径 {source} 一致
     *   peerEnabled    —— 该通道在本平台已登记且 enabled
     *
     * 免鉴权不等于无信息量：这里刻意不回显密钥是否存在，只给布尔值。
     *
     * @param Request $request
     * @param string $source
     * @return \Illuminate\Http\JsonResponse
     */
    public function handshake(Request $request, $source)
    {
        $source = trim((string) $source);
        $appCode = trim((string) $request->headers->get(PeerSigner::HEADER_APP_CODE, ''));

        $channel = config('platform.peers.channels.' . $source);
        $enabled = is_array($channel) && $this->channelEnabled($channel);

        return PlatformResponse::ok([
            'platform' => (string) config('platform.peers.self', 'training'),
            'source' => $source,
            'appCode' => $appCode,
            'appCodeMatched' => $appCode === '' ? null : ($appCode === $source),
            'peerEnabled' => $enabled,
            'authMode' => (string) config('platform.peers.auth_mode', 'auto'),
            'serverTime' => Carbon::now('UTC')->format('c'),
            'ruleVersion' => (string) config('platform.training.rule_version', 'v1'),
            'inboundEvents' => PeerInboxService::INBOUND_TYPES,
            'hint' => $enabled
                ? ''
                : '该通道未在本平台开启：请在 PEER_' . strtoupper($source) . '_ENABLED / _SECRET / _BASE_URL 配置后重启',
        ]);
    }

    /**
     * 实训闸门判定（方案 §3.4）
     *
     * GET /api/v1/training/peers/{source}/students/{userId}/training-eligibility/{taskId}
     *
     * 每次调用都读最新成绩，不缓存判定结果——方案把"前端缓存解锁状态"
     * 列为明确的风险项（§9 绕过闸门）。
     *
     * @param string $source
     * @param string $userId
     * @param string $taskId
     * @return \Illuminate\Http\JsonResponse
     */
    public function eligibility($source, $userId, $taskId)
    {
        $uid = PeerIdentity::uid((string) $userId);
        if ($uid === '') {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                'userId 不能为空',
                ['reason' => 'USER_ID_EMPTY'],
                400
            );
        }

        $result = (new TrainingEligibilityService())->evaluate($uid, (string) $taskId);

        // 未达标仍回 200 + canStart=false：这是业务判定，不是请求错误。
        // 若回 4xx，对端容易把"没达标"当成"接口挂了"去重试。
        return PlatformResponse::ok($result, $result['canStart'] ? '可以接取' : '未达到实训准入条件');
    }

    /**
     * 接收考试平台成绩事件
     *
     * POST /api/v1/training/peers/{source}/events
     *
     * @param Request $request
     * @param string $source
     * @return \Illuminate\Http\JsonResponse
     */
    public function events(Request $request, $source)
    {
        $envelope = $request->json()->all();
        if (!is_array($envelope) || !$envelope) {
            $envelope = $request->all();
        }

        $requestId = (string) $request->attributes->get('peer.request_id', PlatformResponse::requestId());
        $result = (new PeerInboxService())->consume($envelope, (string) $source, $requestId);

        if (!$result['accepted']) {
            $status = $this->statusFor($result['code']);
            return PlatformResponse::fail(
                $result['code'],
                $result['message'] ?: '事件被拒收',
                array_filter([
                    'reason' => $result['code'],
                    'eventId' => $result['eventId'] ?? '',
                ]),
                $status
            );
        }

        return PlatformResponse::ok([
            'eventId' => $result['eventId'],
            'duplicated' => (bool) $result['duplicated'],
            'overwritten' => (bool) $result['overwritten'],
        ], $result['duplicated'] ? '事件已处理（幂等）' : '事件已接收');
    }

    /**
     * 游标补拉（方案 §6.2 ②）
     *
     * GET /api/v1/training/peers/{source}/pull?afterId=0&limit=50
     *
     * 对端用它补自己漏收的事件：本平台按自增 id 返回"已投递给它"的记录。
     * 用自增 id 而非时间戳，是因为同秒多条时间戳会重复或漏读。
     *
     * @param Request $request
     * @param string $source
     * @return \Illuminate\Http\JsonResponse
     */
    public function pull(Request $request, $source)
    {
        $afterId = max(0, (int) $request->query('afterId', 0));
        $limit = (int) $request->query('limit', 50);
        $limit = $limit < 1 ? 50 : min($limit, 200);

        $rows = TrainingPeerOutbox::where('target_platform', (string) $source)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'id' => (int) $row->id,
                'eventId' => (string) $row->event_id,
                'eventType' => (string) $row->event_type,
                'payload' => json_decode((string) $row->payload, true),
                'deliveredAt' => $row->delivered_at ? Carbon::parse($row->delivered_at)->format('c') : null,
            ];
        }

        return PlatformResponse::ok([
            'source' => (string) $source,
            'afterId' => $afterId,
            'count' => count($events),
            'nextAfterId' => $rows->isEmpty() ? $afterId : (int) $rows->last()->id,
            'events' => $events,
        ]);
    }

    /**
     * 回执（方案 §6.2 ①）
     *
     * POST /api/v1/training/peers/{source}/receipts
     * body: { correlationId | eventId, status: accepted|rejected, reason }
     *
     * accepted —— 闭环，不再重发；
     * rejected —— 60 秒后重发（方案规定的固定间隔）；
     * 指向不存在的事件 → 422 TARGET_EVENT_NOT_FOUND。
     *
     * @param Request $request
     * @param string $source
     * @return \Illuminate\Http\JsonResponse
     */
    public function receipts(Request $request, $source)
    {
        $body = $request->json()->all();
        if (!is_array($body)) {
            $body = [];
        }

        $eventId = trim((string) ($body['correlationId'] ?? $body['eventId'] ?? ''));
        $status = strtolower(trim((string) ($body['status'] ?? 'accepted')));

        if ($eventId === '') {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                '缺少 correlationId（指向被确认的 eventId）',
                ['reason' => 'CORRELATION_ID_MISSING'],
                400
            );
        }
        if (!in_array($status, ['accepted', 'rejected'], true)) {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                'status 只接受 accepted 或 rejected',
                ['reason' => 'RECEIPT_STATUS_INVALID'],
                400
            );
        }

        $row = TrainingPeerOutbox::where('event_id', $eventId)
            ->where('target_platform', (string) $source)
            ->first();

        if (!$row) {
            return PlatformResponse::fail(
                'TARGET_EVENT_NOT_FOUND',
                '回执指向的事件不存在：' . $eventId,
                ['reason' => 'TARGET_EVENT_NOT_FOUND', 'eventId' => $eventId],
                422
            );
        }

        if ($status === 'accepted') {
            $row->status = TrainingPeerOutbox::STATUS_DELIVERED;
            $row->delivered_at = $row->delivered_at ?: Carbon::now('UTC')->format('Y-m-d H:i:s');
            $row->last_error = '';
            $row->save();

            return PlatformResponse::ok(['eventId' => $eventId, 'status' => 'accepted'], '回执已记录');
        }

        // rejected：60 秒后重发，不立刻重投。立刻重投在对端持续 5xx 时会变成风暴
        $row->status = TrainingPeerOutbox::STATUS_PENDING;
        $row->next_attempt_at = Carbon::now('UTC')->addSeconds(60)->format('Y-m-d H:i:s');
        $row->last_error = mb_substr('回执 rejected：' . (string) ($body['reason'] ?? ''), 0, 250);
        $row->save();

        return PlatformResponse::ok(['eventId' => $eventId, 'status' => 'rejected', 'retryAfter' => 60], '已安排在 60 秒后重发');
    }

    /**
     * 死信重放（方案 §6.2 ③ 的人工干预口）
     *
     * POST /api/v1/training/peers/{source}/replay
     * body: { eventId } 或 { afterId } 或空（重放该通道全部死信）
     *
     * @param Request $request
     * @param string $source
     * @return \Illuminate\Http\JsonResponse
     */
    public function replay(Request $request, $source)
    {
        $body = $request->json()->all();
        if (!is_array($body)) {
            $body = [];
        }

        $query = TrainingPeerOutbox::where('target_platform', (string) $source)
            ->where('status', TrainingPeerOutbox::STATUS_DEAD);

        $eventId = trim((string) ($body['eventId'] ?? ''));
        if ($eventId !== '') {
            $query->where('event_id', $eventId);
        }
        $afterId = (int) ($body['afterId'] ?? 0);
        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query->limit(200)->get();
        if ($rows->isEmpty()) {
            return PlatformResponse::ok(['replayed' => 0], '没有待重放的死信');
        }

        foreach ($rows as $row) {
            $row->status = TrainingPeerOutbox::STATUS_PENDING;
            $row->attempts = 0;
            $row->next_attempt_at = Carbon::now('UTC')->format('Y-m-d H:i:s');
            $row->last_error = '';
            $row->save();
        }

        return PlatformResponse::ok([
            'replayed' => $rows->count(),
            'eventIds' => $rows->pluck('event_id')->unique()->values()->all(),
        ], '已重新入队，下次 peer:dispatch 会投递');
    }

    /**
     * 错误码 → HTTP 状态（方案 §10.4）
     *
     * @param string $code
     * @return int
     */
    private function statusFor(string $code): int
    {
        $map = [
            'AUTH_REQUIRED' => 401,
            'PEER_NOT_OPEN' => 403,
            'PEER_SCOPE_DENIED' => 403,
            'PEER_NOT_FOUND' => 404,
            'EVENT_NOT_ALLOWED' => 422,
            'TARGET_EVENT_NOT_FOUND' => 422,
            'VALIDATION_ERROR' => 400,
            'INTERNAL_ERROR' => 500,
        ];
        return $map[$code] ?? 400;
    }

    /**
     * @param array $channel
     * @return bool
     */
    private function channelEnabled(array $channel): bool
    {
        $value = $channel['enabled'] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
    }
}
