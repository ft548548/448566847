<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProjectRecruitmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('project_recruitments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title')->nullable()->default('')->comment('招募标题');
            $table->text('description')->nullable()->comment('招募简介');
            $table->text('skills')->nullable()->comment('所需技能，JSON 数组字符串，1~8 个标签');
            $table->integer('headcount')->nullable()->default(0)->comment('需招募的队员人数（不含发起人）');
            $table->string('status', 20)->nullable()->default('recruiting')->comment('状态：recruiting|full|in_progress|completed|closed');
            $table->timestamp('deadline')->nullable()->comment('报名截止时间');
            $table->timestamp('started_at')->nullable()->comment('成队转正时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->timestamp('closed_at')->nullable()->comment('关闭时间');
            $table->string('close_reason', 255)->nullable()->default('')->comment('关闭原因');
            $table->bigInteger('project_id')->nullable()->default(0)->comment('成队转正后回填的项目ID，0 表示未转正');
            $table->bigInteger('publisher_id')->nullable()->default(0)->comment('发布者 userid');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at'], 'idx_pr_status_created');
            $table->index('publisher_id', 'idx_pr_publisher');
            $table->index('deadline', 'idx_pr_deadline');
            $table->index('project_id', 'idx_pr_project');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('project_recruitments');
    }
}
