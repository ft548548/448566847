# 平台对接接口预留说明（网关 / 考试平台 / 成果广场 / 论坛账号确认）

> 版本：v1.2
> 依据：《实训平台\_统一身份与网关接入说明》V3.0（下称"接入说明"）、
> 《警院 WeChat 五平台统一开发与功能联动约束规范》V2.0、
> 《警院论坛账号确认接口 · 接口说明与四平台接入指南》v2.2.0、
> `docs/platform/data-dictionary-mapping.md`、`docs/platform/openapi.yaml`
> 状态标记沿用接入说明 §0.4：**✓ 已实现** / **△ 占位实现（契约已定，实现待接）** / **× 本期不做**

---

## 1 本次预留的范围

| 分层         | 内容                                                                  | 状态                                         |
| ------------ | --------------------------------------------------------------------- | -------------------------------------------- |
| 网关接入层   | `/api/v1/workshop/*` 新前缀、可信头验签、公开路径白名单、CORS、幂等键 | ✓                                            |
| 统一身份     | OAuth 授权码 + PKCE、服务端换码、sub 绑定与首登建号、服务端会话       | ✓（mock 可跑通；real 待身份中心参数）        |
| 论坛账号确认 | 密码代验式登录、失败节流、网关码 → 平台码翻译、建本平台会话           | ✓（**real 已通**，2026-11-06 实测 P6b PASS）  |
| 考试平台对接 | 准入回源（L2 签名）、投影表、事件收件箱、超时降级 pending             | ✓（准入）/ △（事件投递器）                   |
| 成果广场对接 | 项目 → 作品映射、作品推送、成员 sub 映射                              | △（契约已定，出站为 mock）                   |
| 事件出站     | `project.completed` 等事件的发件箱                                    | △（只落库，不投递，DV-11）                   |

**边界声明**：本次不改动任何存量接口的路径、参数与响应结构。存量 `/api/{module}/{method}` 的行为与改造前完全一致，v1 只是并列新增。

---

## 2 接口清单

### 2.1 对外接口（浏览器经网关调用）

| #   | 方法 | 路径                                           | 鉴权                    | 用途                                   |
| --- | ---- | ---------------------------------------------- | ----------------------- | -------------------------------------- |
| 1   | GET  | `/api/v1/workshop/auth/login`                  | 无（白名单）            | 发起授权，302 到身份中心               |
| 2   | GET  | `/api/v1/workshop/auth/oauth/callback`         | 无（靠 state）          | 服务端换码、建会话、302 回业务页       |
| 3   | POST | `/api/v1/workshop/auth/forum-verify`           | 无（白名单 + 失败节流） | 论坛账号确认登录（密码代验式）         |
| 4   | GET  | `/api/v1/workshop/auth/userinfo`               | 会话 / token            | 返回 `userId`(=sub) 与 `roles`         |
| 5   | POST | `/api/v1/workshop/auth/logout`                 | 会话                    | 清会话并返回身份中心登出地址           |
| 6   | GET  | `/api/v1/workshop/auth/logged-out`             | 无                      | 身份中心登出回跳落点                   |
| 7   | GET  | `/api/v1/workshop/health`                      | 无                      | 健康检查：构建版本、能力自述、依赖状态 |
| 8   | GET  | `/api/v1/workshop/recruitments/{id}/admission` | 会话 + `admission:read` | 招募的考试准入结论（回源 + 缓存）      |
| 9   | GET  | `/api/v1/workshop/projects`                    | 会话                    | 项目列表（REST 别名）                  |
| 10  | GET  | `/api/v1/workshop/projects/{id}`               | 会话                    | 项目详情（REST 别名）                  |
| 11  | GET  | `/api/v1/workshop/recruitments`                | 会话                    | 招募列表（REST 别名）                  |
| 12  | GET  | `/api/v1/workshop/recruitments/{id}`           | 会话                    | 招募详情（REST 别名）                  |

### 2.2 服务间接口（只给其他平台服务端）

| #   | 方法 | 路径                                              | 鉴权                             | 消费方             |
| --- | ---- | ------------------------------------------------- | -------------------------------- | ------------------ |
| 13  | GET  | `/api/v1/workshop/internal/projects/{id}`         | L2 签名 + `X-Auth-Mode: service` | 广场 / 档案 / 考试 |
| 14  | GET  | `/api/v1/workshop/internal/projects/{id}/work`    | 同上                             | 成果广场           |
| 15  | GET  | `/api/v1/workshop/internal/projects/{id}/members` | 同上                             | 成长档案           |
| 16  | POST | `/api/v1/workshop/internal/projects/{id}/publish` | 同上 + `Idempotency-Key`         | 广场 / 管理端      |
| 17  | POST | `/api/v1/workshop/internal/events`                | 同上 + `Idempotency-Key`         | 考试平台           |

---

## 3 公共约定

### 3.1 请求头

| 头名                 | 必填       | 规则                                                                                      |
| -------------------- | ---------- | ----------------------------------------------------------------------------------------- |
| `X-Request-Id`       | 否         | 8~128 位 `[A-Za-z0-9._:-]`；合法则沿用，否则回落 `req_<20位十六进制>`，响应体与响应头同值 |
| `X-App-Code`         | 是         | 固定 `workshop`；非法值 400 + `VALIDATION_ERROR` + `data.reason=APP_CODE_INVALID`         |
| `Idempotency-Key`    | 写操作必填 | 8~128 位 `[A-Za-z0-9._:-]`；作用域 `auth:idem:{subject}:{key}`，只缓存 2xx                |
| `X-User-Id` 等可信头 | 仅网关侧   | 必须配 `X-Internal-Sign`，**无签名一律不认**                                              |
| `X-Auth-Mode`        | 服务间必填 | `service`；`user` 访问 internal 接口返回 403                                              |
| `Content-Type`       | 写操作必填 | 严格等于 `application/json`；带 `; charset=` 会走表单分支                                 |

### 3.2 响应信封

v1 在存量 `ret/msg/data` 基础上**追加**两个字段，并补 `message` 别名：

```json
{
  "ret": 1,
  "msg": "success",
  "data": {},
  "code": "OK",
  "requestId": "req_7f3c9a2e5b1d4c80a9f2",
  "message": "success"
}
```

- 成功判据对外一律是 `code === "OK"`；`ret` 只给存量前端兼容。
- `data` 失败时**不是 null**，而是 `{}` 或 `[]`。
- `message` 与 `msg` 同值，供考试平台通用客户端读取。

### 3.3 与存量接口的两处差异

| 项       | 存量 `/api/{module}/{method}`                            | v1 `/api/v1/workshop/*`                   |
| -------- | -------------------------------------------------------- | ----------------------------------------- |
| 未登录   | HTTP 200 + `ret:-1` + `AUTH_REQUIRED`（DV-04 历史行为）  | **HTTP 401** + `AUTH_REQUIRED`            |
| 分页结构 | Laravel 原生（`current_page`/`per_page`/`data`/`links`） | `{lists, total, page, pagesize}`          |
| 字段命名 | snake_case（`pagesize`）                                 | camelCase（`pageSize`），同一接口内不混用 |

---

## 4 对外接口详述

### 4.1 `GET /auth/login`

**用途**：发起统一身份授权。

