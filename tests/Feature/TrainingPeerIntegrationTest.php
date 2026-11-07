<?php

namespace Tests\Feature;

use App\Models\TrainingExamScore;
use App\Models\TrainingPeerInbox;
use App\Models\TrainingPeerOutbox;
use App\Models\TrainingTaskBinding;
use App\Services\PeerSigner;
use App\Services\TaskBindingService;
use Tests\TestCase;

/**
 * 三平台对接验收测试（《三平台对接清单 V2.0》§7.2）
 *
 * 覆盖八条验收用例中属于实训平台的全部条目，外加方案里点名的边界情况：
 * 签名错误、时钟偏移、跨通道越权、未注册事件、缺 taskId、幂等重复、补考刷新。
 *
 * 测试用固定的 taskId 前缀与 userId，setUp/tearDown 双向清理，
 * 不会污染开发库里的真实数据。
 */
class TrainingPeerIntegrationTest extends TestCase
{
    /** 测试专用标识，勿与真实数据冲突 */
    private const SECRET_EXAM = 'test-exam-secret-0123456789';
    private const SECRET_LEARNING = 'test-learning-secret-0123456789';
    private const UID = '900001';
    private const TASK = 'task_test_a';
    private const TASK_ALT = 'task_test_b';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform.peers.self' => 'training',
            'platform.peers.auth_mode' => 'auto',
            'platform.peers.skew' => 300,
            'platform.peers.channels.exam.enabled' => true,
            'platform.peers.channels.exam.secret' => self::SECRET_EXAM,
            'platform.peers.channels.learning.enabled' => true,
            'platform.peers.channels.learning.secret' => self::SECRET_LEARNING,
            'platform.training.min_score' => 90,
            'platform.training.min_score_mode' => 'all',
        ]);

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    // ————————————————————————————————————————————————
    // 一、能力发现与握手（清单 7.1 第 1、2 步）
    // ————————————————————————————————————————————————

    public function testDiscoverReturnsCapabilitiesWithoutAuth()
    {
        $response = $this->getJson('/api/v1/training/peers');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('OK', $body['code']);
        $this->assertSame('training', $body['data']['platform']);
        $this->assertContains('exam.result.published', $body['data']['inboundEvents']);
        $this->assertSame(90, $body['data']['thresholds']['minScore']);
    }

    public function testHandshakeIsPublicAndReportsChannelState()
    {
        $response = $this->getJson('/api/v1/training/peers/exam/handshake', [
            'X-App-Code' => 'exam',
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertTrue($data['appCodeMatched']);
        $this->assertTrue($data['peerEnabled']);
        $this->assertSame('auto', $data['authMode']);
    }

    public function testHandshakeReportsDisabledChannel()
    {
        config(['platform.peers.channels.exam.enabled' => false]);

        $response = $this->getJson('/api/v1/training/peers/exam/handshake', [
            'X-App-Code' => 'exam',
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.peerEnabled'));
        $this->assertNotSame('', $response->json('data.hint'));
    }

    // ————————————————————————————————————————————————
    // 二、对端层鉴权（清单 7.2「签名错误」）
    // ————————————————————————————————————————————————

    public function testMissingSignatureReturns401()
    {
        $response = $this->call('GET', '/api/v1/training/peers/exam/students/usr_' . self::UID . '/training-eligibility/' . self::TASK);

        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    public function testWrongSignatureReturns401()
    {
        $ts = time();
        $rid = 'req_' . bin2hex(random_bytes(8));

        $response = $this->call('GET', '/api/v1/training/peers/exam/students/usr_' . self::UID . '/training-eligibility/' . self::TASK, [], [], [], [
            'HTTP_X_APP_CODE' => 'exam',
            'HTTP_X_REQUEST_ID' => $rid,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_INTERNAL_SIGN' => str_repeat('a', 64),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(401);
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
        $this->assertStringContainsString('签名', (string) $response->json('msg'));
    }

    public function testClockSkewReturns401()
    {
        $ts = time() - 900; // 超出 ±300 秒
        $rid = 'req_' . bin2hex(random_bytes(8));
        $sign = PeerSigner::sign(self::SECRET_EXAM, 'exam', $rid, $ts, '');

        $response = $this->call('GET', '/api/v1/training/peers/exam/students/usr_' . self::UID . '/training-eligibility/' . self::TASK, [], [], [], [
            'HTTP_X_APP_CODE' => 'exam',
            'HTTP_X_REQUEST_ID' => $rid,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_INTERNAL_SIGN' => $sign,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(401);
        $this->assertStringContainsString('窗口', (string) $response->json('msg'));
    }

    public function testUnopenedChannelReturns403()
    {
        config(['platform.peers.channels.exam.enabled' => false]);

        $ts = time();
        $rid = 'req_' . bin2hex(random_bytes(8));
        $sign = PeerSigner::sign(self::SECRET_EXAM, 'exam', $rid, $ts, '');

        $response = $this->call('GET', '/api/v1/training/peers/exam/students/usr_' . self::UID . '/training-eligibility/' . self::TASK, [], [], [], [
            'HTTP_X_APP_CODE' => 'exam',
            'HTTP_X_REQUEST_ID' => $rid,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_INTERNAL_SIGN' => $sign,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $this->assertSame('PEER_NOT_OPEN', $response->json('code'));
    }

    public function testCrossChannelAccessDenied()
    {
        // 用 exam 的密钥与编码，去访问 learning 通道
        $ts = time();
        $rid = 'req_' . bin2hex(random_bytes(8));
        $sign = PeerSigner::sign(self::SECRET_EXAM, 'exam', $rid, $ts, '');

        $response = $this->call('GET', '/api/v1/training/peers/learning/students/usr_' . self::UID . '/training-eligibility/' . self::TASK, [], [], [], [
            'HTTP_X_APP_CODE' => 'exam',
            'HTTP_X_REQUEST_ID' => $rid,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_INTERNAL_SIGN' => $sign,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $this->assertSame('PEER_SCOPE_DENIED', $response->json('code'));
    }

    // ————————————————————————————————————————————————
    // 三、成绩回传（清单 7.2「成绩回传」）
    // ————————————————————————————————————————————————

    public function testScoreEventAcceptedAndStored()
    {
        $response = $this->postSignedEvent($this->scoreEnvelope('evt_test_001', self::TASK, 'exam_2', 92));

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('OK', $body['code']);
        $this->assertFalse($body['data']['duplicated']);

        $row = TrainingExamScore::where('subject', self::UID)
            ->where('exam_id', 'exam_2')
            ->where('task_id', self::TASK)
            ->first();

        $this->assertNotNull($row, '成绩必须落库');
        $this->assertSame(92, (int) $row->score);
        $this->assertTrue((bool) $row->passed);
    }

    public function testDuplicateEventIdIsIdempotent()
    {
        $envelope = $this->scoreEnvelope('evt_test_dup', self::TASK, 'exam_2', 92);

        $first = $this->postSignedEvent($envelope);
        $second = $this->postSignedEvent($envelope);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertTrue($second->json('data.duplicated'));

        $this->assertSame(1, TrainingPeerInbox::where('event_id', 'evt_test_dup')->count());
        $this->assertSame(1, TrainingExamScore::where('subject', self::UID)->where('task_id', self::TASK)->count());
    }

    public function testEventWithoutTaskIdRejected()
    {
        $envelope = $this->scoreEnvelope('evt_test_notask', self::TASK, 'exam_2', 92);
        unset($envelope['data']['taskId']);

        $response = $this->postSignedEvent($envelope);

        $response->assertStatus(400);
        $this->assertStringContainsString('taskId', (string) $response->json('msg'));
    }

    public function testUnknownEventTypeReturns422()
    {
        $envelope = $this->scoreEnvelope('evt_test_type', self::TASK, 'exam_2', 92);
        $envelope['eventType'] = 'exam.something.else';

        $response = $this->postSignedEvent($envelope);

        $response->assertStatus(422);
        $this->assertSame('EVENT_NOT_ALLOWED', $response->json('code'));
    }

    public function testEventFromLearningChannelRejected()
    {
        // 用 learning 的通道投递 exam 事件：封套 sourcePlatform 与通道不一致
        $envelope = $this->scoreEnvelope('evt_test_wrongsrc', self::TASK, 'exam_2', 92);
        $response = $this->postSignedEvent($envelope, 'learning', self::SECRET_LEARNING);

        $response->assertStatus(403);
        $this->assertSame('PEER_SCOPE_DENIED', $response->json('code'));
    }

    // ————————————————————————————————————————————————
    // 四、实训闸门（清单 7.2 达标 / 未达标 / 补考刷新）
    // ————————————————————————————————————————————————

    public function testEligibilityNotBoundWhenTaskHasNoRequirements()
    {
        $response = $this->getSignedEligibility(self::TASK_ALT);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertFalse($body['data']['canStart']);
        $this->assertSame('not_bound', $body['data']['reason']);
    }

    public function testEligibilityPassesWhenAllScoresReachThreshold()
    {
        $this->bind(self::TASK, [[2, 'exam_2'], [5, 'exam_5']]);
        $this->storeScores([
            ['exam_2', 92],
            ['exam_5', 95],
        ]);

        $response = $this->getSignedEligibility(self::TASK);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertTrue($data['canStart']);
        $this->assertSame('', $data['reason']);
        $this->assertSame(90, $data['minScore']);
        $this->assertCount(2, $data['requirements']);
        // courseId 在响应里是数字（方案 §3.4 示例）
        $this->assertSame(2, $data['requirements'][0]['courseId']);
        $this->assertSame('exam_2', $data['requirements'][0]['examId']);
    }

    public function testEligibilityFailsWhenAnyScoreBelowThreshold()
    {
        $this->bind(self::TASK, [[2, 'exam_2'], [5, 'exam_5']]);
        $this->storeScores([
            ['exam_2', 92],
            ['exam_5', 88],
        ]);

        $response = $this->getSignedEligibility(self::TASK);

        $data = $response->json('data');
        $this->assertFalse($data['canStart']);
        $this->assertSame('scores_insufficient', $data['reason']);
        $this->assertSame(88, $data['lowestScore']);
    }

    public function testEligibilityReportsMissingExam()
    {
        $this->bind(self::TASK, [[2, 'exam_2'], [5, 'exam_5']]);
        $this->storeScores([['exam_2', 95]]);

        $response = $this->getSignedEligibility(self::TASK);

        $data = $response->json('data');
        $this->assertFalse($data['canStart']);
        $this->assertSame('missing_exam', $data['reason']);
        $this->assertContains('exam_5', $data['missingExamIds']);
    }

    public function testMakeupExamRefreshesScoreAndOpensGate()
    {
        $this->bind(self::TASK, [[2, 'exam_2']]);

        // 首考 88，未过闸门
        $this->postSignedEvent($this->scoreEnvelope('evt_test_first', self::TASK, 'exam_2', 88, '2026-11-06T10:00:00Z'));
        $before = $this->getSignedEligibility(self::TASK)->json('data');
        $this->assertFalse($before['canStart']);
        $this->assertSame('scores_insufficient', $before['reason']);

        // 补考 93，再次回传，闸门开放
        $this->postSignedEvent($this->scoreEnvelope('evt_test_makeup', self::TASK, 'exam_2', 93, '2026-11-06T12:00:00Z'));
        $after = $this->getSignedEligibility(self::TASK)->json('data');
        $this->assertTrue($after['canStart']);

        // 最新覆盖而非追加
        $this->assertSame(1, TrainingExamScore::where('subject', self::UID)->where('task_id', self::TASK)->count());
        $this->assertSame(93, (int) TrainingExamScore::where('subject', self::UID)->value('score'));
    }

    public function testOutOfOrderEventDoesNotDowngradeScore()
    {
        $this->bind(self::TASK, [[2, 'exam_2']]);

        // 先到新成绩
        $this->postSignedEvent($this->scoreEnvelope('evt_test_new', self::TASK, 'exam_2', 95, '2026-11-06T12:00:00Z'));
        // 再到旧成绩（重投或乱序），不能把 95 打回 80
        $this->postSignedEvent($this->scoreEnvelope('evt_test_old', self::TASK, 'exam_2', 80, '2026-11-06T09:00:00Z'));

        $this->assertSame(95, (int) TrainingExamScore::where('subject', self::UID)->value('score'));
        $this->assertTrue($this->getSignedEligibility(self::TASK)->json('data.canStart'));
    }

    // ————————————————————————————————————————————————
    // 五、勾选发布（清单 7.2「勾选发布」）
    // ————————————————————————————————————————————————

    public function testPublishBindingWritesRowsAndEnqueuesEvent()
    {
        $result = (new TaskBindingService())->publish(self::TASK, 'Java 工程实训', [
            ['courseId' => 2, 'examId' => 'exam_2'],
            ['courseId' => 'course_5', 'examId' => 'exam_5'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['eventId']);

        $this->assertSame(2, TrainingTaskBinding::where('task_id', self::TASK)->count());
        // course_5 被归一化为 5
        $this->assertTrue(TrainingTaskBinding::where('task_id', self::TASK)->where('course_id', '5')->exists());
    }

    public function testPublishRejectsDuplicateExam()
    {
        $result = (new TaskBindingService())->publish(self::TASK, '重复考试', [
            ['courseId' => 2, 'examId' => 'exam_2'],
            ['courseId' => 5, 'examId' => 'exam_2'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('重复', $result['message']);
    }

    public function testPublishReplacesPreviousBindings()
    {
        $service = new TaskBindingService();
        $service->publish(self::TASK, '首版', [['courseId' => 2, 'examId' => 'exam_2']]);
        $service->publish(self::TASK, '改版', [['courseId' => 7, 'examId' => 'exam_7']]);

        $this->assertSame(1, TrainingTaskBinding::where('task_id', self::TASK)->count());
        $this->assertTrue(TrainingTaskBinding::where('task_id', self::TASK)->where('exam_id', 'exam_7')->exists());
    }

    // ————————————————————————————————————————————————
    // 六、闭环：回执与补拉
    // ————————————————————————————————————————————————

    public function testReceiptForUnknownEventReturns422()
    {
        $response = $this->postSigned('/api/v1/training/peers/exam/receipts', [
            'correlationId' => 'evt_does_not_exist',
            'status' => 'accepted',
        ]);

        $response->assertStatus(422);
        $this->assertSame('TARGET_EVENT_NOT_FOUND', $response->json('code'));
    }

    public function testPullReturnsDeliveredEventsForSource()
    {
        TrainingPeerOutbox::create([
            'event_id' => 'evt_test_pull_1',
            'event_type' => 'training.task.published',
            'target_platform' => 'learning',
            'payload' => json_encode(['eventId' => 'evt_test_pull_1', 'data' => ['taskId' => self::TASK]]),
            'status' => TrainingPeerOutbox::STATUS_DELIVERED,
            'attempts' => 1,
            'delivered_at' => now(),
        ]);

        $ts = time();
        $rid = 'req_' . bin2hex(random_bytes(8));
        $sign = PeerSigner::sign(self::SECRET_LEARNING, 'learning', $rid, $ts, '');

        $response = $this->call('GET', '/api/v1/training/peers/learning/pull?afterId=0', [], [], [], [
            'HTTP_X_APP_CODE' => 'learning',
            'HTTP_X_REQUEST_ID' => $rid,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_INTERNAL_SIGN' => $sign,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('data.count'));
    }

    // ————————————————————————————————————————————————
    // 辅助
    // ————————————————————————————————————————————————

    /**
     * 构造成绩事件封套（方案 §3.3）
     */
    private function scoreEnvelope(string $eventId, string $taskId, string $examId, int $score, string $takenAt = '2026-11-06T11:18:00Z'): array
    {
        return [
            'eventId' => $eventId,
            'eventType' => 'exam.result.published',
            'eventVersion' => '1.0',
            'sourcePlatform' => 'exam',
            'occurredAt' => $takenAt,
            'data' => [
                'userId' => 'usr_' . self::UID,
                'courseId' => 2,
                'examId' => $examId,
                'taskId' => $taskId,
                'score' => $score,
                'maxScore' => 100,
                'passed' => $score >= 60,
                'takenAt' => $takenAt,
                'ruleVersion' => 'v1',
            ],
        ];
    }

    /**
     * 带签名投递事件
     */
    private function postSignedEvent(array $envelope, string $appCode = 'exam', string $secret = self::SECRET_EXAM)
    {
        return $this->postSigned('/api/v1/training/peers/' . $appCode . '/events', $envelope, $appCode, $secret);
    }

    /**
     * 带签名 POST（签名对**实际发出的原始字节**计算）
     */
    private function postSigned(string $uri, array $body, string $appCode = 'exam', string $secret = self::SECRET_EXAM)
    {
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = time();
        $rid = 'req_' . bin2hex(random_bytes(8));
        $sign = PeerSigner::sign($secret, $appCode, $rid, $ts, $raw);

        return $this->call('POST', $uri, [], [], [], [
            'HTTP_X_APP_CODE' => $appCode,
            'HTTP_X_REQUEST_ID' => $rid,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_INTERNAL_SIGN' => $sign,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);
    }

    /**
     * 带签名查询实训资格
     */
    private function getSignedEligibility(string $taskId)
    {
        $uri = '/api/v1/training/peers/exam/students/usr_' . self::UID . '/training-eligibility/' . $taskId;
        $ts = time();
        $rid = 'req_' . bin2hex(random_bytes(8));
        // GET 无 body，哈希空串——这一步不能省
        $sign = PeerSigner::sign(self::SECRET_EXAM, 'exam', $rid, $ts, '');

        return $this->call('GET', $uri, [], [], [], [
            'HTTP_X_APP_CODE' => 'exam',
            'HTTP_X_REQUEST_ID' => $rid,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_INTERNAL_SIGN' => $sign,
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    /**
     * 直接写绑定（绕过业务侧登录校验，聚焦闸门逻辑）
     */
    private function bind(string $taskId, array $pairs): void
    {
        foreach ($pairs as $pair) {
            TrainingTaskBinding::create([
                'task_id' => $taskId,
                'course_id' => (string) $pair[0],
                'exam_id' => $pair[1],
                'rule_version' => 'v1',
            ]);
        }
    }

    /**
     * 直接写成绩（模拟已回传的状态）
     */
    private function storeScores(array $rows): void
    {
        foreach ($rows as $row) {
            TrainingExamScore::create([
                'subject' => self::UID,
                'exam_id' => $row[0],
                'task_id' => self::TASK,
                'course_id' => '2',
                'score' => $row[1],
                'max_score' => 100,
                'passed' => $row[1] >= 60,
                'taken_at' => '2026-11-06 10:00:00',
                'rule_version' => 'v1',
                'event_id' => 'evt_seed_' . $row[0],
                'source_platform' => 'exam',
            ]);
        }
    }

    /**
     * 清理测试数据（只删测试前缀，不碰真实数据）
     */
    private function cleanup(): void
    {
        TrainingTaskBinding::where('task_id', 'like', 'task_test%')->delete();
        TrainingExamScore::where('task_id', 'like', 'task_test%')->delete();
        TrainingExamScore::where('subject', self::UID)->delete();
        TrainingPeerInbox::where('event_id', 'like', 'evt_test%')->delete();
        TrainingPeerOutbox::where('event_id', 'like', 'evt_test%')->delete();
    }
}
