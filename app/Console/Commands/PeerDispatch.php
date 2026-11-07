<?php

namespace App\Console\Commands;

use App\Models\TrainingPeerOutbox;
use App\Services\PeerOutboxService;
use Illuminate\Console\Command;

/**
 * 三平台发件箱投递器（《三平台对接方案 V2.0》§6.1）
 *
 * 建议每分钟跑一次：
 *   * * * * * cd /www/wwwroot/dootask && php artisan peer:dispatch >> /dev/null 2>&1
 *
 * 全部业务代码只负责 enqueue，真正的网络调用只发生在这里——
 * 这是"前置行为永不因下游不可用而回滚"的实现位置。
 */
class PeerDispatch extends Command
{
    /** @var string */
    protected $signature = 'peer:dispatch {--limit=50 : 单次最多投递条数} {--dry-run : 只展示待发队列，不实际投递}';

    /** @var string */
    protected $description = '投递三平台发件箱中待发的训练任务绑定事件';

    /**
     * @return int
     */
    public function handle()
    {
        $service = new PeerOutboxService();
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('dry-run')) {
            $rows = TrainingPeerOutbox::where('status', TrainingPeerOutbox::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            $this->table(
                ['id', 'eventId', 'type', 'target', 'attempts', 'nextAttemptAt', 'lastError'],
                $rows->map(function (TrainingPeerOutbox $row) {
                    return [
                        $row->id,
                        $row->event_id,
                        $row->event_type,
                        $row->target_platform,
                        $row->attempts,
                        (string) $row->next_attempt_at,
                        mb_substr((string) $row->last_error, 0, 40),
                    ];
                })->all()
            );

            $this->info('待投递：' . $rows->count() . ' 条（dry-run 未实际发送）');
            return 0;
        }

        $stats = $service->dispatch($limit);

        $this->line(sprintf(
            '取出 %d 条：成功 %d，待重试 %d，死信 %d',
            $stats['picked'],
            $stats['delivered'],
            $stats['retry'],
            $stats['dead']
        ));

        // 死信要能被监控看到，不能静默堆积
        $dead = TrainingPeerOutbox::where('status', TrainingPeerOutbox::STATUS_DEAD)->count();
        if ($dead > 0) {
            $this->warn('当前死信累计：' . $dead . ' 条，可用 POST /api/v1/training/peers/{source}/replay 重放');
        }

        return $stats['dead'] > 0 ? 2 : 0;
    }
}