| 参数       | 位置  | 类型   | 必填 | 说明                                                       |
| ---------- | ----- | ------ | ---- | ---------------------------------------------------------- |
| `redirect` | query | string | 否   | 站内回跳路径，必须以 `/` 开头；`//evil.com` 等一律回落 `/` |

**响应**：`302`。real 模式跳身份中心 `/oauth/authorize`（带 `state`、`code_challenge`、`code_challenge_method=S256`）；mock 模式跳本平台回调以打通时序。

**错误**：无（任何异常都降级为重新发起授权）。

**实现要点**：`state` 为 32 字节随机，绑会话缓存 10 分钟，一次性消费。

### 4.2 `GET /auth/oauth/callback`

**用途**：授权回跳。校验 `state` → 服务端换码 → 取 `userinfo` → 按 sub 绑定/建号 → 建会话 → 302。

| 参数    | 位置  | 类型   | 必填 |
| ------- | ----- | ------ | ---- |
| `code`  | query | string | 是   |
| `state` | query | string | 是   |

**成功**：`302` + `Set-Cookie: workshop_sid=...`（HTTPS 部署为 `__Host-workshop_sid`，`HttpOnly; Secure; SameSite=Lax; Path=/`）。**响应体与 Cookie 中都不出现 access_token**。

**错误**：

| 场景                           | HTTP | code                  | data.reason             |
| ------------------------------ | ---- | --------------------- | ----------------------- |
| `state` 不存在/已消费          | 400  | `VALIDATION_ERROR`    | `STATE_INVALID`         |
| 缺少 `code`                    | 400  | `VALIDATION_ERROR`    | `CODE_MISSING`          |
| 换码失败（含 `invalid_grant`） | 401  | `AUTH_REQUIRED`       | `TOKEN_EXCHANGE_FAILED` |
| `userinfo` 缺 `sub`/`userId`   | 503  | `SERVICE_UNAVAILABLE` | `USERINFO_INVALID`      |
| sub 已绑定但 `status=0`        | 403  | `FORBIDDEN`           | —                       |
| 邮箱已存在本地账号             | 409  | `CONFLICT`            | —                       |

> 换码**不重试**：`code` 一次性，超时重试必然 `invalid_grant`，且首个请求可能已在身份中心侧成功。
> `userinfo` 缺 sub 时**不降级放行**：临时 ID 会产生一批无法与论坛对账的脏数据。

### 4.3 `GET /auth/userinfo`

**用途**：恢复页面身份（刷新后唯一身份来源）。

**出参 `data`**：

| 字段                                   | 类型            | 说明                                         |
| -------------------------------------- | --------------- | -------------------------------------------- |
| `userId`                               | string          | **论坛 sub 原值**，不是本地 userid           |
| `roles`                                | array\<string\> | 统一角色（已由网关映射，业务侧不再翻译）     |
| `nickname` / `email` / `avatar`        | string          | 展示用                                       |
| `localUserId`                          | integer         | 本地主键，**仅供本平台内部**，禁止跨平台使用 |
| `source`                               | string          | `session` / `gateway` / `token`              |
| `authMode`                             | string          | `user` / `service`                           |
| `clientId`                             | string          | 客户端标识，审计用                           |
| `session.active` / `session.expiresIn` | bool / int      | 会话状态与剩余秒数（绝对上限）               |

**错误**：401 `AUTH_REQUIRED`（`data.reason=AUTH_REQUIRED`）。

### 4.4 `POST /auth/logout`

**用途**：退出。顺序固定：先删本地会话，再返回登出地址。

**出参**：`data.logoutUrl`（real 模式为身份中心 `/protocol/logout?...`，mock 为空串）、`data.mock`。

**幂等**：会话已失效时同样返回 200。

### 4.5 `GET /health`

**用途**：探活与能力自述。**不返回任何密钥、连接串、文件路径**。

**出参 `data`**：

| 字段                                       | 类型          | 说明                                    |
| ------------------------------------------ | ------------- | --------------------------------------- |
| `status`                                   | string        | `ok` / `degraded`（数据库或缓存不可用） |
| `service`                                  | string        | 固定 `workshop`                         |
| `version` / `buildTime`                    | string        | `APP_VERSION` / `APP_BUILD_TIME`        |
| `capabilities.ssoMode`                     | string        | `mock` / `real`                         |
| `capabilities.examMock` / `showcaseMock`   | bool          | 未配置 base_url 时为 true               |
| `capabilities.signVerify`                  | bool          | 是否启用可信头验签                      |
| `capabilities.cookieName` / `secureCookie` | string / bool | 便于核对"本地通了线上不通"              |
| `checks.database` / `checks.cache`         | string        | `ok` / `down` / `degraded`              |
| `time` / `timestamp`                       | string / int  | RFC 3339 与秒级时间戳                   |

### 4.6 `GET /recruitments/{id}/admission`

**用途**：给出该招募的考试准入结论。判定顺序固定，不可颠倒。

```
course_id 为空 ──────────────────────► required=false（不触发回源）
   │
   ├─ 本地投影（expires_at 未过期，≤5 分钟）──► eligible + reason（source=projection）
   ├─ 结果缓存 admission:{subject}:{courseId}（60 秒）──► source=cache
   └─ 回源 GET /api/v1/exam/admissions/{subject}/{courseId}（1.5 秒超时）
          ├─ eligible=true  ──► 放行，写缓存
          ├─ eligible=false ──► 拒绝，reason 原样返回
          └─ 超时 / 5xx ─────► reason=pending，HTTP 200
```

**出参 `data`**（`Admission` schema）：

| 字段            | 类型       | 说明                                                                                                    |
| --------------- | ---------- | ------------------------------------------------------------------------------------------------------- |
| `recruitmentId` | integer    |                                                                                                         |
| `courseId`      | string     | 原值存储；空串表示不校验                                                                                |
| `subject`       | string     | 当前用户 sub；未绑定时缺省                                                                              |
| `required`      | bool       | false 表示未绑定课程                                                                                    |
| `eligible`      | bool       | 结论                                                                                                    |
| `reason`        | string     | `passed` / `not_started` / `failed` / `violation` / `pending` / `prerequisite_missing` / `not_required` |
| `reasonText`    | string     | 服务端统一生成的中文提示，下游不要自写第二套                                                            |
| `score`         | int / null | 仅展示，不落业务表                                                                                      |
| `ruleVersion`   | string     | 规则版本，供下游复算                                                                                    |
| `source`        | string     | `remote` / `cache` / `projection` / `mock` / `local`                                                    |
| `cached`        | bool       | 是否命中缓存/投影                                                                                       |
| `checkedAt`     | string     | RFC 3339 带时区                                                                                         |
| `mock`          | bool       | true 表示演示数据，**不得当作真实回源结论**                                                             |

**错误**：401 未登录；403 `ADMISSION_FORBIDDEN`（非发布者/非管理员）；404 `RECRUITMENT_NOT_FOUND`（含软删除）；409 `SUBJECT_NOT_BOUND`（token 身份无 sub，无法回源）。

**权限点**：`admission:read`。当前投影为「发布者 ∪ 管理员」，属 DV-06 登记的"无授权表"补偿实现。

### 4.7 REST 别名（`projects` / `recruitments`）

**用途**：给接入方 REST 风格入口，复用既有 RPC 逻辑（权限与数据归属判定完全一致）。

