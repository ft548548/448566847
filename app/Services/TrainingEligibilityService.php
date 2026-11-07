<?php

namespace App\Services;

use App\Models\TrainingExamScore;
use App\Models\TrainingTaskBinding;

/**
 * 实训闸门判定（《三平台对接方案 V2.0》§3.2、§3.3、§3.4）
 *
 * 判定口径：
 *   all     —— 任务绑定的每门考试都必须有成绩且 ≥ min_score（默认）
 *   average —— 每门都有成绩，且平均分 ≥ min_score
 *
 * 三条不可让步的规则：
 *   1. 每次判定都读最新成绩，绝不缓存判定结果（方案 §9"不缓存闸门状态"）；
 *   2. 缺考与不达标是两种 reason，不能合并——前者要引导去考试，后者要引导补考；
 *   3. 任务没有任何绑定时判 not_bound 且不放行。宁可拦住，也不能因为
 *      "找不到绑定"就默认放行，那等于绕过勾选。
 */
class TrainingEligibilityService
{
    public const REASON_OK = '';
    public const REASON_SCORES_INSUFFICIENT = 'scores_insufficient';
    public const REASON_MISSING_EXAM = 'missing_exam';
    public const REASON_NOT_BOUND = 'not_bound';

    /**
     * 判定某学员能否接取某实训任务
     *
     * @param string $userId 任意形态（usr_10000 / 10000），内部归一化
     * @param string $taskId 任意形态（task_1001 / 1001）
     * @return array
     */
    public function evaluate(string $userId, string $taskId): array
    {
        $uid = PeerIdentity::uid($userId);
        $task = PeerIdentity::taskId($taskId);

        $minScore = (int) config('platform.training.min_score', 90);
        $mode = strtolower(trim((string) config('platform.training.min_score_mode', 'all')));
        if ($mode !== 'average') {
            $mode = 'all';
        }
        $ruleVersion = (string) config('platform.training.rule_version', 'v1');

        $bindings = TrainingTaskBinding::where('task_id', $task)->orderBy('id')->get();

        // 未绑定 = 未达标。这是防"绕过勾选"的关键分支
        if ($bindings->isEmpty()) {
            return $this->result($uid, $task, false, $minScore, [], $ruleVersion, self::REASON_NOT_BOUND, $mode, null);
        }

        $requirements = [];
        $missing = [];
        $below = [];
        $sum = 0;
        $counted = 0;
        $lowest = null;

        foreach ($bindings as $binding) {
            $examId = PeerIdentity::examId($binding->exam_id);
            $row = TrainingExamScore::where('subject', $uid)
                ->where('exam_id', $examId)
                ->where('task_id', $task)
                ->first();

            // 缺考：没有记录，或记录里分数为空
            if (!$row || $row->score === null) {
                $missing[] = $examId;
                $requirements[] = [
                    'courseId' => $this->courseInt($binding->course_id),
                    'examId' => $examId,
                    'score' => null,
                    'passed' => false,
                    'missing' => true,
                ];
                continue;
            }

            $score = (int) $row->score;
            $sum += $score;
            $counted++;
            $lowest = $lowest === null ? $score : min($lowest, $score);

            $itemPassed = $score >= $minScore;
            if (!$itemPassed) {
                $below[] = $examId;
            }

            $requirements[] = [
                'courseId' => $this->courseInt($binding->course_id),
                'examId' => $examId,
                'score' => $score,
                'passed' => $itemPassed,
                'missing' => false,
            ];
        }

        $total = $bindings->count();

        if ($mode === 'average') {
            // 平均口径：仍然要求每门都有成绩，只是用均值与阈值比较
            $canStart = $missing === [] && $counted === $total && $counted > 0
                && ($sum / $counted) >= $minScore;
        } else {
            $canStart = $missing === [] && $below === [];
        }

        if ($missing !== []) {
            $reason = self::REASON_MISSING_EXAM;
        } elseif (!$canStart) {
            $reason = self::REASON_SCORES_INSUFFICIENT;
        } else {
            $reason = self::REASON_OK;
        }

        $extra = [
            'mode' => $mode,
            'lowestScore' => $lowest,
            'missingExamIds' => $missing,
        ];
        if ($mode === 'average') {
            $extra['averageScore'] = $counted > 0 ? round($sum / $counted, 2) : null;
        }

        return $this->result($uid, $task, $canStart, $minScore, $requirements, $ruleVersion, $reason, $mode, $extra);
    }

    /**
     * 组装响应体（字段名严格对齐方案 §3.4 的响应示例）
     *
     * @param string $uid
     * @param string $taskId
     * @param bool $canStart
     * @param int $minScore
     * @param array $requirements
     * @param string $ruleVersion
     * @param string $reason
     * @param string $mode
     * @param array|null $extra
     * @return array
     */
    private function result(
        string $uid,
        string $taskId,
        bool $canStart,
        int $minScore,
        array $requirements,
        string $ruleVersion,
        string $reason,
        string $mode,
        ?array $extra
    ): array {
        $out = [
            'userId' => PeerIdentity::userId($uid),
            'taskId' => $taskId,
            'canStart' => $canStart,
            'minScore' => $minScore,
            'requirements' => $requirements,
            'ruleVersion' => $ruleVersion,
            'reason' => $reason,
        ];

        if ($extra !== null) {
            foreach ($extra as $key => $value) {
                $out[$key] = $value;
            }
        } else {
            $out['mode'] = $mode;
        }

        return $out;
    }

    /**
     * courseId 在响应里是数字（方案示例："courseId": 2）
     *
     * 非数字形态（理论上不该出现）原样返回字符串，便于对端发现异常
     * 而不是被静默转成 0。
     *
     * @param string $courseId
     * @return int|string
     */
    private function courseInt(string $courseId)
    {
        $pure = PeerIdentity::courseId($courseId);
        return ctype_digit($pure) ? (int) $pure : $pure;
    }
}
