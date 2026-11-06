<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 跨平台字段补齐
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §10.2.1、§10.4.2、§7.5、G15。
 *
 * course_id 为空表示该招募不校验考试准入 —— 这是保证存量报名流程
 * 完全按原逻辑走的开关（§10.4.2 末段）。
 * request_id 用于把业务动态与访问日志按追踪号串起来（§7.5）。
 */
class AddPlatformFieldsToRecruitmentsAndLogs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('project_recruitments') && !Schema::hasColumn('project_recruitments', 'course_id')) {
            Schema::table('project_recruitments', function (Blueprint $table) {
                $table->string('course_id', 64)->default('')->comment('关联课程ID；空表示不校验考试准入');
                $table->index('course_id', 'idx_pr_course');
            });
        }

        if (Schema::hasTable('project_logs') && !Schema::hasColumn('project_logs', 'request_id')) {
            Schema::table('project_logs', function (Blueprint $table) {
                $table->string('request_id', 64)->default('')->comment('追踪号，与访问日志关联');
                $table->index('request_id', 'idx_pl_request');
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
        if (Schema::hasTable('project_recruitments') && Schema::hasColumn('project_recruitments', 'course_id')) {
            Schema::table('project_recruitments', function (Blueprint $table) {
                $table->dropIndex('idx_pr_course');
                $table->dropColumn('course_id');
            });
        }

        if (Schema::hasTable('project_logs') && Schema::hasColumn('project_logs', 'request_id')) {
            Schema::table('project_logs', function (Blueprint $table) {
                $table->dropIndex('idx_pl_request');
                $table->dropColumn('request_id');
            });
        }
    }
}