**参数**：`page`、`pageSize`、`keyword`、`status`（招募）、`archived`（项目，传 `1` 查已完成）。

**出参**：`data.{lists,total,page,pagesize}`。

> 实现方式：v1 把参数拍平后转发到 `ProjectController::lists()` 等方法，并把当前身份桥接成内部 token。
> 前端整体切到会话后，桥接可删除（代码内标记 `[SESSION-MIGRATION]`）。

### 4.8 `POST /auth/forum-verify`

**用途**：用警院论坛账号密码登录本平台。平台经网关调用论坛的只读账号确认接口，
`confirmed === true` 时建立**本平台自己的会话**——平台拿不到论坛 token，也不保存密码。

**入参**：`login`（1~100，用户名或邮箱）、`pass`（8~32）、`login_type`（`auto`/`username`/`email`，非法值 400）。

**出参**：`userId`（论坛 `user_id`，跨平台 sub）、`localUserId`、`username`、`displayName`、
`role`、`emailMasked`、`accountSource`、`created`、`session`、`source`；mock 模式额外有 `mock=true`。

**错误**：`400` 参数不合法 / `401` 账号密码不符或账号停用 / `429` 失败次数超限（带 `Retry-After`）/
`503` 渠道故障（**不是**密码错误）。

> 出站契约、网关码映射、建号与权限取舍见 §6.4。这条链路是**过渡方案**，
> 终态是 §4.1 的授权码 + PKCE（平台不接触密码，见 DV-01 / 文档 §九.1）。

---

## 5 服务间接口详述

### 5.1 鉴权

两层，缺一不可：

1. **网关注入的可信头签名**（入站）

   ```
   canonical = X-User-Id \n X-User-Role \n X-Client-Id \n X-Token-Exp \n
               X-Request-Id \n X-Auth-Mode \n X-Timestamp
   X-Internal-Sign = hex(HMAC-SHA256(GATEWAY_INTERNAL_SECRET, canonical))
   ```

   字段缺失以空串占位，顺序固定不可调整。时间窗 ±300 秒。

   **密钥来源**（按平台独立，每平台一把 32 字节，与 L2 AppSecret 不复用）：

   | 配置项 | 说明 |
   | --- | --- |
   | `GATEWAY_INTERNAL_SECRET` | 直填，优先级最高 |
   | `GATEWAY_INTERNAL_SECRET_FILE` | 文件注入（容器 Secret / 600 挂载），直填为空时才读，读取后统一 `trim` |

   两者由 `GatewaySigner::internalSecret()` 统一读取，`GatewayTrust`、`health`、`gateway:probe` 共用同一入口。
   密钥带空格、被换行截断或长度不足 16 时返回 `SECRET_MALFORMED`，与 `SIGN_MISMATCH` 区分——前者改配置，后者查对端算法。

2. **服务态声明**：`X-Auth-Mode: service`。`user` 模式一律 403 `USER_MODE_REJECTED`。

**拒绝语义**：

| 场景                | HTTP | data.reason                      |
| ------------------- | ---- | -------------------------------- |
| 签名缺失            | 403  | `SIGN_MISSING`                   |
| 签名不一致          | 403  | `SIGN_MISMATCH`                  |
| 密钥形态不合法      | 403  | `SECRET_MALFORMED`               |
| 未配置密钥          | 403  | `SECRET_MISSING`                 |
| 时间戳越窗          | 403  | `CLOCK_SKEW`                     |
| 来源不在可信网段    | 403  | `NOT_TRUSTED_NETWORK`            |
| 时间戳超窗          | 403  | `CLOCK_SKEW`（附 `skewSeconds`） |
| 缺 `X-Timestamp`    | 403  | `TIMESTAMP_MISSING`              |
| 服务端未配密钥      | 403  | `SECRET_MISSING`                 |
| 来源不在可信网段    | 403  | `NOT_TRUSTED_NETWORK`            |
| 未验签调用 internal | 403  | `SERVICE_ONLY`                   |

> 安全纪律：**只有验签通过才信任 `X-User-Id`**。`GATEWAY_VERIFY_SIGN=false` 时虽然放行（调试开关），但仍会打印告警；`GATEWAY_DIRECT_ACCESS=allow` 时无签名请求按匿名处理，绝不采信身份头。

### 5.2 `GET /internal/projects/{id}`

**用途**：项目摘要，供广场、档案、考试平台读取。

**出参**：`projectId`、`name`、`summary`（纯文本 ≤500 字）、`status`（`in_progress`/`completed`/`submitted`）、`ownerSubject`（未绑定为 **null**）、`memberCount`、`createdAt`、`completedAt`、`submittedAt`、`updatedAt`、`sourcePlatform`。

> `ownerSubject=null` 是明确语义：该用户尚未绑定统一身份。**不允许**用 userid 顶替（DV-16）。

### 5.3 `GET /internal/projects/{id}/work`

**用途**：项目 → 作品草稿（广场专用）。

**字段映射**：

| Work 字段        | 来源                                |
| ---------------- | ----------------------------------- |
| `workId`         | 恒为 `null`（发布后由广场回填）     |
| `sourcePlatform` | `workshop`                          |
| `sourceEntityId` | `project_{id}`                      |
| `title`          | `projects.name`                     |
| `summary`        | `projects.desc` 去标签后截断 500 字 |
| `authorSubjects` | 项目成员中**已绑定**的 sub          |
| `unboundMembers` | 未绑定成员数（供广场提示）          |
| `status`         | `draft`                             |
| `publishedAt`    | `null`                              |

> Work 的权威平台是广场，本平台不持有该实体，不做合格判定（`data-dictionary-mapping.md` §6）。

### 5.4 `GET /internal/projects/{id}/members`

**用途**：项目参与记录，供成长档案消费。

**出参**：`members[]`，每项 `{subject, unbound, role, joinedAt}`。`role` 为 `owner` / `member`（DooTask 无更细角色，见 data-dictionary §3）。

### 5.5 `POST /internal/projects/{id}/publish`

**状态**：**△ 占位实现**。未配置 `SHOWCASE_BASE_URL` 时返回 `mock=true` 与模拟 `workId`。

**请求**：无 body（项目 ID 在路径），必须带 `Idempotency-Key`。

**出参**：`projectId`、`workId`、`status`、`mock`。

**错误**：409 `NO_BOUND_AUTHOR`（成员均未绑定 sub）；503 `SHOWCASE_PUBLISH_FAILED`（广场不可用，不影响项目本身状态）。

### 5.6 `POST /internal/events`

**用途**：接收 `exam.passed` / `exam.failed`。

**请求体**（`EventEnvelope`，10 个必填字段）：

```json
{
  "eventId": "evt_01j8zq7m2k",
  "eventType": "exam.passed",
  "eventVersion": "1.0",
  "occurredAt": "2026-11-05T14:00:00Z",
  "sourcePlatform": "exam",
  "sourceEntityId": "record_5001",
  "actorId": "system",
  "correlationId": "corr-001",
  "requestId": "req-src-001",
  "data": {
    "userId": "usr_01J8ZQ7M2K",
    "courseId": "course_9",
    "passed": true,
    "score": 92,
    "ruleVersion": "v1"
  }
}
```

