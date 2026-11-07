<?php

namespace Tests\Feature;

use App\Models\TrainingPeerOutbox;
use App\Services\PeerOutboxService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 发件箱投递行为测试（《三平台对接方案 V2.0》§6.1 表 11）
 *
 * 这张表里有四行，最容易写错的是第二行「2xx + HTML」：
 * 网关对未注册路径会回落 Dashboard 单页，同样返回 200。如果只判状态码，
 * 投递器会报告"全部成功"，而事件一条都没落地——这种假成功比失败更难发现，
 * 因为它不会触发任何告警。
 */
class PeerOutboxDispatchTest extends TestCase
{
    private const SECRET = 'test-exam-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform.peers.self' => 'training',
            'platform.peers.max_attempts' => 6,
            'platform.peers.retry_backoff' => [1, 5, 30, 120, 600, 3600],
            'platform.peers.channels.exam.enabled' => true,
            'platform.peers.channels.exam.secret' => self::SECRET,
            'platform.peers.channels.exam.base_url' => 'http://exam.test',
            'platform.peers.channels.exam.events_path' => '/api/v1/exam/internal/peers/training/events',
        ]);

        TrainingPeerOutbox::where('event_id', 'like', 'evt_out_test%')->delete();
    }

    protected function tearDown(): void
    {
        TrainingPeerOutbox::where('event_id', 'like', 'evt_out_test%')->delete();
        parent::tearDown();
    }

    public function testDeliversOnJsonSuccess()
    {
        Http::fake([
            'exam.test/*' => Http::response(['code' => 'OK', 'data' => ['eventId' => 'x']], 200, ['Content-Type' => 'application/json']),
        ]);

        $row = $this->makeRow();
        $outcome = (new PeerOutboxService())->deliver($row);

        $this->assertSame('delivered', $outcome);
        $row->refresh();
        $this->assertSame(TrainingPeerOutbox::STATUS_DELIVERED, (int) $row->status);
        $this->assertNotNull($row->delivered_at);
    }

    public function testTreatsHtml200AsFailure()
    {
        // 网关兜底页：200 + HTML
        Http::fake([
            'exam.test/*' => Http::response(
                '<!DOCTYPE html><html><body>APISIX Dashboard</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $row = $this->makeRow();
        $outcome = (new PeerOutboxService())->deliver($row);

        $this->assertSame('retry', $outcome, '2xx + HTML 必须判失败，不能当成功');
        $row->refresh();
        $this->assertSame(TrainingPeerOutbox::STATUS_PENDING, (int) $row->status);
        $this->assertStringContainsString('非 JSON', (string) $row->last_error);
    }

    public function testTreatsUnparsableJsonAsFailure()
    {
        Http::fake([
            'exam.test/*' => Http::response('not json at all', 200, ['Content-Type' => 'application/json']),
        ]);

        $row = $this->makeRow();
        $outcome = (new PeerOutboxService())->deliver($row);

        $this->assertSame('retry', $outcome);
        $this->assertStringContainsString('无法解析', (string) $row->refresh()->last_error);
    }

    public function testContractErrorGoesDeadWithoutRetry()
    {
        Http::fake([
            'exam.test/*' => Http::response(['code' => 'EVENT_NOT_ALLOWED', 'message' => 'x'], 422),
        ]);

        $row = $this->makeRow();
        $outcome = (new PeerOutboxService())->deliver($row);

        $this->assertSame('dead', $outcome, '422 属契约错误，重试没有意义');
        $row->refresh();
        $this->assertSame(TrainingPeerOutbox::STATUS_DEAD, (int) $row->status);
        $this->assertSame(1, (int) $row->attempts);
    }

    public function testServerErrorRetriesWithBackoff()
    {
        Http::fake([
            'exam.test/*' => Http::response('upstream unavailable', 503),
        ]);

        $row = $this->makeRow();
        $outcome = (new PeerOutboxService())->deliver($row);

        $this->assertSame('retry', $outcome);
        $row->refresh();
        $this->assertSame(TrainingPeerOutbox::STATUS_PENDING, (int) $row->status);
        $this->assertSame(1, (int) $row->attempts);
        // 首次退避 1 秒
        $this->assertNotNull($row->next_attempt_at);
        $this->assertLessThanOrEqual(3, strtotime((string) $row->next_attempt_at) - time());
    }

    public function testNetworkErrorRetries()
    {
        Http::fake([
            'exam.test/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $row = $this->makeRow();
        $outcome = (new PeerOutboxService())->deliver($row);

        $this->assertSame('retry', $outcome);
        $this->assertStringContainsString('请求异常', (string) $row->refresh()->last_error);
    }

    public function testExhaustedAttemptsBecomeDeadLetter()
    {
        config(['platform.peers.max_attempts' => 2]);
        Http::fake([
            'exam.test/*' => Http::response('boom', 500),
        ]);

        $row = $this->makeRow();
        $row->attempts = 1; // 已失败过一次
        $row->save();

        $outcome = (new PeerOutboxService())->deliver($row);

        $this->assertSame('dead', $outcome);
        $row->refresh();
        $this->assertSame(TrainingPeerOutbox::STATUS_DEAD, (int) $row->status);
        $this->assertSame(2, (int) $row->attempts);
    }

    public function testMissingConfigDoesNotKillEvent()
    {
        config(['platform.peers.channels.exam.base_url' => '']);

        $row = $this->makeRow();
        $outcome = (new PeerOutboxService())->deliver($row);

        // 配置缺失不判死信：补上配置后应能自动补发
        $this->assertSame('retry', $outcome);
        $this->assertStringContainsString('未配置', (string) $row->refresh()->last_error);
    }

    /**
     * 造一条待投递记录
     */
    private function makeRow(): TrainingPeerOutbox
    {
        return TrainingPeerOutbox::create([
            'event_id' => 'evt_out_test_' . bin2hex(random_bytes(4)),
            'event_type' => 'training.task.published',
            'target_platform' => 'exam',
            'payload' => json_encode([
                'eventId' => 'evt_out_test',
                'eventType' => 'training.task.published',
                'eventVersion' => '1.0',
                'sourcePlatform' => 'training',
                'occurredAt' => '2026-11-06T09:00:00Z',
                'data' => ['taskId' => 'task_1001', 'requirements' => []],
            ], JSON_UNESCAPED_UNICODE),
            'status' => TrainingPeerOutbox::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => null,
        ]);
    }
}
