#!/usr/bin/env node
/**
 * PHP 静态语法检查（规范 §3.1-4：lint 必须可执行并纳入门禁）
 *
 * 为什么不用 ESLint/PHPStan：
 *   - DooTask 前端是 Vue 2 + Laravel Mix，引入 ESLint 需要改构建链（见 DV-06）；
 *   - PHPStan 未安装，本期不新增 composer 依赖（见 DV-07）。
 * 因此这里用 PHP 自带的 `php -l` 做整仓语法检查，保证"改坏语法"能被拦住。
 *
 * 用法：node scripts/lint-php.mjs
 * PHP 路径：优先环境变量 PHP_BIN，其次 PATH 中的 php。
 *   Windows 本地示例：PHP_BIN=D:/dt-run/php/php.exe node scripts/lint-php.mjs
 */

import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const ROOT = process.cwd();
const TARGETS = ["app", "routes", "tests", "database", "config", "server.php", "artisan"];
const SKIP_DIRS = new Set(["node_modules", "vendor", "storage", "public", ".git", "bootstrap"]);

const PHP_BIN = process.env.PHP_BIN || "php";

function walk(entry, out = []) {
  const abs = path.isAbsolute(entry) ? entry : path.join(ROOT, entry);
  if (!fs.existsSync(abs)) return out;
  const stat = fs.statSync(abs);
  if (stat.isFile()) {
    if (abs.endsWith(".php")) out.push(abs);
    return out;
  }
  for (const name of fs.readdirSync(abs)) {
    if (SKIP_DIRS.has(name)) continue;
    walk(path.join(abs, name), out);
  }
  return out;
}

function main() {
  let files = [];
  for (const target of TARGETS) files = walk(target, files);
  files = [...new Set(files)].sort();

  if (files.length === 0) {
    console.error(`未找到任何 PHP 文件，工作目录是否正确？${ROOT}`);
    process.exit(1);
  }

  const failed = [];
  for (const file of files) {
    try {
      execFileSync(PHP_BIN, ["-l", file], { stdio: "pipe", encoding: "utf8" });
    } catch (error) {
      const output = `${error.stdout ?? ""}${error.stderr ?? ""}`.trim();
      failed.push({ file: path.relative(ROOT, file), output });
    }
  }

  if (failed.length > 0) {
    console.error(`✗ PHP 语法检查失败 ${failed.length} 个文件（共检查 ${files.length} 个）：`);
    for (const item of failed) {
      console.error(`  - ${item.file}`);
      if (item.output) console.error(`    ${item.output.split("\n")[0]}`);
    }
    process.exit(1);
  }

  console.log(`✓ PHP 语法检查通过：${files.length} 个文件`);
}

main();