**处理流程**：校验封套 → 查 `exam_event_inbox` 幂等 → 事务内写 inbox + upsert 投影 → 失效 `admission:{subject}:{courseId}` 缓存。

**出参**：`eventId`、`duplicated`（true 表示重复投递被幂等挡下）。

**错误**：

| 场景                        | HTTP | code               | data                     |
| --------------------------- | ---- | ------------------ | ------------------------ |
| 封套缺字段                  | 400  | `VALIDATION_ERROR` | `missing: [...]`         |
| 事件类型不在目录            | 400  | `VALIDATION_ERROR` | `reason=EVENT_REJECTED`  |
| 版本不是 1.0                | 400  | `VALIDATION_ERROR` | 同上                     |
| `sourcePlatform != exam`    | 400  | `VALIDATION_ERROR` | 同上                     |
| 缺 `data.userId`/`courseId` | 500  | `INTERNAL_ERROR`   | 事务回滚，投影不写脏数据 |

> 任一项不满足即拒收，不做"尽力解析"：封套不完整的消息一旦入库，后续无法补救。

---

## 6 出站契约（本平台作为调用方）

> 出站一律经已部署网关 `GATEWAY_BASE_URL`（当前 `http://8.138.246.181`）。
> 服务间调用必须落在 `/{平台}/internal/*` 路由上——非 internal 路径属于 L1 用户态路由，
> 带 L2 签名也会被 401 拒绝。

### 6.1 考试平台：准入查询

```
GET {EXAM_BASE_URL}/api/v1/exam/internal/admissions/{subject}/{courseId}
X-App-Key: jy-workshop-svc
X-App-Code: workshop
X-Timestamp: <秒>
X-Nonce: <32位十六进制随机>
X-Sign-Alg: HMAC-SHA256
X-Signature: hex(HMAC-SHA256(EXAM_APP_SECRET, canonical))
X-Request-Id: <当前请求追踪号>
```

`canonical` 取决于 `GATEWAY_L2_VERSION`：

| 版本                              | 规范串（`\n` 连接）                                                                                   |
| --------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `gateway`（默认，对齐已部署网关） | `appKey` / `timestamp` / `nonce` / `METHOD` / `path` / 排序并编码后的 query / `sha256(body)` 十六进制 |
| `spec`（接入说明 V3.0 §12.4）     | `METHOD` / `path` / 原始 query / body 原文                                                            |

> 两版的差别见 DV-23。`query` 的规范化不是"排序原始片段"，而是先解码、按 `(name, value)` 升序、
> 再按 RFC1738 重新编码（空格 →`+`），否则与网关的 `parse_qsl + urlencode` 结果不一致。

**超时**：`EXAM_TIMEOUT_MS=1500`（必须短于网关 2 秒，留 500ms 写 pending 响应）。
**重试**：不重试（读接口可退避 2 次，但准入是用户同步等待，重试只会放大延迟）。
**降级**：超时/5xx → `pending`；401/403 → `pending` + `serviceUnavailable` 标记 + 告警日志；**任何情况都不判"不合格"**。

**响应解析**：以 `code === "OK"` 为成功判据（不看 HTTP 200）；`reason` 不在 6 个枚举内时按 `pending` 处理。
网关自身错误是**数字码**（`40101`/`40105`/`50301` 等），与本平台的字符串码不同码系，一律按失败处理，不透传给用户。

### 6.2 成果广场：作品推送

```
POST {SHOWCASE_BASE_URL}/api/v1/plaza/internal/works
（L2 签名同上，body 为 Work JSON）
```

> 网关侧成果广场的前缀是 `plaza` 而非 `showcase`（DV-25）。
> 原 `/api/v1/showcase/works` 在网关上是未登记路径，会被默认拒绝规则拦成 403/40300。

**状态**：△ 占位。路径、字段、签名方式已按网关路由表与接入说明 §6.3 调整；**广场侧具体字段契约尚未提供，字段名以本文件与 `openapi.yaml#/components/schemas/Work` 为准，待对方确认后可能调整**（DV-17）。

**失败处理**：不抛异常、不阻断项目提交/完成；返回 `SERVICE_UNAVAILABLE` 并记日志。

### 6.3 网关接入参数与自检

| 参数          | 作用                                   | 配置项                                                            |
| ------------- | -------------------------------------- | ----------------------------------------------------------------- |
| 网关地址      | 出站基地址、自检目标                   | `GATEWAY_BASE_URL`                                                |
| L2 规范串版本 | 决定签名算法分支                       | `GATEWAY_L2_VERSION`（`gateway` / `spec`）                        |
| 调用方凭据    | L2 五头中的 AppKey 与 HMAC 密钥        | `EXAM_APP_KEY` / `EXAM_APP_SECRET`（广场、账号确认同构，见 §6.4） |
| 入站验签密钥  | 本平台作为上游时校验 `X-Internal-Sign` | `GATEWAY_INTERNAL_SECRET`（已配置，来源 `secrets.json → internal.workshop.secret`；亦支持 `_FILE` 注入） |
| 直连策略      | 生产必须 `reject`，本地调试可 `allow`  | `GATEWAY_DIRECT_ACCESS`                                           |

一键自检：

```
php artisan gateway:probe                 # 用当前 .env 配置
php artisan gateway:probe --app-key=... --app-secret=...
php artisan gateway:probe --token=<L1 访问令牌>   # 追加验证用户态链路
FORUM_PROBE_PASS=<密码> php artisan gateway:probe --login=<论坛账号>   # 追加验证账号确认
```

> 密码走环境变量而不是 `--pass=`：命令行参数会留在 shell 历史与进程列表里。

六项判定与对应处置：

| 项              | 通过条件                                   | 不通过说明                                                                          |
| --------------- | ------------------------------------------ | ----------------------------------------------------------------------------------- |
| P1 网关可达     | `GET /` 返回 200                           | 网络/安全组/网关未启动                                                              |
| P2 路由已登记   | `/api/v1/workshop/ping` 返回 401 + `40101` | 路由未注册或被默认拒绝                                                              |
| P3 默认拒绝生效 | 未登记路径返回 403 + `40300`               | 网关缺少兜底规则，存在越权风险                                                      |
| P4 L2 服务签名  | 返回 200                                   | `40105` 密钥错或版本错；`40106` 时钟偏差；`40107` nonce 重放；`40108` AppKey 未预置 |
| P5 L1 令牌链路  | 带 Bearer 返回 200（需 `--token`）         | 令牌无效、自省失败或上游不可达                                                      |
| P6 账号确认路由 | 无签名 POST 返回 401 + `40105`             | `40300` 路由未登记；`404` 网关版本未含 `account-verify`                             |
| P7 入站验签密钥 | 已配置（64 位）且用当前密钥签样例头可验回，显示指纹 | `SKIP` 未配置（网关回源将 403 `SECRET_MISSING`）；`FAIL` 为占位值或长度不符（`SECRET_MALFORMED`） |

> P4 与 P6 查的是**不同路由的白名单**：`account-verify` 与 `workshop/internal/*` 各自维护
> `l2([...])` 客户端列表，一侧通过不代表另一侧通过。实测两者当前状态并不一致（DV-26）。

**网关侧配对现状（2026-11-06 实测）**：

