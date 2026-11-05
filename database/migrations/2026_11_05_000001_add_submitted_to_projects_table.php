<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubmittedToProjectsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->comment('提交时间，NULL 表示未提交（仅已完成/已归档项目可提交）');
            $table->bigInteger('submitted_userid')->nullable()->default(0)->comment('提交会员');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'submitted_userid']);
        });
    }
}
