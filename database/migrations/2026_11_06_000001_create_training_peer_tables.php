<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 三平台对接数据层（《三平台对接方案 V2.0》）
 *
 * 四张表各对应方案中的一个契约点：
 *
 *   training_task_bindings —— §3.1 勾选绑定。实训平台是绑定的唯一权威，
 *       本表是其持久化形态；(task_id, exam_id) 唯一，因为方案规定考试与
 *       课程一一对应，同一任务下同一考试不可能挂两门课。
 *
 *   training_exam_scores —— §7 成绩回传落库。唯一键 (subject, exam_id, task_id)
 *       实现"最新覆盖"：补考后新成绩直接覆盖旧行，不追加历史，
 *       闸门每次读到的都是最新一次结果。
 *
 *   training_peer_inbox —— §6.3 入站收件箱。(source_platform, event_id) 唯一，
 *       这是幂等的物理保证，重复投递在数据库层就插不进去。
 *
 *   training_peer_outbox —— §6.1 发件箱。业务事务内只写本表，
 *       由 peer:dispatch 定时投递，前置行为不因下游不可用而回滚。
 */
class CreateTrainingPeerTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // —— 勾选绑定（§3.1）——
        if (!Schema::hasTable('training_task_bindings')) {
            Schema::create('training_task_bindings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('task_id', 64)->comment('实训任务ID，形如 task_1001');
                $table->string('course_id', 64)->comment('课程ID，纯数字串，不带 course_ 前缀');
                $table->string('exam_id', 64)->comment('考试ID，形如 exam_2');
                $table->string('rule_version', 16)->default('v1');
                $table->timestamps();

                $table->unique(['task_id', 'exam_id'], 'uk_ttb_task_exam');
                $table->index('course_id', 'idx_ttb_course');
            });
        }

        // —— 成绩库（§7）——
        if (!Schema::hasTable('training_exam_scores')) {
            Schema::create('training_exam_scores', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('subject', 64)->comment('归一化后的用户标识，存十进制 uid');
                $table->string('exam_id', 64);
                $table->string('task_id', 64);
                $table->string('course_id', 64)->default('');
                $table->integer('score')->nullable();
                $table->integer('max_score')->default(100);
                $table->boolean('passed')->default(false);
                $table->dateTime('taken_at')->nullable();
                $table->string('rule_version', 16)->default('v1');
                $table->string('event_id', 64)->default('')->comment('最后一次覆盖该行的 eventId');
                $table->string('source_platform', 32)->default('exam');
                $table->timestamps();

                // 最新覆盖的物理保证：同一 (学员, 考试, 任务) 只有一行
                $table->unique(['subject', 'exam_id', 'task_id'], 'uk_tes_subject_exam_task');
                $table->index(['task_id', 'subject'], 'idx_tes_task_subject');
            });
        }

        // —— 入站收件箱（§6.3）——
        if (!Schema::hasTable('training_peer_inbox')) {
            Schema::create('training_peer_inbox', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('source_platform', 32);
                $table->string('event_id', 64);
                $table->string('event_type', 64);
                $table->longText('payload')->nullable();
                $table->tinyInteger('status')->default(1)->comment('1=已处理 2=处理失败');
                $table->string('request_id', 64)->default('');
                $table->string('last_error', 255)->default('');
                $table->dateTime('received_at')->nullable();
                $table->timestamps();

                $table->unique(['source_platform', 'event_id'], 'uk_tpi_source_event');
            });
        }

        // —— 出站发件箱（§6.1）——
        if (!Schema::hasTable('training_peer_outbox')) {
            Schema::create('training_peer_outbox', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('event_id', 64)->unique('uk_tpo_event');
                $table->string('event_type', 64);
                $table->string('target_platform', 32);
                $table->longText('payload')->nullable();
                $table->tinyInteger('status')->default(0)->comment('0=待投递 1=已投递 2=死信');
                $table->integer('attempts')->default(0);
                $table->dateTime('next_attempt_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->string('last_error', 255)->default('');
                $table->timestamps();

                $table->index(['status', 'next_attempt_at'], 'idx_tpo_dispatch');
                $table->index('target_platform', 'idx_tpo_target');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('training_peer_outbox');
        Schema::dropIfExists('training_peer_inbox');
        Schema::dropIfExists('training_exam_scores');
        Schema::dropIfExists('training_task_bindings');
    }
}
