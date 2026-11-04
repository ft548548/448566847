<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProjectRecruitmentApplicationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('project_recruitment_applications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('recruitment_id')->nullable()->default(0)->comment('招募ID');
            $table->bigInteger('applicant_id')->nullable()->default(0)->comment('报名者 userid');
            $table->text('message')->nullable()->comment('报名留言');
            $table->string('status', 20)->nullable()->default('pending')->comment('状态：pending|approved|rejected|withdrawn');
            $table->bigInteger('reviewer_id')->nullable()->default(0)->comment('审批人 userid');
            $table->timestamp('reviewed_at')->nullable()->comment('审批时间');
            $table->string('review_note', 255)->nullable()->default('')->comment('审批备注');
            $table->timestamps();

            $table->unique(['recruitment_id', 'applicant_id'], 'uk_pra_recruit_applicant');
            $table->index(['recruitment_id', 'status'], 'idx_pra_recruit_status');
            $table->index(['applicant_id', 'status'], 'idx_pra_applicant_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('project_recruitment_applications');
    }
}
