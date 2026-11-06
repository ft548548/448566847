# OAuth 登录链路迁移方案（替换 forum-verify）

> 目标：把「用户在本平台输入论坛密码 → 平台经网关代验」的过渡链路，替换为「跳转身份中心 → 授权码 + PKCE → 服务端换码 → 建会话」的终态链路。  
> 约束：存量功能零改动；不在代码中硬编码任何凭据。  
> 编制时间：2026-11-06　适用版本：workshop API v1



---

## 0. 一句话结论

**OAuth 客户端代码（`IdentityProvider` + `auth/login` + `auth/oauth/callback`）早已完整实现，但这条链路今天在生产上跑不通，而且不是改代码能解决的。**

实测证据（2026-11-06 14:30，生产 `http://8.134.60.240:8080`）：

```
GET /api/v1/workshop/auth/login
  → 302 .../auth/oauth/callback?code=mock_02113436b15615b1&state=027b7a92...
GET .../auth/oauth/callback?code=mock_...&state=...
  → 400 {"code":"VALIDATION_ERROR","msg":"论坛 uid 非十进制正整数，拒绝建号：本平台不以任何方式生成替代标识"}
```

即：**mock 链路在最后一步被 uid 契约拦下，真实链路连端点都不存在。** 迁移的成败取决于三个前置条件，全部在平台代码之外。

---

## 1. 现状实测（先立证据，再谈方案）

| #   | 观测项                                 | 实测结果                                                                                                                                                                                                            | 判读                                                                            |
| --- | ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| E-1 | 生产 `auth/login`                     | 302 到自己域的 `callback?code=mock_*`                                                                                                                                                                                | 走的是 **mock 分支**，未接触任何身份中心                                                     |
| E-2 | 生产 `auth/oauth/callback`（mock code） | 400 `VALIDATION_ERROR`，`reason` 文案为「论坛 uid 非十进制正整数」                                                                                                                                                             | mock 的 `sub` 是 `usr_mock_workshop_demo`，被 `parseUid()` 拒绝                     |
| E-3 | 生产 `.env` 的 `SSO_` 变量数              | **0 个**                                                                                                                                                                                                         | `SSO_MODE` 取默认值 `mock`，生产从未配置过真实 SSO                                          |
| E-4 | 本地 `.env`                           | `SSO_MODE=mock`、`SSO_CLIENT_SECRET` 为空、无 `SSO_BASE_URL`                                                                                                                                                         | 本地也只是打通链路，无真实凭据                                                               |
| E-5 | 网关 5 条 OAuth 路径                     | `/.well-known/openid-configuration`、`/oauth/authorize`、`/oauth/token`、`/oauth/userinfo`、`/protocol/logout` **全部** 200 + 同一张 6666 字节 HTML，正文为「You need to enable JavaScript to run this app.」并带 Apache License 头 | 这是 **APISIX Dashboard 的 SPA 首页**（history 模式通配兜底），说明这些路径**未注册为 API 路由**，被兜底页吃掉 |
| E-6 | 前端 `login.vue`                      | OAuth 按钮为纯视觉占位，注释明确「不跳转、不请求 OAuth 授权端点、不调用 `/api/v1/workshop/auth/*`」                                                                                                                                           | 本仓库前端 **不消费** 任何 workshop 登录接口                                                |
| E-7 | 路由与中间件                              | `auth/login`、`auth/oauth/callback`、`auth/logged-out` 均已在 `GatewayTrust::PUBLIC_PATHS` 白名单内                                                                                                                      | 网关改造时这三条无需再加白名单                                                               |

**E-6 的含义容易被忽略**：`forum-verify` 的调用方不在本仓库，而是外部的论坛/统一门户页面。因此「登录入口」的改造不是改本仓库页面，而是**改外部调用方与我们之间的契约**——从「同源 AJAX 提交密码」变成「整页跳转到本平台 `/auth/login`」。这是本次迁移里最容易低估工作量的一处。

---

## 2. 三个阻塞项（必须先定，否则代码改了也白改）

### B-1（P0）`sub` 形态与 uid 契约直接冲突