| 事项 | 现状 | 处置 |
| --- | --- | --- |
| `workshop-internal` 白名单 | 真实签名返回 `401/40108`；同一把 Key 在 `learning`/`plaza` 通过 | 网关侧 `l2([...])` 追加 `jy-workshop-svc`（CR-01） |
| 匿名入口路由 | 匿名 `health`、`auth/forum-verify` 均 `401/40101` | 新增 `workshop-auth` 匿名路由（CR-02 / DV-30） |
| upstream | 三条 `workshop-*` 均指向 `127.0.0.1:8000` 桩服务 | 改为 `8.134.60.240:8080`（CR-03） |

完整变更项、验收命令与回退方式见 **`docs/platform/gateway-change-request.md`（CR-20261106-01）**，可直接转交网关运维。

### 6.4 论坛账号确认（密码代验式）

```
POST {FORUM_VERIFY_BASE_URL}/answer/api/v1/account/verify
X-App-Key: jy-workshop-svc
X-Timestamp / X-Nonce / X-Sign-Alg / X-Signature  同 §6.1
X-Request-Id: <当前请求追踪号>
Content-Type: application/json

{"login":"admin@jspi.edu.cn","pass":"...","login_type":"auto","client_id":"jy-workshop-web"}
```

> **路径不在 `/api/v1/workshop/*` 下**：该路由由网关回环到论坛（`127.0.0.1:9088`），
> 平台既不直连论坛，也不经过本平台的 upstream。这与 §6.1 / §6.2 的 `internal` 前缀规律不同——
> 它属于网关自己的 `account-verify` 路由（优先级 2500），不是某个平台的上游。

依据《警院论坛账号确认接口 · 接口说明与四平台接入指南》v2.2.0。

| 项       | 值                                                                                   |
| -------- | ------------------------------------------------------------------------------------ |
| 成功判据 | `code === 200 && data.confirmed === true`，只认 `confirmed`，平台不做二次比对        |
| 超时     | `FORUM_VERIFY_TIMEOUT_MS=2500`（必须短于网关该路由的 3 s）                           |
| 重试     | **不重试**：签名类失败重试无意义，密码类失败重试等同撞库                             |
| 请求体   | 先序列化成字符串再签名，**发送的就是签名的那一串**（`withBody`，不用 `post(array)`） |
| 密码     | 只在内存中透传一次：不落库、不写日志、不进异常信息、不回显                           |
| 节流     | 同一 `(login, ip)` 连续失败 5 次锁 5 分钟，响应带 `Retry-After`                      |

**网关响应码 → 平台处置**：

| 网关码                          | 含义                         | 平台 `code` / `data.reason`                                    | HTTP    |
| ------------------------------- | ---------------------------- | -------------------------------------------------------------- | ------- |
| `200` + `confirmed=true`        | 确认通过                     | `OK`                                                           | 200     |
| `40104`                         | 账号不存在/密码错/账号停用   | `AUTH_REQUIRED` / `invalid_credentials`、`account_unavailable` | 401     |
| `40001` / `41300`               | 参数非法 / 体积超限          | `VALIDATION_ERROR` / `invalid_params`、`payload_too_large`     | 400/413 |
| `42901`                         | 触发限流                     | `SERVICE_UNAVAILABLE` / `rate_limited`                         | 429     |
| `40105` `40106` `40107` `40108` | 签名 / 时钟 / nonce / AppKey | `SERVICE_UNAVAILABLE` / `channel_misconfigured`                | 503     |
| `40303` `40300`                 | 内部签名失败 / 路由未登记    | 同上                                                           | 503     |
| `50200` `50400` `50000`         | 上游不可达 / 超时 / 内部错误 | 同上                                                           | 503     |
| 连接异常 / 超时                 | 网络层                       | `SERVICE_UNAVAILABLE` / `channel_unavailable`                  | 503     |

> **最关键的一条**：除 `40104` 外的一切失败都**不得**报成"账号或密码错误"。
> 把 `40105`（我方签名错）报成密码错，用户会反复重试一个永远不会成功的操作，
> 而运营侧看不到任何告警——这是这类接口最容易犯、也最难排查的错。
> 联调时看 `data.gatewayCode` 即可判断卡在网关还是论坛。

**建号的取舍**：接口只返回掩码邮箱（`a***@jspi.edu.cn`），它不是合法邮箱，**不能用来建本地账号**。
因此按 `sub` 生成占位邮箱 `{user_id}@sso.local`，`userinfo` 会回显该占位值（DV-27）。

**不写本地权限**：`data.role`（`student`/`teacher`/`admin`）只存入会话供展示，
**不写入** `pre_users.identity`——本地权限体系独立，直接混用会造成越权（与 SSO 路径同一纪律）。

**mock 语义**：未配 `FORUM_APP_SECRET` 时自动 mock，`confirmed` 恒为 true 并回传 `data.mock=true`。
它只能打通"确认 → 建会话"，**不能**作为接入验收依据；`health.data.forumVerify.mock` 会暴露当前状态。

### 6.5 事件出站

`PlatformEventService::enqueue()` 写入 `platform_event_outbox`（`status=0`），**本期无投递器**（DV-11）。
目录预留：`project.completed`、`project.submitted`、`recruitment.converted`。
投递器接入后按 `status=0` 拉取，成功置 1、失败置 2 并记 `last_error`，业务代码无需改动。

---

## 7 错误码与异常处理约定

### 7.1 九个规范码（v1 的 HTTP 映射）

| code                  | HTTP | 触发条件                                | 典型 data.reason                                         |
| --------------------- | ---- | --------------------------------------- | -------------------------------------------------------- |
| `OK`                  | 200  | 成功                                    | —                                                        |
| `VALIDATION_ERROR`    | 400  | 参数缺失/非法、AppCode 非法、封套不完整 | `APP_CODE_INVALID` / `STATE_INVALID` / `EVENT_REJECTED`  |
| `AUTH_REQUIRED`       | 401  | 未登录、令牌失效                        | `AUTH_REQUIRED` / `TOKEN_EXCHANGE_FAILED`                |
| `FORBIDDEN`           | 403  | 无权限、验签失败、非服务态              | `SIGN_MISMATCH` / `SERVICE_ONLY` / `ADMISSION_FORBIDDEN` |
| `NOT_FOUND`           | 404  | 对象不存在、方法不存在                  | `RECRUITMENT_NOT_FOUND`                                  |
| `CONFLICT`            | 409  | 状态冲突、幂等处理中、邮箱已存在        | `IDEMPOTENCY_IN_PROGRESS` / `SUBJECT_NOT_BOUND`          |
| `BUSINESS_ERROR`      | 200  | 业务规则拒绝                            | （沿用业务 msg）                                         |
| `SERVICE_UNAVAILABLE` | 503  | 依赖不可用（身份中心、考试平台、广场）  | `USERINFO_INVALID` / `SHOWCASE_PUBLISH_FAILED`           |
| `INTERNAL_ERROR`      | 500  | 未预期异常                              | `EVENT_REJECTED`（投影缺关键字段）                       |

> `AUTH_TOKEN_EXPIRED` 本平台**永不产出**（无产出点），接入方不要为它写专门分支。

### 7.2 三条硬约定

