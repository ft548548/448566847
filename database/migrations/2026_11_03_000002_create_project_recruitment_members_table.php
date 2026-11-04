<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProjectRecruitmentMembersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('project_recruitment_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('recruitment_id')->nullable()->default(0)->comment('招募ID');
            $table->bigInteger('userid')->nullable()->default(0)->comment('队员 userid');
            $table->string('status', 20)->nullable()->default('active')->comment('状态：active|removed|exited');
            $table->timestamp('joined_at')->nullable()->comment('入队时间');
            $table->timestamp('left_at')->nullable()->comment('退出/移除时间');
            $table->timestamps();

            $table->unique(['recruitment_id', 'userid'], 'uk_prm_recruit_user');
            $table->index(['userid', 'status'], 'idx_prm_user_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('project_recruitment_members');
    }
}