| 依据                          | 说法                                                                                                                               |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| 《统一身份与网关接入说明》V3.0 §3.2/§4.1 | `sub` = `userinfo.userId`，是**不透明字符串**（样例 `usr_01J8ZQ7M2K`）；`userid` 是 bigint 自增，**不等于** `sub`，必须经 `pre_user_identity_binding` 映射 |
| 你的 uid 契约 R1/R2（后裁决，优先）     | `canonical_key = forum_uid`，取值 = 论坛 `user.id` 的**十进制字符串原值**；本地主键直接等于它；**不建本地账号**；禁止返回 `sub`                                      |
| 代码实际                        | `IdentityBinder::parseUid()`（`app/Services/IdentityBinder.php:215-229`）只接受纯十进制正整数，非数字一律返回 null → `VALIDATION_ERROR`              |

**结论**：如果真实 `userinfo` 返回的 `sub` 是 `usr_xxx` 形态，**OAuth 链路永远无法满足 uid 契约**，E-2 的 400 会原样在真实环境复现。这不是 bug，是两套身份模型在同一字段上的正面冲突。

**必须先拿到一个真实样例**：任取一个已知 uid 的论坛账号（如 uid 5），走完授权流程，把 `GET /oauth/userinfo` 的原始响应贴出来。判定标准只有一个：

- 若响应里存在**十进制数字**的论坛 `user.id` 字段（`userId` / `user_id` / `forum_uid` 任一） → 方案可落地，用它作 `forum_uid`；
- 若只有不透明 `sub`、没有任何数字 id → **uid 契约必须改**（回到绑定表方案），这属于你的裁决范围，我不会擅自改。

### B-2（P0）网关侧 OAuth 端点未注册

E-5 显示 5 条协议路径全部落到 APISIX 默认页。在端点可用之前，`SSO_MODE=real` 一开启，用户会被 302 到一个不存在的地址。

**需要网关/身份中心交付的最小集合**：

| 端点                                      | 用途    | 备注                                                                                                |
| --------------------------------------- | ----- | ------------------------------------------------------------------------------------------------- |
| `GET /oauth/authorize`                  | 授权页   | 需支持 `response_type=code`、`code_challenge(S256)`、`state`                                           |
| `POST /oauth/token`                     | 服务端换码 | 需支持 `authorization_code` + `code_verifier`；**错误须返回 `invalid_grant`/`invalid_client` 等标准 `error`** |
| `GET /oauth/userinfo`                   | 取身份   | 须含数字论坛 uid 字段（见 B-1）与 `roles`                                                                     |
| `GET /.well-known/openid-configuration` | 端点发现  | 建议提供，代码优先读它，避免长期硬编码路径                                                                             |
| `GET /protocol/logout`                  | 单点登出  | 支持 `post_logout_redirect_uri`、`client_id`                                                         |

另需登记 **`redirect_uri` 精确白名单**：`https://<平台域名>/api/v1/workshop/auth/oauth/callback`（当前为 `http://8.134.60.240:8080/...`，见 §7 的 HTTP 限制）。

### B-3（P0）你给的凭据不是 OAuth 客户端凭据

你提供的是 `AppKey=jy-workshop-svc` / `AppSecret=502d93d6…`。经上一轮对照实验已确认：这对凭据是**网关 L2 服务间签名凭据**（用于 `X-Internal-Sign` / L2 七段签名，实测正确→404 过签、错误→40105、错 AppKey→40108）。

OAuth 授权码流程需要的是**另一组**：`client_id`（浏览器可见） + `client_secret`（仅服务端），由**身份中心注册签发**。仓库里已有占位：`SSO_CLIENT_ID=jy-workshop-web`，而 `SSO_CLIENT_SECRET` 为空。

**两者不能互换**。把 L2 AppSecret 填进 `SSO_CLIENT_SECRET` 的后果是 `POST /oauth/token` 返回 `invalid_client`，表现为「授权页能打开、回来就失败」，排查时极易误判成网络问题。

> 待确认：`jy-workshop-web` 这个 client 是否已在身份中心注册？其 `client_secret` 由谁下发？如果身份中心确认「OAuth 客户端凭据就是 L2 的 AppKey/AppSecret」，也请书面明确——但按标准 OAuth 2.0，机密客户端的 secret 与网关 L2 secret 是两个信任域，通常不复用。

---

## 3. 需要你裁决的事项