1. **HTTP 与 code 必须成对**：v1 不再出现 200 + `FORBIDDEN`。存量接口的历史行为（200 + `AUTH_REQUIRED`）保持不动，属 DV-04。
2. **失败时 `data` 不为 null**：`{}` 或 `[]`。对端若以 `data === null` 判失败会误判。
3. **`reason` 是契约**：`data.reason` 用于程序分支，`msg`/`message` 只用于展示。下游不得自造第二套 reason 映射。

### 7.3 超时与降级矩阵

| 依赖              | 超时 | 失败时                   | 是否重试                 |
| ----------------- | ---- | ------------------------ | ------------------------ |
| 身份中心换码      | 3s   | 401 让用户重走授权       | 否（code 一次性）        |
| 身份中心 userinfo | 3s   | 503，不放行              | 否                       |
| 考试平台准入      | 1.5s | `pending`，HTTP 200      | 否                       |
| 成果广场作品推送  | 2s   | 503，不影响主流程        | 否（有幂等键可人工重放） |
| 数据库 / 缓存     | —    | `health.status=degraded` | —                        |

### 7.4 CORS 约定（易踩坑，单独说明）

| 场景                                        | 响应头 `Access-Control-Allow-Origin`                  |
| ------------------------------------------- | ----------------------------------------------------- |
| `Origin` 命中 `GATEWAY_CORS_ORIGINS` 白名单 | 回显该来源，并带 `Allow-Credentials: true`            |
| `Origin` 不在白名单                         | 固定写 `null`（规范里的"不透明来源"，浏览器必然拦截） |
| 未配置白名单（默认空）                      | 同上，等于拒绝所有跨域                                |
| 请求无 `Origin` 头                          | 不写该头（同源调用无需 CORS）                         |

**为什么不写 `*`**：`*` 与 `Allow-Credentials: true` 互斥，浏览器会直接拒绝带 Cookie 的跨域请求。

**为什么要一个全局中间件**（`GatewayCors`）：`config/cors.php` 里 `allowed_origins=['*']` 是给存量接口用的，
它由 `Fruitcake\Cors\HandleCors` 实现，并额外挂了 `RequestHandled` 事件，在**整个管道结束之后**再补一次头——
判据是"响应里还没有 `Access-Control-Allow-Origin`"。因此路由中间件（`GatewayTrust`）写的值会被它覆盖，
而"干脆不写"会被它补成 `*`。`GatewayCors` 注册在 `HandleCors` **之前**（更外层），只接管 `api/v1/*`，
把该头改写成上表的确定值，其余路径完全不介入。

**OPTIONS 预检**：`HandleCors` 会直接返回 204，路由中间件根本执行不到。为此 `routes/web.php`
在 v1 组内补了一条 `Route::options('{any}')` 兜底路由（否则 Laravel 在路由匹配阶段就按方法过滤掉，返回 405）。

---

## 8 占位实现与扩展点

| 位置                                      | 现状                                         | 接入时要改什么                                                                         |
| ----------------------------------------- | -------------------------------------------- | -------------------------------------------------------------------------------------- |
| `IdentityProvider::exchangeCode/userinfo` | 已实现，`SSO_MODE=real` 即启用               | 配 `SSO_BASE_URL`/`CLIENT_ID`/`CLIENT_SECRET`/`REDIRECT_URI`，域名与证书须先就位       |
| `IdentityProvider::discovery`             | 已实现（缓存 1 小时）                        | 身份中心提供 Discovery 即自动生效；无 Discovery 时回落配置路径                         |
| `ExamClient::requestRemote`               | 已实现                                       | 配 `EXAM_BASE_URL` + `EXAM_APP_SECRET`，把 `EXAM_MOCK` 置空                            |
| `ShowcaseClient::publishWork`             | **占位**（mock 返回模拟 `workId`）           | 配 `SHOWCASE_BASE_URL` + 凭据；核对广场契约字段后可能需要微调 `buildWorkFromProject()` |
| `PlatformEventService::enqueue`           | 只落库，无投递器                             | 加队列消费者 + 投递器；表结构已就绪，业务侧无需改                                      |
| `WorkshopController::injectToken`         | 身份桥接（`[SESSION-MIGRATION]`）            | 前端整体切到会话后，把 `User::auth()` 改为读会话，然后删除该方法                       |
| 前端登录页                                | 未改动，仍用邮箱密码                         | 切换到 `GET /api/v1/workshop/auth/login`，下线本地表单与 localStorage token            |
| CSRF                                      | 会话层已提供 `WorkshopSession::verifyCsrf()` | 前端接入后对写接口强制校验 `X-CSRF-Token`；当前 logout 未强制（低风险）                |
| 限流                                      | 依赖网关                                     | 网关侧按 §5.6 配置；应用侧未实现令牌桶                                                 |

---

## 9 文件清单与改动点

### 9.1 新增

| 文件                                                                                     | 作用                                                                            |
| ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `config/platform.php`                                                                    | 四组配置：sso / session / gateway / exam / showcase / idempotency               |
| `app/Services/GatewaySigner.php`                                                         | L1 可信头签名（入站校验）与 L2 出站签名，规范化串顺序固化                       |
| `app/Services/GatewayIdentity.php`                                                       | 身份上下文：会话 → 可信头 → token 三级解析，只认验签后的 `X-User-Id`            |
| `app/Services/WorkshopSession.php`                                                       | 服务端会话（Redis）+ HttpOnly Cookie + CSRF + OAuth state                       |
| `app/Services/IdentityProvider.php`                                                      | 身份中心客户端：授权 URL、换码、userinfo、登出、Discovery                       |
| `app/Services/IdentityBinder.php`                                                        | sub ↔ userid 绑定、首登建号（插入即冲突回查）                                   |
| `app/Services/ExamClient.php`                                                            | 准入回源：投影 → 缓存 → 回源，超时降级 pending                                  |
| `app/Services/ShowcaseClient.php`                                                        | 成果广场客户端：Work 映射、推送、撤回、查询（占位）                             |
| `app/Services/ForumAccountClient.php`                                                    | 论坛账号确认客户端：L2 五头出站、网关码翻译、失败节流、密码不外泄               |
| `app/Services/PlatformEventService.php`                                                  | 事件发件箱（enqueue）+ 收件箱（封套校验、幂等、投影 upsert）                    |
| `app/Services/PlatformResponse.php`                                                      | v1 信封与 code↔HTTP 映射，含 `message` 别名                                     |
| `app/Http/Middleware/GatewayTrust.php`                                                   | 验签、白名单、来源网段、CORS、安全审计日志                                      |
| `app/Http/Middleware/GatewayCors.php`                                                    | 只接管 `api/v1/*` 的 CORS 校正：推翻全局 `*`，按白名单写确定值（§7.4）          |
| `app/Http/Middleware/Idempotency.php`                                                    | Idempotency-Key 原子占位 + 结果回放                                             |
| `app/Http/Controllers/Api/WorkshopController.php`                                        | 对外 12 个接口（7 主 + 4 别名 + logged-out）                                    |
| `app/Http/Controllers/Api/WorkshopInternalController.php`                                | 服务间 5 个接口                                                                 |
| `app/Models/UserIdentityBinding.php`                                                     | 绑定表模型                                                                      |
| `app/Models/ExamAdmissionProjection.php`                                                 | 准入投影模型                                                                    |
| `app/Models/ExamEventInbox.php`                                                          | 事件收件箱模型（主键 event_id，字符串非自增）                                   |
| `app/Models/PlatformEventOutbox.php`                                                     | 事件发件箱模型                                                                  |
| `database/migrations/2026_11_05_000010_create_user_identity_binding_table.php`           | `pre_user_identity_binding`                                                     |
| `database/migrations/2026_11_05_000011_create_exam_admission_tables.php`                 | `pre_exam_admission_projection` + `pre_exam_event_inbox`                        |
| `database/migrations/2026_11_05_000012_add_platform_fields_to_recruitments_and_logs.php` | `recruitments.course_id`、`project_logs.request_id`                             |
| `database/migrations/2026_11_05_000013_create_platform_event_outbox_table.php`           | `pre_platform_event_outbox`                                                     |
| `docs/platform/gateway-interfaces.md`                                                    | 本文件                                                                          |
| `app/Console/Commands/GatewayProbe.php`                                                  | `php artisan gateway:probe`：连通/路由/L1/L2/账号确认五段自检，按错误码定位卡点 |
| `tests/Feature/WorkshopGatewayTest.php` 等                                               | 见第 10 节                                                                      |

