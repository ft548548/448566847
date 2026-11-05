#!/usr/bin/env node
/**
 * 契约一致性静态检查（替代 typecheck，见 docs/platform/deviations.md DV-05）
 *
 * DooTask 无 TypeScript，`tsc --noEmit` 无处可跑。这里把"类型检查"替换为
 * **契约与代码的静态对账**，检查的是同一类问题：文档写了、代码里没有，或者
 * 中间件写了、路由没挂上。
 *
 * 检查项：
 *   1. 契约中间件存在且在 Kernel 注册；
 *   2. /api 路由组已挂载 platform.contract；
 *   3. OpenAPI 文档中的每个 path 都能在对应控制器找到实际方法；
 *   4. .env.example 已声明 PLATFORM_CONTRACT_ENABLED；
 *   5. 规范 §13 要求的交付物文件齐全；
 *   6. Demo 明确标注"演示数据"。
 *
 * 用法：node scripts/contract-check.mjs
 */

import fs from "node:fs";
import path from "node:path";
import yaml from "js-yaml";

const ROOT = process.cwd();
const errors = [];
const notes = [];

function read(rel) {
  const abs = path.join(ROOT, rel);
  if (!fs.existsSync(abs)) {
    errors.push(`缺少文件：${rel}`);
    return "";
  }
  return fs.readFileSync(abs, "utf8");
}

function expect(condition, message) {
  if (!condition) errors.push(message);
}

/* 1. 中间件存在且已注册 ------------------------------------------------ */
const middlewarePath = "app/Http/Middleware/PlatformContract.php";
expect(fs.existsSync(path.join(ROOT, middlewarePath)), `缺少中间件：${middlewarePath}`);

const kernel = read("app/Http/Kernel.php");
expect(
  kernel.includes("platform.contract") && kernel.includes("PlatformContract::class"),
  "app/Http/Kernel.php 未注册 platform.contract 中间件",
);

/* 2. 路由挂载 ---------------------------------------------------------- */
const routes = read("routes/web.php");
const apiGroup = routes.match(/Route::prefix\('api'\)->middleware\(\[([^\]]*)\]\)/);
expect(apiGroup !== null, "routes/web.php 未找到 /api 路由组");
if (apiGroup) {
  expect(
    apiGroup[1].includes("platform.contract"),
    `/api 路由组未挂载 platform.contract（当前：${apiGroup[1].trim()}）`,
  );
}

/* 3. OpenAPI 与控制器方法对账 ------------------------------------------ */
const MODULE_CONTROLLERS = {
  users: "UsersController",
  project: "ProjectController",
  recruitment: "RecruitmentController",
  system: "SystemController",
  dialog: "DialogController",
  file: "FileController",
  report: "ReportController",
};

let spec = null;
const openapiRel = "docs/platform/openapi.yaml";
if (fs.existsSync(path.join(ROOT, openapiRel))) {
  try {
    spec = yaml.load(fs.readFileSync(path.join(ROOT, openapiRel), "utf8"));
  } catch (error) {
    errors.push(`OpenAPI YAML 解析失败：${error.message}`);
  }
} else {
  errors.push(`缺少文件：${openapiRel}`);
}

if (spec) {
  expect(/^3\./.test(String(spec.openapi)), `OpenAPI 版本必须为 3.x，当前 ${spec.openapi}`);
  const paths = Object.keys(spec.paths ?? {});
  expect(paths.length > 0, "OpenAPI 未定义任何 path");

  /** 控制器公开方法缓存 */
  const methodCache = new Map();
  const controllerMethods = (controllerName) => {
    if (methodCache.has(controllerName)) return methodCache.get(controllerName);
    const rel = `app/Http/Controllers/Api/${controllerName}.php`;
    const content = read(rel);
    const names = new Set(
      [...content.matchAll(/public function ([A-Za-z0-9_]+)\s*\(/g)].map((m) => m[1]),
    );
    methodCache.set(controllerName, names);
    return names;
  };

  for (const apiPath of paths) {
    const segments = apiPath.split("/").filter(Boolean); // api, module, method, action?
    if (segments[0] !== "api" || segments.length < 3) {
      errors.push(`路径不符合 /api/{module}/{method} 约定：${apiPath}`);
      continue;
    }
    const [, module, method, action] = segments;
    const controller = MODULE_CONTROLLERS[module];
    if (!controller) {
      errors.push(`未知模块 "${module}"（${apiPath}），请在 MODULE_CONTROLLERS 中登记`);
      continue;
    }
    const expected = action ? `${method}__${action}` : method;
    const methods = controllerMethods(controller);
    expect(methods.has(expected), `${apiPath} 在 ${controller} 中找不到方法 ${expected}()`);
  }

  notes.push(`OpenAPI 对账通过：${paths.length} 个 path`);
}

/* 4. 环境变量样例 ------------------------------------------------------ */
const envExample = read(".env.example");
expect(
  envExample.includes("PLATFORM_CONTRACT_ENABLED"),
  ".env.example 未声明 PLATFORM_CONTRACT_ENABLED",
);

/* 5. 规范 §13 交付物 --------------------------------------------------- */
const REQUIRED_DELIVERABLES = [
  "docs/platform/README.md",
  "docs/platform/openapi.yaml",
  "docs/platform/event-envelope.yaml",
  "docs/platform/实训平台_统一身份与网关接入说明.md",
  "docs/platform/permissions.md",
  "docs/platform/data-dictionary-mapping.md",
  "docs/platform/network-matrix.md",
  "docs/platform/logging.md",
  "docs/platform/deviations.md",
  "demo/index.html",
  ".env.example",
];
for (const rel of REQUIRED_DELIVERABLES) {
  expect(fs.existsSync(path.join(ROOT, rel)), `缺少交付物：${rel}`);
}

/* 6. Demo 必须标注模拟数据 --------------------------------------------- */
if (fs.existsSync(path.join(ROOT, "demo/index.html"))) {
  const demo = fs.readFileSync(path.join(ROOT, "demo/index.html"), "utf8");
  expect(demo.includes("演示数据"), "demo/index.html 未标注「演示数据」");
}

/* 输出 ---------------------------------------------------------------- */
for (const note of notes) console.log(`· ${note}`);

if (errors.length > 0) {
  console.error(`\n✗ 契约一致性检查失败 ${errors.length} 项：`);
  for (const error of errors) console.error(`  - ${error}`);
  process.exit(1);
}

console.log("✓ 契约一致性检查通过");
