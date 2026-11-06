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

/**
 * v1 路径 → [控制器, 方法, HTTP 方法]
 *
 * v1 不是 `{module}/{method}` 派发，无法按路径段推导，因此显式登记。
 * 新增 v1 接口必须同时改三处：路由、控制器、这张表 + openapi.yaml。
 */
const V1_ROUTE_MAP = {
  "/api/v1/workshop/auth/login": ["WorkshopController", "login", "get"],
  "/api/v1/workshop/auth/oauth/callback": ["WorkshopController", "oauthCallback", "get"],
  "/api/v1/workshop/auth/forum-verify": ["WorkshopController", "forumVerify", "post"],
  "/api/v1/workshop/auth/userinfo": ["WorkshopController", "userinfo", "get"],
  "/api/v1/workshop/auth/logout": ["WorkshopController", "logout", "post"],
  "/api/v1/workshop/auth/logged-out": ["WorkshopController", "loggedOut", "get"],
  "/api/v1/workshop/health": ["WorkshopController", "health", "get"],
  "/api/v1/workshop/recruitments/{id}/admission": ["WorkshopController", "admission", "get"],
  "/api/v1/workshop/projects": ["WorkshopController", "projects", "get"],
  "/api/v1/workshop/projects/{id}": ["WorkshopController", "projectOne", "get"],
  "/api/v1/workshop/recruitments": ["WorkshopController", "recruitments", "get"],
  "/api/v1/workshop/recruitments/{id}": ["WorkshopController", "recruitmentOne", "get"],
  "/api/v1/workshop/internal/projects/{id}": ["WorkshopInternalController", "projectSummary", "get"],
  "/api/v1/workshop/internal/projects/{id}/work": ["WorkshopInternalController", "projectWork", "get"],
  "/api/v1/workshop/internal/projects/{id}/members": ["WorkshopInternalController", "projectMembers", "get"],
  "/api/v1/workshop/internal/projects/{id}/publish": ["WorkshopInternalController", "publishWork", "post"],
  "/api/v1/workshop/internal/events": ["WorkshopInternalController", "receiveEvent", "post"],
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
    // v1（网关接入版）：按显式映射表校验，不套用 RPC 路径规则
    if (apiPath.startsWith("/api/v1/workshop")) {
      const entry = V1_ROUTE_MAP[apiPath];
      if (!entry) {
        errors.push(`v1 路径未在 V1_ROUTE_MAP 登记：${apiPath}`);
        continue;
      }
      const [controller, method, httpMethod] = entry;
      const declared = Object.keys(spec.paths[apiPath]);
      expect(
        declared.includes(httpMethod),
        `${apiPath} 在 openapi.yaml 中声明为 ${declared.join("/")}，与 V1_ROUTE_MAP 的 ${httpMethod} 不一致`,
      );
      expect(
        controllerMethods(controller).has(method),
        `${apiPath} 在 ${controller} 中找不到方法 ${method}()`,
      );
      continue;
    }

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

  // 反向校验：映射表里的 v1 路径必须都在契约中，避免"代码有、文档无"
  for (const apiPath of Object.keys(V1_ROUTE_MAP)) {
    expect(paths.includes(apiPath), `V1_ROUTE_MAP 中的 ${apiPath} 未在 openapi.yaml 声明`);
  }

  // 契约外方法只提示不阻断：存量有大量内部接口未登记（dialog/report 整模块）
  const extra = [];
  for (const [mod, ctrl] of Object.entries(MODULE_CONTROLLERS)) {
    const declared = new Set();
    for (const p of paths) {
      const seg = p.split("/").filter(Boolean);
      if (seg[1] === mod && seg[2]) declared.add(seg[2] + (seg[3] ? `__${seg[3]}` : ""));
    }
    for (const m of controllerMethods(ctrl)) {
      if (["__construct", "__invoke", "middleware"].includes(m)) continue;
      if (!declared.has(m)) extra.push(`${mod}/${m.replace("__", "/")}`);
    }
  }

  notes.push(`OpenAPI 对账通过：${paths.length} 个 path（其中 v1 ${Object.keys(V1_ROUTE_MAP).length} 个）`);
  if (extra.length > 0) {
    notes.push(
      `契约外方法 ${extra.length} 个（未登记，仅提示）：${extra.slice(0, 10).join(", ")}${extra.length > 10 ? " …" : ""}`,
    );
  }
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
