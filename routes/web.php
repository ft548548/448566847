<?php

use App\Http\Controllers\Api\DialogController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RecruitmentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\WorkshopController;
use App\Http\Controllers\Api\WorkshopInternalController;
use App\Http\Controllers\Api\TrainingPeerController;
use App\Http\Controllers\Api\TrainingTaskController;
use App\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


/**
 * 接口 v1（网关接入版）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §5.2、§6.1.2：
 *   - /api/v1/workshop/* 是**精确前缀**，不能写成 /api/*（会把其他平台路由吃进来）
 *   - 旧 RPC 路径 /api/{module}/{method} 保留一个版本，与新前缀并存
 *
 * 中间件顺序：webapi（全局态）→ platform.contract（信封）→ gateway.trust（验签/白名单/CORS）
 * 这一组对存量接口零影响：存量路由没有任何一行改动。
 */
Route::prefix('api/v1/workshop')->middleware(['webapi', 'platform.contract', 'gateway.trust'])->group(function () {
    // —— 身份（公开，§5.4 白名单）——
    Route::get('auth/login',              [WorkshopController::class, 'login']);
    Route::get('auth/oauth/callback',     [WorkshopController::class, 'oauthCallback']);
    // 登录失败落地页：浏览器流程里不能把裸 JSON 甩给用户，错误一律 302 到这里
    Route::get('auth/login-failed',       [WorkshopController::class, 'loginFailed']);
    Route::get('auth/logged-out',         [WorkshopController::class, 'loggedOut']);
    // 论坛账号确认（密码代验）：用户尚未登录，必须公开；安全由 L2 出站签名与失败节流保证
    Route::post('auth/forum-verify',      [WorkshopController::class, 'forumVerify']);
    // —— 身份（登录后）——
    Route::get('auth/userinfo',           [WorkshopController::class, 'userinfo']);
    Route::post('auth/logout',            [WorkshopController::class, 'logout']);
    // —— 探活（公开）——
    Route::get('health',                  [WorkshopController::class, 'health']);

    // —— 考试准入（表 6-2 #5）——
    Route::get('recruitments/{id}/admission', [WorkshopController::class, 'admission'])
        ->where('id', '[0-9]+');

    // —— 业务 REST 别名（§6.1.2）——
    Route::get('projects',                [WorkshopController::class, 'projects']);
    Route::get('projects/{id}',           [WorkshopController::class, 'projectOne'])
        ->where('id', '[0-9]+');
    Route::get('recruitments',            [WorkshopController::class, 'recruitments']);
    Route::get('recruitments/{id}',       [WorkshopController::class, 'recruitmentOne'])
        ->where('id', '[0-9]+');

    // —— 服务间接口（L2 签名 + X-Auth-Mode: service，不对浏览器开放）——
    Route::prefix('internal')->group(function () {
        Route::get('projects/{id}',           [WorkshopInternalController::class, 'projectSummary'])
            ->where('id', '[0-9]+');
        Route::get('projects/{id}/work',      [WorkshopInternalController::class, 'projectWork'])
            ->where('id', '[0-9]+');
        Route::get('projects/{id}/members',   [WorkshopInternalController::class, 'projectMembers'])
            ->where('id', '[0-9]+');
        Route::post('projects/{id}/publish',  [WorkshopInternalController::class, 'publishWork'])
            ->where('id', '[0-9]+')
            ->middleware('idempotency');
        Route::post('events',                 [WorkshopInternalController::class, 'receiveEvent'])
            ->middleware('idempotency');
    });

    // —— CORS 预检兜底 ——
    // Laravel 在路由匹配阶段就按 HTTP 方法过滤，OPTIONS 没有对应路由会先抛 405，
    // GatewayTrust::preflight() 根本执行不到。这里只对 v1 前缀兜底，存量路由零影响。
    // 真正的预检响应由 GatewayTrust 直接返回（204 + 回显白名单来源），本闭包不会被触发。
    Route::options('{any}', function () {
        return response('', 204);
    })->where('any', '.*');
});

