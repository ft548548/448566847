# DooTask 平台契约对齐（规范 V2.0）

本目录是 DooTask（实训工坊 + 项目大厅招募域）对《警院 WeChat 五平台统一开发与
功能联动约束规范》V2.0 的对齐交付物。

**核心前提：现有功能一律不改。** 所有对齐只做「新增中间件 + 新增文档 + 新增 Demo」，
不改变任何既有接口的请求/响应语义、页面行为与数据结构。凡是无法在不改功能前提下
达成的条款，一律登记在 `deviations.md`，不做“看起来对齐”的表面文章。

## 1 已落地的东西

| 能力                 | 位置                                       | 一句话                                                                              |
| -------------------- | ------------------------------------------ | ----------------------------------------------------------------------------------- |
| 请求头治理与信封叠加 | `app/Http/Middleware/PlatformContract.php` | 校验/生成 `X-Request-Id`，白名单校验 `X-App-Code`，响应体追加 `code` 与 `requestId` |
| 访问日志             | `storage/logs/platform-access.log`         | 每请求一行 JSON，含 requestId/耗时/状态/IP                                          |
| API 契约             | `docs/platform/openapi.yaml`               | OpenAPI 3.0.3，23 个核心接口，与控制器方法双向对账                                  |
| 事件契约             | `docs/platform/event-envelope.yaml`        | 封套与目录已定义；本期不生产不消费（DV-11）                                         |
| 权限映射             | `docs/platform/permissions.md`             | 规范权限点 → DooTask 实际服务端判定                                                 |
| 字段映射             | `docs/platform/data-dictionary-mapping.md` | 公共实体 → DooTask 表/字段                                                          |
| 网络矩阵             | `docs/platform/network-matrix.md`          | 实际拓扑 + 生产目标 + 缺口清单                                                      |
| 日志说明             | `docs/platform/logging.md`                 | 字段定义与排查流程                                                                  |
| 偏离登记             | `docs/platform/deviations.md`              | 14 条偏离，逐条写清理由与补偿                                                       |
| 离线 Demo            | `demo/index.html`                          | 单文件零网络，三角色权限差异 + 一条联动流程                                         |

## 2 常用命令

```bash
# 语法检查（PHP 全仓）
PHP_BIN=D:/dt-run/php/php.exe npm run lint

# 契约一致性检查（替代 typecheck，见 DV-07）
npm run typecheck

# 格式化（仅作用于新增目录，不碰存量 Vue）
npm run format
npm run format:check

# 自动化测试（含契约中间件用例）
PHP_BIN=D:/dt-run/php/php.exe npm run test

# 一键门禁
PHP_BIN=D:/dt-run/php/php.exe npm run check
```

Windows 下 PHP 不在 PATH 时，用 `PHP_BIN` 指向解释器；CI 里通常 `php` 直接在 PATH。

## 3 启动与依赖服务

| 依赖    | 版本            | 说明                                           |
| ------- | --------------- | ---------------------------------------------- |
| PHP     | 8.0.30          | 需 `pdo_mysql`、`redis`、`mbstring`、`openssl` |
| MariaDB | 10.x，端口 3307 | 库名 `dootask`，前缀 `pre_`                    |
| Redis   | 端口 6379       | 会话/队列/广播                                 |
| Node    | ≥16             | 仅构建前端与跑脚本，非运行时依赖               |

启动（本地已跑通的形态）：

```bash
"D:/dt-run/php/php.exe" -S 127.0.0.1:2222 -t public server.php
```

前端构建：`npm run build`（Laravel Mix，产出 `public/js/app.js`）。**本目录的改动不涉及前端构建。**

## 4 规范 §13 九项交付对照

|  #  | 要求                                 | 本仓库位置                                   |              状态              |
| :-: | ------------------------------------ | -------------------------------------------- | :----------------------------: |
|  1  | README：边界、命令、负责人、依赖服务 | 本文件                                       |               ✅               |
|  2  | OpenAPI 3.x 与公共实体 schema        | `openapi.yaml`                               |               ✅               |
|  3  | 角色-权限点矩阵、状态机、数据归属    | `permissions.md`（含状态取值与数据范围说明） |               ✅               |
|  4  | SSO 客户端配置申请                   | ——                                           |      ⛔ 未接 SSO（DV-01）      |
|  5  | 网络矩阵与部署拓扑                   | `network-matrix.md`                          |               ✅               |
|  6  | 事件清单、schema、幂等与重试         | `event-envelope.yaml`                        |  ⚠️ 契约已定；实现缺（DV-11）  |
|  7  | 环境变量样例（无真实密钥）           | `.env.example`                               |               ✅               |
|  8  | 可运行 Demo 与模拟数据说明           | `demo/index.html`、`demo/README.md`          |               ✅               |
|  9  | 联调记录、失败重放说明、日志字段     | `logging.md`                                 | ⚠️ 日志字段有；重放无（DV-11） |

## 5 验证方式（可复现）

```bash
# 1) 信封叠加与 requestId 回带
curl -s -D - -H "x-request-id: trace-abc-0001" http://127.0.0.1:2222/api/system/setting
#    → 响应头 X-Request-Id: trace-abc-0001；响应体末尾 "code":"OK","requestId":"trace-abc-0001"

# 2) 非法平台编码
curl -s -H "x-app-code: hacker" http://127.0.0.1:2222/api/system/setting
#    → HTTP 400，code=VALIDATION_ERROR，reason=APP_CODE_INVALID

# 3) 未认证映射
curl -s http://127.0.0.1:2222/api/project/lists
#    → ret=-1、code=AUTH_REQUIRED（HTTP 仍为 200，历史行为见 DV-04）

# 4) 访问日志
tail -n 3 storage/logs/platform-access.log

# 5) 契约与代码对账（改错方法名会失败）
npm run typecheck

# 6) 中间件单元/功能测试
PHP_BIN=D:/dt-run/php/php.exe npm run test
```

## 6 责任与待确认

| 事项                                  | 负责人         | 状态               |
| ------------------------------------- | -------------- | ------------------ |
| 应用侧契约中间件、OpenAPI、文档、Demo | 实训工坊负责人 | 已交付             |
| 统一身份（OIDC/BFF）                  | 论坛负责人     | 未交付，阻塞 DV-01 |
| 网关、限流、TLS                       | API 网关负责人 | 未交付，阻塞 DV-10 |
| 事件代理与投递                        | 集成负责人     | 未交付，阻塞 DV-11 |
