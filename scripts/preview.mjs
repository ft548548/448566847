#!/usr/bin/env node
/**
 * 本地预览服务（规范 §3.1-5 要求的 preview 脚本）
 *
 * 等价于：
 *   php -S 127.0.0.1:2222 -t public server.php
 *
 * 用脚本包装的原因：Windows 上 php 常不在 PATH，需要统一走 PHP_BIN。
 *
 * 用法：
 *   PHP_BIN=D:/dt-run/php/php.exe npm run preview
 *   PORT=8080 PHP_BIN=... npm run preview
 */

import { spawn } from "node:child_process";
import path from "node:path";

const PHP_BIN = process.env.PHP_BIN || "php";
const HOST = process.env.HOST || "127.0.0.1";
const PORT = process.env.PORT || "2222";

const args = ["-S", `${HOST}:${PORT}`, "-t", path.join(process.cwd(), "public"), "server.php"];
console.log(`> ${PHP_BIN} ${args.join(" ")}`);
console.log(`> 预览地址 http://${HOST}:${PORT}`);

const child = spawn(PHP_BIN, args, { stdio: "inherit", env: process.env });

child.on("error", (error) => {
  console.error(`无法启动 PHP（PHP_BIN=${PHP_BIN}）：${error.message}`);
  console.error("提示：设置 PHP_BIN 指向 php 可执行文件，例如 PHP_BIN=D:/dt-run/php/php.exe");
  process.exit(1);
});

child.on("exit", (code) => process.exit(code ?? 0));
