<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSourceProjectIdToProjectRecruitments extends Migration
{
    /**
     * 项目大厅：区分「来源项目」与「转正项目」
     *
     *   - source_project_id：用户把自己创建的项目加入招募大厅时回填的源项目ID（去重依据）
     *   - project_id         ：成队转正后新建的项目ID（原有语义）
     */
    public function up()
    {
        Schema::table('project_recruitments', function (Blueprint $table) {
            $table->bigInteger('source_project_id')->nullable()->default(0)
                ->comment('来源项目ID：用户把自己创建的项目加入招募大厅时回填，0 表示非项目来源')
                ->after('project_id');
            $table->index('source_project_id', 'idx_pr_source_project');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('project_recruitments', function (Blueprint $table) {
            $table->dropIndex('idx_pr_source_project');
            $table->dropColumn('source_project_id');
        });
    }
}