| 编号  | 事项                                      | 选项                                                                           | 我的建议                                                                                                                        |
| --- | --------------------------------------- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| D-1 | `forum_uid` 从 `userinfo` 的哪个字段取         | A. 用数字 `userId`/`forum_uid`（保 uid 契约不变）<br />B. 用不透明 `sub` + 恢复绑定表（改 uid 契约） | **A**。B 会推翻你已定的 R1/R2 与「不建本地账号」，且要重建绑定表并回填                                                                                  |
| D-2 | `client_secret` 来源                      | A. 身份中心另发<br />B. 复用 L2 AppSecret                                            | **A**，理由见 B-3                                                                                                               |
| D-3 | 切换方式                                    | A. 硬切：下线 `forum-verify`<br />B. 灰度双开：OAuth 为主，`forum-verify` 保留一个版本          | **B**。真实身份中心未验证前硬切，等于把唯一可用登录方式也关掉                                                                                           |
| D-4 | 是否在会话中保存 `access_token`/`refresh_token` | A. 保存（加密后入会话）<br />B. 用完即弃（当前行为）                                             | 取决于是否有「以用户身份回调论坛」的需求。当前代码注释（`WorkshopSession.php:15`）声称存 `accessCipher/refreshCipher`，**但 `create()` 实际没存**，属注释与实现不一致，需一并修正 |
| D-5 | 错误呈现方式                                  | A. 整页跳转带 `?error=...`，落地页渲染<br />B. 保留 JSON 错误体                              | **A**。跳转式登录中返回 JSON 会让用户看到一屏裸 JSON                                                                                          |

---

## 4. 改动模块清单（精确到文件与行）

### 4.1 登录入口

| 文件                                                | 位置                  | 改动    | 说明                                                                                                                              |
| ------------------------------------------------- | ------------------- | ----- | ------------------------------------------------------------------------------------------------------------------------------- |
| `routes/web.php`                                  | `:39`               | 保留    | `GET auth/login` 即 OAuth 入口，已是终态形态                                                                                              |
| `app/Http/Controllers/Api/WorkshopController.php` | `:40-63` `login()`  | **改** | 去掉 `isMock()` 分支或将其限制在 `APP_ENV=local`。`redirect` 参数已由 `safeRedirect()`（`:780-790`）限制为站内相对路径（必须 `/` 开头、拒绝 `//` 与反斜杠），**该处无需改动** |
| 同上                                                | `:46-49` `putState` | 保留    | `state` 里存 `redirect` 与 PKCE `verifier`，结构正确                                                                                    |
| 外部调用方（论坛/门户）                                      | 不在本仓库               | **改** | 登录按钮从「POST 密码」改为「跳转 `GET {平台}/api/v1/workshop/auth/login`」                                                                      |

### 4.2 回调处理