/**
 * 三平台对端通道（《三平台对接方案 V2.0》§4.2、§10.2、清单 §3）
 *
 * 两种前缀指向同一份逻辑，区别只在链路：
 *   /api/v1/training/peers/...           内网直连，只做对端层签名
 *   /api/v1/training/internal/peers/...  经统一网关，网关先做 L2，应用再做对端层
 *
 * 应用侧不重复验 L2：internal 与 peers 落到同一个控制器，靠 peer.auth 区分身份。
 * 这与 /api/v1/workshop/internal/*（只认 L2 签名）是两套不同契约，不要混用。
 */
Route::prefix('api/v1/training')->group(function () {
    // —— 能力发现与握手：方案明确免鉴权 ——
    Route::middleware(['webapi', 'platform.contract'])->group(function () {
        Route::get('peers', [TrainingPeerController::class, 'discover']);
        Route::get('internal/peers', [TrainingPeerController::class, 'discover']);
        Route::get('peers/{source}/handshake', [TrainingPeerController::class, 'handshake']);
        Route::get('internal/peers/{source}/handshake', [TrainingPeerController::class, 'handshake']);
    });

    // —— 服务间：对端层签名（X-App-Code / X-Request-Id / X-Timestamp / X-Internal-Sign）——
    Route::middleware(['webapi', 'platform.contract', 'peer.auth'])->group(function () {
        Route::get('peers/{source}/students/{userId}/training-eligibility/{taskId}', [TrainingPeerController::class, 'eligibility']);
        Route::get('internal/peers/{source}/students/{userId}/training-eligibility/{taskId}', [TrainingPeerController::class, 'eligibility']);

        Route::post('peers/{source}/events', [TrainingPeerController::class, 'events']);
        Route::post('internal/peers/{source}/events', [TrainingPeerController::class, 'events']);

        Route::get('peers/{source}/pull', [TrainingPeerController::class, 'pull']);
        Route::get('internal/peers/{source}/pull', [TrainingPeerController::class, 'pull']);

        Route::post('peers/{source}/receipts', [TrainingPeerController::class, 'receipts']);
        Route::post('internal/peers/{source}/receipts', [TrainingPeerController::class, 'receipts']);

        Route::post('peers/{source}/replay', [TrainingPeerController::class, 'replay']);
        Route::post('internal/peers/{source}/replay', [TrainingPeerController::class, 'replay']);
    });

    // —— 业务侧：管理端勾选课程+考试（登录态，沿用招募的发布者/管理员权限）——
    Route::middleware(['webapi', 'platform.contract', 'gateway.trust'])->group(function () {
        Route::get('tasks/{taskId}/bindings', [TrainingTaskController::class, 'show']);
        Route::post('tasks/{taskId}/bindings', [TrainingTaskController::class, 'publish']);
    });
});

/**
 * 接口
 */
Route::prefix('api')->middleware(['webapi', 'platform.contract'])->group(function () {
    // 会员
    Route::any('users/{method}',                    UsersController::class);
    Route::any('users/{method}/{action}',           UsersController::class);
    // 项目
    Route::any('project/{method}',                  ProjectController::class);
    Route::any('project/{method}/{action}',         ProjectController::class);
    // 项目大厅（招募）
    Route::any('recruitment/{method}',              RecruitmentController::class);
    Route::any('recruitment/{method}/{action}',     RecruitmentController::class);
    // 系统
    Route::any('system/{method}',                   SystemController::class);
    Route::any('system/{method}/{action}',          SystemController::class);
    // 对话
    Route::any('dialog/{method}',                   DialogController::class);
    Route::any('dialog/{method}/{action}',          DialogController::class);
    // 文件
    Route::any('file/{method}',                     FileController::class);
    Route::any('file/{method}/{action}',            FileController::class);
    // 报告
    Route::any('report/{method}',                     ReportController::class);
    Route::any('report/{method}/{action}',            ReportController::class);
});

/**
 * 页面
 */
Route::middleware(['webapi'])->group(function () {
    Route::any('/',                                     IndexController::class);
    Route::any('/{method}',                             IndexController::class);
    Route::any('/{method}/{action}',                    IndexController::class);
    Route::any('/{method}/{action}/{child}',            IndexController::class);
    Route::any('/{method}/{action}/{child}/{n}',        IndexController::class);
    Route::any('/{method}/{action}/{child}/{n}/{c}',    IndexController::class);
});
