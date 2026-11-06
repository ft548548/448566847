<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 身份绑定表：论坛 sub ↔ 本地 userid
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §4.1.2、§10.2.1。
 * sub 必须原值存储：不 trim、不转小写、不截断、不加前缀（§8.2.2）。
 * 解绑不删行，只把 status 置 0，保留审计痕迹。
 */
class CreateUserIdentityBindingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_identity_binding', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('issuer', 128)->default('')->comment('签发方，防多 IdP 串号');
            $table->string('subject', 64)->default('')->comment('论坛 sub 原值，不得加工');
            $table->bigInteger('userid')->default(0)->comment('本地用户主键');
            $table->tinyInteger('status')->default(1)->comment('1 生效 0 停用');
            $table->timestamp('bound_at')->nullable()->comment('首次绑定时间');
            $table->timestamp('last_login_at')->nullable()->comment('最近登录时间');
            $table->timestamps();

            $table->unique(['issuer', 'subject'], 'uk_issuer_subject');
            $table->index('userid', 'idx_uib_userid');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_identity_binding');
    }
}
