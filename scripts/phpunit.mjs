#!/usr/bin/env node
/**
 * PHPUnit 启动器
 *
 * 为什么不直接写 `vendor/bin/phpunit`：Windows 上 PHP 常常不在 PATH 里，
 * 直接调用会得到 "php 不是内部或外部命令"。这里统一走 PHP_BIN，
 * 与 scripts/lint-php.mjs 保持一致。
 *
 * 用法：
 *   PHP_BIN=D:/dt-run/php/php.exe node scripts/phpunit.mjs
 *   PHP_BIN=... node scripts/phpunit.mjs --filter PlatformContractTest
 */

import { spawnSync } from "node:child_process";
import path from "node:path";
import fs from "node:fs";

const PHP_BIN = process.env.PHP_BIN || "php";
const phpunit = path.join(process.cwd(), "vendor", "phpunit", "phpunit", "phpunit");

if (!fs.existsSync(phpunit)) {
  console.error("未找到 vendor/phpunit/phpunit/phpunit，请先执行 composer install");
  process.exit(1);
}

const result = spawnSync(PHP_BIN, [phpunit, ...process.argv.slice(2)], {
  stdio: "inherit",
  env: process.env,
});

if (result.error) {
  console.error(`无法启动 PHP（PHP_BIN=${PHP_BIN}）：${result.error.message}`);
  console.error("提示：设置 PHP_BIN 指向 php 可执行文件，例如 PHP_BIN=D:/dt-run/php/php.exe");
  process.exit(1);
}

process.exit(result.status ?? 1);
