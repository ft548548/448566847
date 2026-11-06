<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 考试准入相关表
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §10.4.1（投影表）与 §8.5（收件箱）。
 *
 * 投影表只加速、不作为拒绝依据：拒绝用户必须有实时回源结论（§8.3、决策 D4）。
 * 收件箱按 eventId 主键去重，承载事件消费的幂等语义（§8.4.2、§8.5）。
 */
class CreateExamAdmissionTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('exam_admission_projection')) {
            Schema::create('exam_admission_projection', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('subject', 64)->default('')->comment('论坛 sub 原值');
                $table->string('course_id', 64)->default('')->comment('课程ID，原值存储');
                $table->tinyInteger('eligible')->default(0)->comment('1 通过 0 未通过');
                $table->string('reason', 32)->default('')->comment('对齐考试平台 reason 枚举');
                $table->integer('score')->nullable()->comment('成绩，仅展示，不落业务表');
                $table->string('rule_version', 32)->default('')->comment('规则版本，供下游复算');
                $table->timestamp('granted_at')->nullable()->comment('结论时间（UTC）');
                $table->tinyInteger('source')->default(1)->comment('1 回源 2 事件');
                $table->string('last_event_id', 64)->default('')->comment('最近生效的 eventId');
                $table->timestamp('expires_at')->nullable()->comment('本地缓存过期时间');
                $table->timestamps();

                $table->unique(['subject', 'course_id'], 'uk_subject_course');
                $table->index('expires_at', 'idx_eap_expires');
            });
        }

        if (!Schema::hasTable('exam_event_inbox')) {
            Schema::create('exam_event_inbox', function (Blueprint $table) {
                $table->string('event_id', 64)->primary()->comment('全局唯一，主键去重');
                $table->string('event_type', 64)->default('')->comment('exam.passed / exam.failed');
                $table->string('event_version', 16)->default('1.0')->comment('当前只接受 1.0');
                $table->string('source_platform', 32)->default('')->comment('必须是 exam');
                $table->string('source_entity_id', 64)->default('')->comment('来源实体，如 record_5001');
                $table->string('actor_id', 64)->default('')->comment('操作者；系统为 system');
                $table->string('correlation_id', 64)->default('')->comment('关联链路');
                $table->string('request_id', 64)->default('')->comment('追踪号');
                $table->timestamp('occurred_at')->nullable()->comment('事件发生时间（UTC）');
                $table->timestamp('received_at')->nullable()->comment('接收时间');
                $table->tinyInteger('status')->default(1)->comment('1 已处理 2 处理失败');
                $table->string('last_error', 500)->default('')->comment('失败原因摘要');
                $table->timestamps();

                $table->index(['event_type', 'occurred_at'], 'idx_eei_type_time');
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
        Schema::dropIfExists('exam_event_inbox');
        Schema::dropIfExists('exam_admission_projection');
    }
}