### 9.2 修改

| 文件                                                | 改动                                                                                                                                               | 影响面                                   |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| `routes/web.php`                                    | 新增 `/api/v1/workshop/*` 路由组（17 条）+ 1 条 OPTIONS 预检兜底；存量路由 **零改动**                                                              | 仅影响新前缀                             |
| `app/Http/Kernel.php`                               | 注册路由中间件 `gateway.trust`、`idempotency`；全局栈新增 `GatewayCors`（置于 `HandleCors` 之前）                                                  | 全局中间件只对 `api/v1/*` 生效，存量无感 |
| `app/Http/Middleware/PlatformContract.php`          | 把 `requestId`/`appCode` 写入 request attributes，供 v1 信封复用同值                                                                               | 存量响应不变                             |
| `app/Http/Middleware/VerifyCsrfToken.php`           | `$except` 增加 `api/v1/workshop/*`（v1 用自建会话与 CSRF）                                                                                         | 仅新前缀                                 |
| `app/Http/Middleware/GatewayTrust.php`              | `PUBLIC_PATHS` 增加 `api/v1/workshop/auth/forum-verify`（登录前访问，必须公开）                                                                    | 仅多一条白名单                           |
| `docs/platform/openapi.yaml`                        | 新增 17 个 v1 path + 6 个 schema + 3 个 securityScheme + 3 个 parameter；修正 `project/user` 语义（GET 列表 → POST 增删）；补 `project/submit`     | 契约                                     |
| `scripts/contract-check.mjs`                        | 支持 v1 显式路由映射、反向校验（映射表 ↔ 契约）、输出契约外方法提示                                                                                | 校验脚本                                 |
| `.env.example`                                      | 新增 sso / session / gateway / exam / showcase / forum / idempotency 七组变量（密钥留空）                                                          | 部署                                     |
| `docs/platform/deviations.md`                       | 补登记本次新增偏离（见 §11）                                                                                                                       | 文档                                     |
| `config/platform.php`                               | 新增 `gateway.base_url` / `gateway.l2_version` / `gateway.probe_timeout` 与 `forum` 段（账号确认）；exam/showcase 的默认路径与 AppKey 改为网关口径 | 仅出站调用，入站接口不变                 |
| `app/Services/GatewaySigner.php`                    | 新增 `callCanonicalGateway()` / `canonicalQuery()` / `l2Version()`；`callSign()`、`callHeaders()` 增加可选版本参数（旧调用方式保持可用）           | 出站签名，入站验签逻辑未变               |
| `app/Services/ExamClient.php`、`ShowcaseClient.php` | 默认路径改为 internal 前缀                                                                                                                         | 仅出站寻址                               |
| `app/Http/Controllers/Api/WorkshopController.php`   | 新增 `forumVerify`（账号确认登录）；`health` 增加 `gateway` 与 `forumVerify` 段；mock 判定与 Client 的 `mockEnabled()` 对齐                        | 只增接口与字段                           |

### 9.3 未改动（刻意）

- 所有存量控制器（`ProjectController` / `RecruitmentController` / `UsersController` …）**一行未改**。
- 前端所有代码未改（登录页仍走邮箱密码，v1 与存量并行）。
- `User::auth()` 未改，因此 300 多处存量调用零风险。

---

## 10 验证方式

```bash
# 1. 探活（无鉴权）
curl -s http://127.0.0.1:2222/api/v1/workshop/health | jq

# 2. 走 mock 授权链路（-L 跟随 302，-c 保存会话）
curl -s -c /tmp/ws.txt -L -o /dev/null -w '%{http_code} %{url_effective}\n' \
  'http://127.0.0.1:2222/api/v1/workshop/auth/login?redirect=/project'

# 3. 身份（应返回 sub 字符串，而非本地 userid）
curl -s -b /tmp/ws.txt http://127.0.0.1:2222/api/v1/workshop/auth/userinfo | jq '.data'

# 4. 未登录必须 401
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:2222/api/v1/workshop/auth/userinfo

# 5. 服务间：无签名必须 403
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:2222/api/v1/workshop/internal/projects/12

# 6. 契约对账
node scripts/contract-check.mjs

# 7. 自动化测试（64 项新增 + 17 项存量）
PHP_BIN=D:/dt-run/php/php.exe npm run test

# 8. 已部署网关的实机自检（需网络可达 GATEWAY_BASE_URL）
php artisan gateway:probe
php artisan gateway:probe --app-key=jy-exam-svc --token=<L1 令牌>
FORUM_PROBE_PASS=<密码> php artisan gateway:probe --login=<论坛账号>   # 追加账号确认验证
```

**论坛账号确认的三条手工验证**（mock 模式下即可跑通）：

```
# 5b. 账号确认 → 建会话（注意 data.mock=true，说明是本地假象）
curl -s -X POST http://127.0.0.1:2222/api/v1/workshop/auth/forum-verify \
  -H 'Content-Type: application/json' \
  -d '{"login":"admin@jspi.edu.cn","pass":"Admin@12345"}' | jq '.data'

# 5c. 带会话查身份（userId 应为 mock_forum_* 或论坛 user_id）
curl -s -b /tmp/ws2.txt http://127.0.0.1:2222/api/v1/workshop/auth/userinfo | jq '.data'

# 5d. 渠道故障不得报成密码错误：关掉 mock 且留空密钥，应得 503/channel_misconfigured
curl -s -X POST http://127.0.0.1:2222/api/v1/workshop/auth/forum-verify \
  -H 'Content-Type: application/json' \
  -d '{"login":"admin@jspi.edu.cn","pass":"Admin@12345"}' | jq '{code, reason: .data.reason}'
```

**已跑通的实测结果**（2026-11-06）：

