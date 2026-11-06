<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 平台事件发件箱（Outbox）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §8.4 与 deviations.md DV-11。
 *
 * 本期不生产事件（无投递器），只把表与写入接口预留出来：
 * 业务侧调用 PlatformEventOutbox::enqueue() 落一行，status=0 待投递；
 * 投递器接入后按 status=0 拉取，成功后置 1，失败置 2 并记录 last_error。
 * 这样"事件生产"与"事件投递"解耦，接入时不需要改业务代码。
 */
class CreatePlatformEventOutboxTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('platform_event_outbox')) {
            return;
        }

        Schema::create('platform_event_outbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_id', 64)->default('')->comment('全局唯一，消费侧幂等主键');
            $table->string('event_type', 64)->default('')->comment('如 project.completed');
            $table->string('event_version', 16)->default('1.0')->comment('封套版本');
            $table->string('source_platform', 32)->default('workshop')->comment('本平台编码');
            $table->string('source_entity_id', 64)->default('')->comment('来源实体ID');
            $table->string('actor_id', 64)->default('')->comment('触发者 subject；系统为 system');
            $table->string('correlation_id', 64)->default('')->comment('关联链路');
            $table->string('request_id', 64)->default('')->comment('追踪号');
            $table->text('payload')->nullable()->comment('事件 data，JSON');
            $table->tinyInteger('status')->default(0)->comment('0 待投递 1 已投递 2 投递失败');
            $table->integer('attempts')->default(0)->comment('投递尝试次数');
            $table->timestamp('occurred_at')->nullable()->comment('事件发生时间（UTC）');
            $table->timestamp('delivered_at')->nullable()->comment('投递成功时间');
            $table->string('last_error', 500)->default('')->comment('最近一次失败原因');
            $table->timestamps();

            $table->unique('event_id', 'uk_peo_event');
            $table->index(['status', 'occurred_at'], 'idx_peo_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_event_outbox');
    }
}