| 文件                       | 位置                      | 改动        | 说明                                                                                                                                                                                                                                          |
| ------------------------ | ----------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WorkshopController.php` | `:73` `oauthCallback()` | **改（重点）** | ① **当前未处理 `?error=` 参数**——身份中心拒绝授权时会回 `error=access_denied&state=...`，现有代码只读 `code`，会把「用户点了取消」误报成「缺少授权码 code」。需先判 `error`。<br />② 换码失败/`userinfo` 失败目前返回 JSON（`:105-121`），应改为 302 到落地页带错误码（D-5）。<br />③ `state` 一次性消费（`pullState`）已正确实现，防重放 |
| 同上                       | `:100-124`              | 保留逻辑      | mock/real 分支、`exchangeCode`、`userinfo` 调用顺序正确                                                                                                                                                                                               |
| 同上                       | `:137-151`              | 保留        | 会话固定防护（换新 sid、删旧 sid）与 `attachCookie` 已具备                                                                                                                                                                                                   |

### 4.3 令牌校验

| 文件                                  | 位置                         | 改动     | 说明                                                                         |
| ----------------------------------- | -------------------------- | ------ | -------------------------------------------------------------------------- |
| `app/Services/IdentityProvider.php` | `:87-132` `exchangeCode()` | 保留     | 已实现：不重试、`asForm`、超时、错误码透传                                                  |
| 同上                                  | `:140-189` `userinfo()`    | **改**  | 取 `sub` 的优先级需按 D-1 调整；若改为取数字 `userId`，需同步改 `IdentityBinder` 调用处传入的 subject |
| 同上                                  | `:235-262` `discovery()`   | 保留     | Discovery 优先、缓存 1h、失败降级到配置                                                 |
| `app/Services/IdentityBinder.php`   | `:215-229` `parseUid()`    | **不改** | 这是 uid 契约的执行点，**不能为兼容 OAuth 而放宽**；B-1 的正解是让上游给数字 id，而非放开校验                 |
| 同上                                  | `:39` `bindOrCreate()`     | 视 D-1  | 若 `sub` 非数字，此函数必然失败                                                        |

### 4.4 会话与用户信息获取

| 文件                       | 位置                          | 改动               | 说明                                                                                  |
| ------------------------ | --------------------------- | ---------------- | ----------------------------------------------------------------------------------- |
| `WorkshopSession.php`    | `:72-92` `create()`         | **改（若 D-4 选 A）** | 当前只存 `userid/subject/roles/client_id/mode`，**令牌被丢弃**；与 `:15` 注释不符                   |
| 同上                       | `:248-262` `attachCookie()` | 视环境              | `SameSite` 取 `platform.session.same_site`（默认 `Lax`）、`secure` 默认 false。**跨站场景见 R-3** |
| `WorkshopController.php` | `:324-362` `userinfo()`     | 保留               | 已按 uid 契约返回 `uid/forum_uid/platform_user_id:null`，无需改动                              |
| `GatewayIdentity.php`    | `:206` `issuer()`           | 保留               | 取 `platform.sso.issuer`，与 `forum.issuer` 同值（`jingyuan-forum`），两条链路命中同一绑定命名空间        |

### 4.5 下线 forum-verify（D-3 选 B 时延后一个版本）

| 文件                                         | 位置                                      | 处理                                                                  |
| ------------------------------------------ | --------------------------------------- | ------------------------------------------------------------------- |
| `routes/web.php`                           | `:43`                                   | 删除路由                                                                |
| `GatewayTrust.php`                         | `:26`                                   | 从 `PUBLIC_PATHS` 移除 `api/v1/workshop/auth/forum-verify`             |
| `WorkshopController.php`                   | `:156-314` `forumVerify()`              | 删除方法                                                                |
| 同上                                         | `:8` `use ForumAccountClient`           | 删除                                                                  |
| 同上                                         | `:513-551` `health()` 的 `forumVerify` 段 | 删除；`capabilities.ssoMode` 保留                                        |
| `app/Services/ForumAccountClient.php`      | 全文（约 490 行）                             | 整文件删除                                                               |
| `app/Console/Commands/GatewayProbe.php`    | `:320-375` P6/P6b                       | P6b 依赖 `ForumAccountClient`，需改为探 OAuth 端点（如 `authorize` 是否 302/200） |
| `config/platform.php`                      | `:121-148` `forum` 段                    | 整段删除                                                                |
| `tests/Feature/ForumAccountVerifyTest.php` | 全文 19 个用例                               | 整文件删除，其中 3 个 uid 契约用例（`:80/:107/:130`）需**迁移**到新的 OAuth 测试里，不能丢覆盖    |
| `.env`                                     | `FORUM_*` 系列                            | 删除（`FORUM_APP_*` 若仍被 L2 出站签名复用则保留，**删前必须确认**）                       |

### 4.6 配置

`config/platform.php:31-53` 的 `sso` 段已完备（`mode/issuer/base_url/client_id/client_secret/redirect_uri/post_logout_uri/login_path/token_path/userinfo_path/logout_path/discovery_path/scope/pkce/http_timeout`），**无需新增字段**。

---

## 5. 替换范围

**做**：登录入口跳转、回调处理（含 `error` 参数与错误落地页）、`userinfo` 取值口径、会话令牌存储（视 D-4）、配置与凭据注入、`forum-verify` 全链下线、探针与测试改造。

**不做**：`GatewayTrust` 验签逻辑、`GatewayIdentity` 三级解析、`WorkshopSession` 的存储结构（除令牌字段）、全部业务接口（`admission`/`projects`/`recruitments`/`internal/*`）、DooTask 原生登录（`/api/users/login`）、数据库表结构。

**不动的理由**：这些模块与登录方式解耦——会话结构不变，下游只认 `workshop_sid`，因此替换登录方式对业务零影响。这也是当初把两条入口设计成「会话结构完全一致」的价值所在。

---

## 6. 新旧链路差异

### 6.1 接口形态

| 维度       | forum-verify（旧）                  | OAuth（新）                                                  |
| -------- | -------------------------------- | --------------------------------------------------------- |
| 请求形态     | `POST /auth/forum-verify`，JSON 体 | `GET /auth/login` → 302 身份中心 → `GET /auth/oauth/callback` |
| 交互模型     | 同页 AJAX，同步返回                     | 整页跳转（跨站），URL 来回                                           |
| 需要用户提供   | 论坛账号 + 密码                        | 无（在身份中心输入）                                                |
| 平台是否接触密码 | **是**（内存透传一次）                    | **否**（终态目标达成）                                             |
| 响应       | `200 + Set-Cookie + JSON data`   | `302 + Set-Cookie`，无 JSON                                 |
| CSRF 防护  | Origin + SameSite                | `state` + PKCE(S256)                                      |

### 6.2 数据结构

**旧（成功响应 `data`）**

```
uid, forum_uid, uid_source="forum", platform_user_id=null, platform_id_generated=false,
username, displayName, role, emailMasked, accountSource, created,
session{idleExpiresIn, absoluteExpiresIn}, source="remote"|"mock"
```

其中 `username/displayName/role/emailMasked/accountSource` 来自 account-verify 的 `profile`。

**新**

- 前端**拿不到任何 JSON**（302），只能靠落地页再调 `GET /auth/userinfo` 取身份；
- `userinfo` 返回：`uid, forum_uid, uid_source, platform_user_id:null, platform_id_generated:false, roles[], nickname, email, avatar, source, authMode, clientId, session{active,expiresIn}`；
- **字段丢失**：`emailMasked`、`accountSource`、`created`、`username/displayName`（`userinfo` 里是 `nickname`）。若外部页面依赖这些字段，需在落地页改造时同步调整。

### 6.3 错误处理（差异最大，也最容易出事）

| 失败点            | 旧行为                                                  | 新行为（现状代码）                                                  | 必须改成                                    |
| -------------- | ---------------------------------------------------- | ---------------------------------------------------------- | --------------------------------------- |
| 账号/密码错         | 401 `invalid_credentials` + `gatewayCode` + `source` | —                                                          | —                                       |
| 失败过多           | 429 `RATE_LIMITED` + `Retry-After`                   | 无（不验密码，无需撞库节流）                                             | 增加 `state` 洪水防护（同 IP 频次限制）              |
| 用户取消授权         | —                                                    | **未处理**：`error=access_denied` 被当成「缺少 code」返回 400           | 识别 `error` 并跳落地页 `?error=ACCESS_DENIED` |
| 身份中心不可达        | 503 `CHANNEL_UNAVAILABLE`                            | `TOKEN_EXCHANGE_FAILED`（401）+ `token_endpoint_unreachable` | 区分「网络不可达（503）」与「凭据被拒（401）」              |
| 换码失败           | —                                                    | 401 JSON                                                   | 302 落地页 `?error=TOKEN_EXCHANGE_FAILED`  |
| `userinfo` 缺身份 | 503 `SUBJECT_MISSING`                                | 503 `USERINFO_INVALID`（JSON）                               | 302 落地页，且**不得降级放行**（现有实现正确）             |
| uid 非十进制       | —                                                    | 400 `VALIDATION_ERROR`（E-2 实测）                             | 取决于 B-1                                 |


**统一原则**：跳转式流程里，**任何错误都不能以裸 JSON 呈现给浏览器**。需要一张落地页把 `?error=` 映射成人话，否则用户看到的是 `{"code":"VALIDATION_ERROR"...}`。

### 6.4 安全属性

| 项 | 旧 | 新 |
|---|---|---|
| 密码暴露面 | 平台后端需处理明文密码 | 不再接触 |
| 令牌 | 无 | `access_token`/`refresh_token`，仅服务端 |
| 重放防护 | 无显式机制 | `state` 一次性 + PKCE |
| 凭据数量 | 1 组（L2） | 2 组（L2 + OAuth client secret） |

### 6.5 会话与 Cookie

- 旧：XHR 响应带 `Set-Cookie`，同源或已配 CORS 的环境下直接生效。
- 新：`302` 响应带 `Set-Cookie`，发生在**从身份中心跳回本站的顶层导航**中。`SameSite=Lax` 对顶层 GET 导航是放行的，因此**回调这一步通常没问题**；但如果落地页所在的第三方域随后以 XHR 调本平台接口，就需要 `SameSite=None; Secure` 且**必须 HTTPS**。当前部署是 `http://8.134.60.240:8080`，`secure=false`——见 R-3。

---

## 7. 凭据配置方案（不硬编码）

### 7.1 配置项（写入 `.env`，不进仓库）

```dotenv
SSO_MODE=real
SSO_ISSUER=jingyuan-forum
SSO_BASE_URL=https://<身份中心对外地址>
SSO_CLIENT_ID=jy-workshop-web
SSO_CLIENT_SECRET=<由身份中心签发，仅服务端>
SSO_REDIRECT_URI=https://<平台域名>/api/v1/workshop/auth/oauth/callback
SSO_POST_LOGOUT_URI=https://<平台域名>/
SSO_SCOPE=openid profile
SSO_PKCE=true
SSO_HTTP_TIMEOUT=3
```

**注意**：`SSO_CLIENT_SECRET` **不要**填你给的 `502d93d6…`，除非身份中心书面确认二者等同（见 B-3）。`jy-workshop-svc` / 其 AppSecret 继续留在 `GATEWAY_*` 下，供 L2 出站签名使用，**两套并存、各司其职**。

### 7.2 不放代码里的具体做法

1. 数值只从 `env()` 读，`config/platform.php` 已是这种写法，无需改动；
2. 生产改完 `.env` 必须 `php artisan config:clear`（该站已启用配置缓存，`config:cache` 会把旧值固化）；
3. 若后续要求「密钥不落 `.env` 明文」，可仿照现成的 `GATEWAY_INTERNAL_SECRET_FILE` 机制，为 `SSO_CLIENT_SECRET` 增加 `SSO_CLIENT_SECRET_FILE` 支持（`config/platform.php` 增加一行读取逻辑，约 5 行代码）——**当前不必做**，先跑通；
4. 任何日志、异常、`health` 输出都不得回显 secret：`health()` 只报 `ssoMode`，不报 secret，现状合规；
5. 排查时用长度 + 哈希前缀核对，不打印明文（本会话既有做法）。

### 7.3 上线前的凭据自检（无需真跑登录）

```bash
php artisan tinker --execute="
echo 'mode=' . config('platform.sso.mode') . PHP_EOL;
echo 'client_id=' . config('platform.sso.client_id') . PHP_EOL;
echo 'secret_len=' . strlen((string) config('platform.sso.client_secret')) . PHP_EOL;
echo 'redirect=' . config('platform.sso.redirect_uri') . PHP_EOL;
"
```

期望：`mode=real`、`client_id=jy-workshop-web`、`secret_len` 非 0、`redirect` 与身份中心登记值**逐字符一致**（尾斜杠、http/https、端口都算差异）。

---

## 8. 实施顺序（每阶段可验证、可回滚）

| 阶段 | 内容 | 前置 | 可验证点 | 回滚 |
|---|---|---|---|---|
| **P0** | 取真实 `userinfo` 样例，确认 B-1；确认 B-2 端点与 B-3 secret | 网关/身份中心配合 | 拿到原始 JSON | 无代码改动 |
| **P1** | 改回调：处理 `?error=`、错误改落地页、区分 503/401 | P0 | 手工构造 `?error=access_denied` 得到友好页 | 单文件回退 |
| **P2** | 配 `SSO_MODE=real` + 五条 `SSO_` 变量，`config:clear` | P0 凭据 | `health.capabilities.ssoMode=real` | 改回 `mock` |
| **P3** | 真跑一次授权：跳转 → 登录 → 回调 → `userinfo` 返回 `uid=5` | P1、P2 | 会话建立、`/auth/userinfo` 200 且 `uid` 为十进制 | 关掉入口 |
| **P4** | 外部页面切换入口（D-3 若选 B：双开灰度） | P3 | 灰度用户可登录，旧入口仍可用 | 切回旧入口 |
| **P5** | 下线 `forum-verify`（§4.5 清单） | P4 稳定一个版本 | 全量测试 0 失败、`grep -r forum-verify` 无业务命中 | 保留旧代码一个版本后再删 |

**不建议跳过 P0 直接改代码**：B-1 的答案会决定 `userinfo` 取值口径与是否要动 `IdentityBinder`，先改后返工的概率很高。

---

## 9. 验收标准

| # | 项 | 通过标准 |
|---|---|---|
| V-1 | 授权跳转 | `GET /auth/login` 返回 302 到 `SSO_BASE_URL/oauth/authorize`，且含 `code_challenge`+`state` |
| V-2 | 回调建会话 | 真实账号登录后回调 302 到站内，响应头含 `Set-Cookie: workshop_sid` |
| V-3 | 身份正确 | `GET /auth/userinfo` 返回 200，`uid`/`forum_uid` 为该账号论坛 `user.id` 十进制原值，`platform_user_id=null` |
| V-4 | uid 稳定性 | 同一账号连续登录 3 次，`uid` 恒等；不产生第二条绑定记录 |
| V-5 | 失败路径 | 取消授权 → 落地页 `ACCESS_DENIED`；`userinfo` 缺 id → 拒绝建号且无替代标识 |
| V-6 | 状态防护 | 重放同一 `state` → `STATE_INVALID`；错误 `state` → 拒绝 |
| V-7 | 零回归 | 全量 phpunit 0 失败（当前基线 97 tests / 427 assertions） |
| V-8 | 存量无影响 | DooTask 原生登录、业务接口、`internal/*` 全部行为不变 |
| V-9 | 密钥不外泄 | `health` 与日志中无 secret；`.env` 不被提交 |

---

## 10. 风险与遗留

| 编号 | 风险 | 级别 | 责任方 | 说明 |
|---|---|---|---|---|
| R-1 | 真实 `userinfo` 无数字 uid，uid 契约需重裁 | **高** | 你 | 见 B-1；若发生，需回到绑定表方案，涉及 `pre_user_identity_binding` 重建与回填 |
| R-2 | 身份中心 OAuth 端点未注册 | **高** | 网关/身份中心 | 见 B-2；这是硬前置，平台侧无法绕过 |
| R-3 | HTTP 部署 + `SameSite=Lax` + `secure=false` | 中 | 你 | 跨站 XHR 需 `None;Secure`，而 `Secure` 必须 HTTPS。**上线前应先落 HTTPS**，否则外部页面改跳转后可能「登录成功但页面仍未登录」 |
| R-4 | 会话不存令牌（注释与实现不一致） | 中 | 你（D-4） | 若将来需要「以用户身份调用论坛」，需补存与刷新逻辑 |
| R-5 | `emailMasked/accountSource/created/username` 字段在 OAuth 下丢失 | 中 | 你 | 外部页面若展示这些，需改为从 `userinfo` 的 `nickname/email` 取 |
| R-6 | 三套 `*_APP_SECRET` 同值（既有技术债） | 中 | 你 + 网关 | 与本次迁移无关，但会扩大泄露半径；建议并入本轮一并拆分 |
| R-7 | L1 令牌仍强制（G-01 未落） | **高** | 网关 | 即便 OAuth 通了，经网关访问业务接口仍会 `40101`。**OAuth 与 G-01 是两件事，必须都解决** |
| R-8 | 外部调用方改造被低估 | 中 | 你 | 从 AJAX 改整页跳转，涉及页面状态、回跳地址、错误展示三处 |
| R-9 | 网关 80 端口对公网返回 APISIX Dashboard SPA | 低（观察项） | 网关 | 这是 E-5 的成因（所有未匹配路径回落 Dashboard 的 `index.html`）。顺带说明管理台与业务入口同源暴露，建议网关侧确认是否应限制来源或关闭 |

> **R-7 特别提示**：本轮只解决「身份怎么来」。用户拿到会话后去调业务接口，仍要经过网关的 L1 策略；那条路是 `docs/platform/gateway-actions-summary.md` 里的 G-01。两者不要混为一谈，也不要指望 OAuth 顺带解决 `40101`。

---

## 11. 假设清单（若不成立请指出，会改变方案）

| 编号 | 假设 | 不成立的影响 |
|---|---|---|
| A-1 | 身份中心 `userinfo` 至少含一个十进制论坛 uid 字段 | 触发 R-1，需重裁 uid 契约 |
| A-2 | `jy-workshop-web` 已在身份中心注册且能签发 secret | 无法换码，需先注册客户端 |
| A-3 | 外部调用方可改为整页跳转（而非必须 AJAX） | 需保留一条同页登录通道，方案要重做 |
| A-4 | 生产可启用 HTTPS（或已具备域名+证书） | `SameSite=None;Secure` 不可用，跨站会话存在问题 |
| A-5 | 本次迁移不改数据库结构 | 若走 D-1 的 B 方案，需新增/重建绑定表 |
| A-6 | `FORUM_APP_SECRET` 仍被 L2 出站签名复用（供 exam/showcase 调用） | 若不再复用，可随 `forum` 段一并清理 |

---

## 附一：实施进度（2026-11-06 14:35）

**P1（回调加固）已完成并验证。** P0 三个阻塞项仍未解决，因此 P2–P5（切 `real`、下线 `forum-verify`）一律未动——现在动只会让登录彻底不可用。

| 改动 | 文件:行 | 内容 |
|---|---|---|
| 识别身份中心回传的 `error` | `WorkshopController.php` `oauthCallback()` | 先判 `error`，`access_denied` → `ACCESS_DENIED`，其他 → `AUTHORIZE_REJECTED`；并消费 `state` 防重放 |
| 错误分通道 | 新增 `ssoFailure()` / `prefersHtml()` / `codeForStatus()` | `Accept` 含 `text/html` → 302 到落地页；否则保持原 JSON 信封 |
| 新增落地页 | `loginFailed()` + `routes/web.php` + `GatewayTrust::PUBLIC_PATHS` | `GET auth/login-failed?reason=`，只查静态表，不回显请求参数（无反射型 XSS 面） |
| 换码失败分级 | `oauthCallback()` + `IdentityProvider::exchangeCode()` | 返回体新增 `status`；连接异常或上游 5xx → 503 `TOKEN_ENDPOINT_UNREACHABLE`，4xx → 401 `TOKEN_EXCHANGE_FAILED` |
| userinfo 失败分级 | `IdentityProvider::userinfo()` | `USERINFO_UNREACHABLE`(503) 与 `USERINFO_INVALID`(503) 分开，便于区分网络故障与账号异常 |
| mock 环境限制 | `login()` / `oauthCallback()` / `config/platform.php` `sso.mock_envs` | 仅 `local,testing` 生效；生产漏配 `SSO_*` 时 **fail closed**（503 `SSO_NOT_CONFIGURED`），不下发 Cookie |
| health 可观测 | `health()` | 新增 `ssoConfigured`、`ssoMockAllowed`（只报布尔，不泄露密钥） |

**验证**：全量 `116 tests / 474 assertions` 全绿（原 97 + 新增 `OAuthCallbackTest` 19）。本地实测：

```
A) 浏览器  Accept: text/html  → 302 .../auth/login-failed?reason=ACCESS_DENIED
B) 脚本    Accept: */*        → 400 {"reason":"ACCESS_DENIED","upstream":"access_denied"}
C) 落地页                     → 渲染「登录未完成 / ACCESS_DENIED / 重新登录」
```

**一处有意的行为变化**：生产 `auth/login` 原先返回 400 `BIND_FAILED`（mock 的非数字 sub 撞 uid 契约），现在返回 503 `SSO_NOT_CONFIGURED`。这是把“静默地跑假身份”改成“明确地说没配”，属于 fail closed。

**顺带修掉的隐患**：若生产误配 `SSO_MOCK_SUB=1`，改造前会直接签发 uid=1 的会话——那是一个免密登录后门。现在 mock 在非 `local/testing` 环境下一律拒绝。

---

## 附二：与既有文档的关系

- 本文替代 `gateway-change-request.md`（v1.0）中关于登录链路的表述；v1.0 的 CR-01/02/03 已实测完成。
- `gateway-actions-summary.md`（v2.0）中的 **G-01（L1 令牌策略）** 与本文 **B-2** 是两项独立的前置条件，**必须同时满足**才能让「浏览器 → 网关 → 业务接口」整链路可用。
- 本文第 2 节的三个 P0 阻塞项，**没有一项能由平台代码单独解决**，因此不建议在 P0 完成前动工 P1 之后的代码。

---

## 附三：P0 复测记录（2026-11-06 15:55）

三个 P0 阻塞项是否解除，现在由一条命令判定，不再人工逐条 curl：

```bash
php artisan sso:probe --base=http://8.138.246.181
```

复测结果：

| 项 | 状态 | 证据 |
|---|---|---|
| B-1 `sub` 形态 | **未解除** | 仍缺真实 `userinfo` 样例，需对方走一次授权后贴原始响应 |
| B-2 端点注册 | **未解除** | 五条路径均返回 APISIX Dashboard 兜底页（HTTP 200 + 6666B HTML） |
| B-3 客户端凭据 | **未解除** | `SSO_CLIENT_SECRET` 未配置；平台上仅有 L2 的 AppSecret，不能复用 |

对外可执行的对接要求已单独成文：`sso-onboarding-request.md`（含逐项验收判据与勾选清单）。

> 判定标准的关键一条：**不看 HTTP 200，看正文是不是真接口**。Dashboard 兜底页同样回 200，只看状态码会把"未注册"误判成"已上线"。`sso:probe` 已内置该判据，并有 6 个单测锁定（`tests/Feature/SsoProbeTest.php`）。