| 检查                | 命令                                              | 结果                                       |
| ------------------- | ------------------------------------------------- | ------------------------------------------ |
| 网关/身份/幂等/CORS | `npm run test -- --filter WorkshopGatewayTest`    | 19 项 / 74 断言 通过                       |
| 准入四分支与降级    | `npm run test -- --filter ExamAdmissionTest`      | 12 项 / 65 断言 通过                       |
| 广场映射与占位推送  | `npm run test -- --filter ShowcaseBridgeTest`     | 8 项 / 57 断言 通过                        |
| L2 签名与网关一致性 | `npm run test -- --filter GatewaySignerTest`      | 7 项 / 28 断言 通过                        |
| 账号确认与故障区分  | `npm run test -- --filter ForumAccountVerifyTest` | 18 项 / 71 断言 通过                       |
| 全量                | `npm run test`                                    | 81 项 / 366 断言 通过                      |
| PHP 语法            | `PHP_BIN=... npm run lint`                        | 222 个文件通过                             |
| 契约对账            | `npm run typecheck`                               | 41 个 path（v1 17 个），契约外 90 个仅提示 |

**实机接入结果**（对 `http://8.138.246.181` 执行 `php artisan gateway:probe`）：

| 项              | 结果             | 证据                                                                                                                                         |
| --------------- | ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| P1 网关可达     | PASS             | `GET /` → 200                                                                                                                                |
| P2 路由已登记   | PASS             | `/api/v1/workshop/ping` → 401 / `40101`                                                                                                      |
| P3 默认拒绝生效 | PASS             | 未登记路径 → 403 / `40300`                                                                                                                   |
| P4 L2 服务签名  | **FAIL（阻塞）** | 本平台 AppKey 打 `workshop/internal/*` 返回 `40108`；换成已登记的 `jy-exam-svc` 则返回 `40105`，说明头/时间窗/nonce/规范串均已通过，仅差密钥 |
| P5 L1 令牌链路  | PASS             | 网关登录取得令牌后 `/api/v1/workshop/ping` → 200，注入 `user_id=1, user_role=admin, auth_mode=user`                                          |
| P6 账号确认路由 | PASS             | 无签名 POST → 401 / `40105`（路由已登记，被 L2 拦下）                                                                                        |

**两个补充实测**（把"能验到哪一步"钉死）：

| 实测                                           | 结果                                                                                       |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `account/verify` 用 `jy-workshop-svc` + 假签名 | `40105 服务签名校验失败`（**不是** `40108`）→ 该路由**已预置**本平台 AppKey，仅缺密钥      |
| 同一 AppKey 打 `workshop/internal/ping`        | `40108 AppKey 不存在或已停用` → 该路由**未**预置，需网关侧单独补（两条路由白名单相互独立） |
| PHP 与官方 Python 脚本对同一输入的签名         | body、规范串、signature（`6665770290…`）逐字节相同                                         |

自动化测试见 `tests/Feature/WorkshopGatewayTest.php`、`ExamAdmissionTest.php`、`ShowcaseBridgeTest.php`、`ForumAccountVerifyTest.php`、`tests/Unit/GatewaySignerTest.php`。

---

## 11 已知偏离与待确认

| 编号  | 偏离                                                                                                                                               | 影响                                                             | 补偿                                                                                            |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| DV-17 | 成果广场契约未提供，Work 字段为本平台按 §6.3 命名规范推定                                                                                          | 联调时字段可能调整                                               | 字段集中在 `ShowcaseClient::buildWorkFromProject()` 一处，改一处理即可                          |
| DV-18 | 事件只落库、无投递器                                                                                                                               | 广场/档案无法自动感知项目完成                                    | 出站走 `internal/projects/{id}/publish` 同步触发；投递器接入后补齐                              |
| DV-19 | 无 `{resource}:{action}` 授权表，`admission:read` 用「发布者 ∪ 管理员」投影                                                                        | 无法声明更细粒度权限                                             | 与 DV-06 同源；补授权表时把投影换成策略查询                                                     |
| DV-20 | 本地 HTTP 下 `__Host-` 前缀 Cookie 不可用，降级为 `workshop_sid`                                                                                   | 本地与生产 Cookie 名不同                                         | 代码按 `WORKSHOP_SESSION_SECURE` 自动切换，`health.capabilities.cookieName` 可见实际值          |
| DV-23 | 已部署网关的 L2 规范串是七段，与接入说明 V3.0 的四段不同                                                                                           | 同一份代码要适配两种对端                                         | `GATEWAY_L2_VERSION` 切换；两版均有测试锁定                                                     |
| DV-24 | 网关侧未预置本平台 AppKey（`jy-workshop-svc` 打 `workshop/internal/*` 返回 40108）                                                                 | **workshop 自身的 L2 出站仍无法通关**，准入与推送只能停在 mock   | `gateway:probe` 精确定位；凭据下发后填 env 即通，代码零改动                                     |
| DV-25 | 网关的广场前缀是 `plaza`，服务间必须走 `internal`                                                                                                  | 路径写错只表现为 401，易误判为密钥问题                           | 默认路径已改为 `/api/v1/plaza/internal/*` 与 `/api/v1/exam/internal/*`                          |
| DV-26 | 网关每条路由各自维护 `l2([...])` 客户端白名单：`account-verify` 已预置 `jy-workshop-svc`（返回 40105），`workshop/internal/*` 未预置（返回 40108） | 同一 AppKey 在不同路由上「一个通、一个不通」，极易误判为密钥问题 | `gateway:probe` 的 P4 与 P6 分别探测两条路由；文档 §6.3 明示二者独立（DV-24 仍只影响 internal） |
| DV-27 | 账号确认接口只返回掩码邮箱（`a***@jspi.edu.cn`），无法用作本地账号邮箱                                                                             | 首登建号时本地邮箱为占位值 `{user_id}@sso.local`                 | `IdentityBinder` 统一生成占位；`userinfo` 回显该占位值，真实邮箱只在论坛侧，符合最小暴露原则    |

**待确认**：

1. ~~**`FORUM_APP_SECRET` 下发**~~（**已于 2026-11-06 完成**）：`jy-workshop-svc` 的 AppSecret 已填入
   `FORUM_APP_SECRET`、`FORUM_VERIFY_MOCK=false`；实机复验 `gateway:probe` 的 **P6b PASS**，
   `gatewayCode=40104` 表示请求已穿过网关到达论坛业务层（账号不存在才返回认证失败），
   说明 L2 签名与七段规范串均已被网关接受。同一密钥同时填入 `EXAM_APP_SECRET` / `SHOWCASE_APP_SECRET`
   （三者同属 `jy-workshop-svc` 一个 AppKey，不存在复用他人凭据的问题）。
2. **网关侧为 `workshop/internal/*` 补 L2 客户端**：该路由仍返回 40108，需单独登记（与第 1 条不是同一处配置，见 DV-26）。**注意：密钥已证明正确，此处只需在路由的 `l2([...])` 中追加 `jy-workshop-svc`，无需重新下发密钥。**
3. **网关侧 `workshop-api` / `workshop-internal` / `workshop-files` 的上游地址**：当前指向 `127.0.0.1:8000` 桩服务，需改为本平台实际监听地址后，才能把本平台当作真正的 workshop 上游。
4. **成果广场的作品契约**（字段名、作品状态机、是否需要封面/标签）。路径已按网关调整为 `POST /api/v1/plaza/internal/works`。
5. **考试平台准入接口的确切路径与 reason 枚举拼写**（本平台按 `passed` / `not_started` / `failed` / `violation` / `pending` / `prerequisite_missing` 实现）。
6. **是否需要把 v1 别名扩展到全部 23 个接口**（当前只做了 projects / recruitments 四个读接口）。
