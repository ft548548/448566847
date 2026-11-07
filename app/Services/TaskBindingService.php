<?php

namespace App\Services;

use App\Models\TrainingTaskBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 实训任务勾选绑定（《三平台对接方案 V2.0》§3.1）
 *
 * 本平台是绑定的唯一权威：这里写什么，learning 与 exam 就缓存什么。
 * 因此写绑定与发事件必须在同一个事务里完成——只写库不发事件，
 * 对端永远不知道新任务；只发事件不写库，本平台闸门自己判不了。
 */
class TaskBindingService
{
    public const EVENT_PUBLISHED = 'training.task.published';
    public const EVENT_UPDATED = 'training.task.updated';

    /**
     * 写入（覆盖）绑定并发事件
     *
     * "覆盖"是有意的：勾选结果是全量清单，不是增量补丁。
     * 若按增量合并，撤销一门勾选就永远删不掉了。
     *
     * @param string $taskId
     * @param string $taskTitle
     * @param array $requirements [['courseId'=>2,'examId'=>'exam_2'], ...]
     * @param string $eventType published|updated
     * @return array{ok:bool,message:string,eventId:?string,targets:array,bindings:array}
     */
    public function publish(
        string $taskId,
        string $taskTitle,
        array $requirements,
        string $eventType = self::EVENT_PUBLISHED
    ): array {
        $task = PeerIdentity::taskId($taskId);
        if ($task === '') {
            return ['ok' => false, 'message' => 'taskId 不能为空', 'eventId' => null, 'targets' => [], 'bindings' => []];
        }

        $normalized = $this->normalize($requirements);
        if (isset($normalized['error'])) {
            return ['ok' => false, 'message' => $normalized['error'], 'eventId' => null, 'targets' => [], 'bindings' => []];
        }
        $items = $normalized['items'];

        if ($items === []) {
            return ['ok' => false, 'message' => '绑定清单为空：至少勾选一门课程与考试', 'eventId' => null, 'targets' => [], 'bindings' => []];
        }

        try {
            DB::transaction(function () use ($task, $items) {
                TrainingTaskBinding::where('task_id', $task)->delete();
                foreach ($items as $item) {
                    TrainingTaskBinding::create([
                        'task_id' => $task,
                        'course_id' => $item['courseId'],
                        'exam_id' => $item['examId'],
                        'rule_version' => (string) config('platform.training.rule_version', 'v1'),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('[peer] binding write failed: ' . $e->getMessage(), ['taskId' => $task]);
            return ['ok' => false, 'message' => '绑定写入失败', 'eventId' => null, 'targets' => [], 'bindings' => []];
        }

        // 事件在事务外入队：发件箱本身是"可失败但不阻断"的旁路，
        // 即使这里挂了，绑定也已生效，可由对账补发（§6.2）。
        $envelopeData = [
            'taskId' => $task,
            'taskTitle' => $taskTitle,
            'requirements' => array_map(function (array $item) {
                // 对外 courseId 回填 course_<id>，与本平台其它接口保持同一形态
                return [
                    'courseId' => PeerIdentity::courseRef($item['courseId']),
                    'examId' => $item['examId'],
                ];
            }, $items),
            'publishedAt' => \Carbon\Carbon::now('UTC')->format('c'),
            'ruleVersion' => (string) config('platform.training.rule_version', 'v1'),
        ];

        $targets = $this->activeTargets();
        $enqueued = (new PeerOutboxService())->enqueue($eventType, $envelopeData, $targets);

        return [
            'ok' => true,
            'message' => $targets === [] ? '绑定已生效；未开启任何对端通道，事件暂存未发' : '绑定已生效，事件已入队',
            'eventId' => $enqueued['eventId'],
            'targets' => $enqueued['targets'],
            'bindings' => $items,
        ];
    }

    /**
     * 读取任务的绑定清单
     *
     * @param string $taskId
     * @return array
     */
    public function requirementsOf(string $taskId): array
    {
        $task = PeerIdentity::taskId($taskId);
        if ($task === '') {
            return [];
        }

        return TrainingTaskBinding::where('task_id', $task)
            ->orderBy('id')
            ->get()
            ->map(function (TrainingTaskBinding $row) {
                return [
                    'courseId' => PeerIdentity::courseRef($row->course_id),
                    'examId' => (string) $row->exam_id,
                ];
            })
            ->all();
    }

    /**
     * 归一化并校验勾选清单
     *
     * @param array $requirements
     * @return array{items?:array,error?:string}
     */
    private function normalize(array $requirements): array
    {
        $items = [];
        $seenExam = [];

        foreach ($requirements as $raw) {
            if (!is_array($raw)) {
                return ['error' => 'requirements 的每一项必须是对象'];
            }

            $courseId = PeerIdentity::courseId($raw['courseId'] ?? '');
            $examId = PeerIdentity::examId($raw['examId'] ?? '');

            if ($courseId === '') {
                return ['error' => 'requirements 中存在空的 courseId'];
            }
            if ($examId === '') {
                return ['error' => 'requirements 中存在空的 examId'];
            }
            // 方案规定考试与课程一一对应，同一考试挂两门课是数据错误
            if (isset($seenExam[$examId])) {
                return ['error' => '同一考试被重复勾选：' . $examId];
            }
            $seenExam[$examId] = true;

            $items[] = ['courseId' => $courseId, 'examId' => $examId];
        }

        return ['items' => $items];
    }

    /**
     * 当前启用了的接收方
     *
     * 没有配 base_url 的通道不发：发出去也只能是失败重试，白白堆死信。
     *
     * @return array
     */
    private function activeTargets(): array
    {
        $targets = [];
        foreach (['learning', 'exam'] as $code) {
            $channel = config('platform.peers.channels.' . $code);
            if (!is_array($channel)) {
                continue;
            }
            $enabled = $channel['enabled'] ?? false;
            $enabled = is_bool($enabled)
                ? $enabled
                : !in_array(strtolower((string) $enabled), ['0', 'false', 'off', 'no', ''], true);

            if ($enabled && trim((string) ($channel['base_url'] ?? '')) !== '') {
                $targets[] = $code;
            }
        }
        return $targets;
    }
}
