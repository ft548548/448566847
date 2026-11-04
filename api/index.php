<?php

/**
 * Vercel serverless front controller for DooTask (Laravel 8).
 *
 * 仅用于 Vercel 部署形态，不改动 public/index.php；本地与容器部署仍走原入口。
 *
 * Vercel 与常驻 PHP 的两点差异必须在这里抹平：
 *   1. 运行期文件系统只读，只有 /tmp 可写 —— Laravel 的 storage 路径必须重定向到 /tmp；
 *   2. 没有 php-fpm/nginx，由 vercel-php 运行时的内置 server 直接执行本文件。
 *
 * 环境变量：
 *   APP_STORAGE_PATH  可选，默认 /tmp/dootask-storage
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__);

/** 建目录（幂等）：函数实例会被复用，已存在直接跳过。 */
$ensure = static function (string $path): void {
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
};

// 1) 可写目录搬到 /tmp
$storagePath = getenv('APP_STORAGE_PATH') ?: '/tmp/dootask-storage';
foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'framework/testing', 'logs', 'app/public'] as $dir) {
    $ensure($storagePath . '/' . $dir);
}

// 2) 维护模式短路（仓库内 storage 只读即可）
if (file_exists($root . '/storage/framework/maintenance.php')) {
    require $root . '/storage/framework/maintenance.php';
}

require $root . '/vendor/autoload.php';

// 2.5) 本入口被当作 router 脚本执行时，PHP 会写入 PATH_INFO 与 PHP_SELF，
//      Symfony 会据此把 `/api/index.php` 当 baseUrl 剥掉，导致 `/api/xxx` 被降级成
//      `/xxx` 而落到 SPA 兜底路由。这里还原成"以 public/index.php 为前端控制器"的形态，
//      使 REQUEST_URI 原样成为 pathInfo。
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
unset($_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO']);

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $root . '/bootstrap/app.php';

// 3) storage 重定向到 /tmp，否则写日志/会话/编译视图会报只读错误
$app->useStoragePath($storagePath);

// 4) 包清单缓存：构建期若已生成 packages.php 就沿用（省一次 vendor 扫描），
//    否则回落到 /tmp，避免运行期往只读的 bootstrap/cache 里写文件。
if (!is_file($root . '/bootstrap/cache/packages.php')) {
    $ensure($storagePath . '/bootstrap/cache');
    $app->useBootstrapPath($storagePath . '/bootstrap');
}

$kernel = $app->make(Kernel::class);

$response = tap($kernel->handle(
    $request = Request::capture()
))->send();

$kernel->terminate($request, $response);
