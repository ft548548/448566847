# 平台契约偏离登记（DooTask / 实训工坊）

| 项         | 内容                                                                                 |
| ---------- | ------------------------------------------------------------------------------------ |
| 版本       | v1.7                                                                                 |
| 日期       | 2026-11-06                                                                           |
| 被偏离文件 | 《警院 WeChat 五平台统一开发与功能联动规范》V2.0                                     |
| 适用项目   | DooTask 0.13.0（实训工坊 + 项目大厅招募域）                                          |
| 约束前提   | **现有功能不得改动**：所有对齐均为叠加或新增，不改变既有接口语义、页面行为与数据结构 |

> **v1.7 变更（网关配对检查结论）**：用真实 AppSecret 对六条 `internal` 路由逐条签名实测后，
> 把「未配对」从一项细化为三项，并新增两项登记：
> ① **DV-24 证据升级**——同一把 `jy-workshop-svc` 在 `learning-internal`、`plaza-internal` 返回 200、
> 在 `exam-internal` 已到上游（404 由桩服务返回），唯独 `workshop-internal` 返回 `40108`，
> 据此排除密钥与规范串因素，确认是**该路由白名单未登记**；
> ② 新增 **DV-30**：workshop 缺匿名入口路由，`health` 与 `auth/*` 被 L1 拦截（对照 forum、learning 均匿名放行），
> 前端若改经网关访问会形成「登录需令牌、令牌需登录」死锁；
> ③ 新增 **DV-31**（已消解）：部署时误将 `GATEWAY_TRUSTED_CIDR` 设为网关 IP，而网关尚未回源，
> 导致直连业务接口一律 `403 NOT_TRUSTED_NETWORK`，已回退为空并验证恢复为 `401 AUTH_REQUIRED`。
> 交付物：`docs/platform/gateway-change-request.md`（CR-20261106-01，可直接转交网关运维）。

> **v1.4 变更**：`jy-workshop-svc` 的 AppSecret 已由网关负责人下发并完成配置
> （`FORUM_APP_SECRET` / `EXAM_APP_SECRET` / `SHOWCASE_APP_SECRET`，同属一个 AppKey）。
> 实机复验：账号确认链路 **P6b PASS**——签名被网关接受并回环到论坛业务层（`gatewayCode=40104`），
> 说明密钥与七段规范串均已生效；`workshop/internal/*` 仍为 `40108`，属路由白名单问题（DV-24），
> 与密钥无关。§2 待办中「账号确认 AppSecret」一项关闭，§3 补第 27 项证据。

> **v1.6 变更**：workshop 平台专属的内部签名密钥已下发（`secrets.json → internal.workshop.secret`）
> 并完成接入，**DV-29 消解**（移入 §1.1）。接入不止填一个值，同时补齐了四件事：
> ① 密钥读取统一走 `GatewaySigner::internalSecret()`，支持 `GATEWAY_INTERNAL_SECRET` 与
> `GATEWAY_INTERNAL_SECRET_FILE`（容器 Secret / 600 挂载），两者都做 trim；
> ② 新增 `SECRET_MALFORMED`：密钥带空格/被换行截断时单独报配置错误，不再混进 `SIGN_MISMATCH`；
> ③ 生产环境不再允许"空密钥 + `direct_access=allow`"降级（仅 local/testing 可用）；
> ④ health 增 `internalSecretValid` / `internalSecretSource`，`gateway:probe` P7 显示密钥指纹便于两侧比对。
> 实测：真实密钥签名打 `internal/projects/12` → 200；错密钥/篡改头 → `SIGN_MISMATCH`；无签名 → `SIGN_MISSING`。

> **v1.5 变更（对 v1.4 的更正）**：`GATEWAY_INTERNAL_SECRET` 曾一度填为论坛侧
> `internal_sign_secret`，核对《统一 API 网关部署报告》§2.2「内部签名密钥按平台独立，
> 随机 32 字节 × 5，与 L2 AppSecret 不复用」后判定该值属于论坛上游、不能用于 workshop，
> 已回退为空并在 `gateway:probe` 增加 P7 项，使"缺密钥"成为可复现的检查项而非隐性故障。
> 新增 DV-29（入站内部签名密钥未下发）。§3 第 28 项据实改为"缺失可被自检发现"。
> 更正原因：密钥值对但平台不对，属于"配了却不通"的高危状态，比留空更难排查。

> **v1.3 变更**：接入《警院论坛账号确认接口》v2.2.0 后新增 DV-26（网关各路由 L2 白名单相互独立）、
> DV-27（账号确认不返回完整邮箱）；据实细化 DV-24（原「AppKey 未预置」仅对 `workshop/internal/*` 成立，
> `account-verify` 已预置）；§3 证据表补到 26 项。

> **v1.2 变更**：对接已部署网关（APISIX 3.13.0 @ `http://8.138.246.181`）后新增
> DV-23（L2 规范串版本差异）、DV-24（网关未预置本平台 AppKey）、DV-25（广场前缀与 internal 路径映射）；
> §2 增加 P0 阻塞项。差异均以网关实机探测结果为准，非推测。

> **v1.1 变更**：新增 DV-15 ~ DV-20；据实回改 DV-01 / DV-02 / DV-09 / DV-11 的「本项目实现」列（v1 网关层已落地，
> 原描述「未实施」已不成立）；DV-15、DV-16、DV-21、DV-22 于本次收口消解，移入 §1.1 留档；
> §3 证据表补到 23 项（含验签、会话、幂等、准入降级、事件幂等、CORS）。

> 判定口径：能在运行时被请求验证的才算“已对齐”，只写“已确认”不算。
> 已对齐项见 `README.md` 的对照表；本文件只登记偏离。

## 1 偏离总表

| 编号  | 偏离项                    | 规范要求                                                                                                | 本项目实现                                                                                                                                   | 偏离理由                                                                                            | 风险与补偿                                                                                                                                                                                           |
| ----- | ------------------------- | ------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DV-01 | 统一身份与 SSO            | §5.2：OIDC 授权码 + PKCE，BFF 会话，浏览器不存 Token                                                    | 存量仍走自建账号 + 请求头 `token`；**v1 层已实现 OIDC 授权码 + PKCE + BFF 会话**（`api/v1/workshop/auth/*`，Issuer 暂为 Mock），存量登录未动 | 现有登录、注册、邀请、免密登录均为存量功能，替换为 OIDC 会改变用户可见流程                          | **中**：存量入口无单点登录、无统一账号状态（v1 入口已具备会话壳，Issuer 待切真实）。补偿：`userId` 字段与规范 `sub` 语义对齐见数据映射文档；接入时只替换登录入口，不改业务接口                       |
| DV-02 | API 路径风格与版本        | §8.1：`/api/v1/{platform}/{resource}`，REST 名词 + 动词分离                                             | 存量 `/api/{module}/{method}[/{action}]` 方法分发，如 `/api/project/task/lists`；新增 `api/v1/workshop/*` 已为 REST 风格                     | 控制器由 `InvokeController::__invoke()` 动态派发 `a/b → a__b()`，改 REST 需重写全部控制器与前端调用 | **中**：网关无法按资源做版本路由。补偿：`api/v1/workshop/*` 已按 §8.1 落地 16 条 v1 别名路由（存量路由零改动），其余接口待逐个加别名；路径与控制器方法的对账由 `scripts/contract-check.mjs` 强制     |
| DV-03 | 响应信封                  | §8.2：`{code,message,data,requestId}`，失败 `data:null`                                                 | 保留 `{ret,msg,data}`，追加 `{code,requestId}`；失败 `data` 为对象或 `[]`                                                                    | 前端 2000+ 处按 `ret` 判成败，替换字段等于重写前端数据层                                            | **低**：两组字段并存，语义一致。代价是响应体略大、失败时 `data` 非 `null`                                                                                                                            |
| DV-04 | HTTP 状态码与业务码一致性 | §8.2：401/403/404/409/429 与 `code` 一致                                                                | 业务失败普遍 **HTTP 200 + `ret<=0`**；控制器内部 404 在非 JSON 请求下返回 HTML 页                                                            | 这是 DooTask 的既有约定，改成真实状态码会让 axios 拦截器把正常业务失败当网络错误                    | **中**：监控无法按状态码统计失败率。补偿：`code` 已按 `ret` 与状态双重映射；建议后续新增接口直接用真实状态码                                                                                         |
| DV-05 | 分页参数与上限            | §8.2：`page`/`pageSize`，默认 20、上限 100，超限 400                                                    | 参数名为 `pagesize`，上限由各接口 `Base::getPaginate()` 自行设定                                                                             | 改参数名会破坏前端所有列表请求                                                                      | **低**：本文件在 OpenAPI 中声明 `page`/`pagesize` 并标注上限 100；新增接口须遵守 100 上限                                                                                                            |
| DV-06 | 前端技术栈                | §3.1-1：React + TS + Vite + Ant Design 5 + GSAP 3                                                       | Vue 2.6 + Element UI + view-design + Laravel Mix（webpack）                                                                                  | 前端是 2000+ 文件的存量单页应用，换栈等于重写，与“功能不得改动”直接冲突                             | **高**：无法享受公共包与 Token 体系。补偿：新增前端文件按 `features/services/types/styles` 落位；格式规范只作用于新增目录（`.prettierignore` 排除 `resources/`）                                     |
| DV-07 | 类型检查与静态分析        | §3.1-3：`tsc` strict；§3.1-5：`typecheck` 脚本                                                          | 无 TypeScript；`typecheck` 用“契约静态对账”（中间件注册、路由挂载、OpenAPI ↔ 控制器方法）替代；PHP 侧用 `php -l`                             | 引入 PHPStan 需新增 composer 依赖并修存量告警，本期不扩大改动面                                     | **中**：抓不到类型错误。补偿：契约对账能拦住“文档写了代码没有”这类最易漂移的问题                                                                                                                     |
| DV-08 | 错误码可扩展性            | §8.2：固定码集合，新增需审批                                                                            | 已落地 9 个码常量于中间件；`ret=-1` 同时表示“未登录”与部分业务拒绝                                                                           | DooTask 的 `-1` 是既有约定，无法在不改业务的前提下细分                                              | **低**：`code` 对 `-1` 一律映射 `AUTH_REQUIRED`，可能把“业务拒绝”标成“未认证”；已在 OpenAPI 逐接口标注                                                                                               |
| DV-09 | 幂等与并发控制            | §8.3：`Idempotency-Key`、`ETag`/`version`，旧版本更新 409                                               | 存量写接口无幂等键；v1 写接口已挂 `Idempotency` 中间件（`Cache::add` 原子占位）；仍无 `ETag`                                                 | 写接口全部为存量功能，加幂等中间件需改请求体与前端重试逻辑                                          | **中**：跨系统重投可能重复创建。补偿：招募报名有部分唯一索引兜底；v1 写接口缺失该头直接 400 `IDEMPOTENCY_KEY_REQUIRED`（见 `app/Http/Middleware/Idempotency.php`）                                   |
| DV-10 | API 网关与限流            | §6.2：TLS 入口、限流 429、来源校验、边缘头清洗                                                          | 无网关，PHP 内置服务 / 反向代理直接暴露；无全局限流                                                                                          | 本期单机内网部署，无网关组件                                                                        | **中**：无限流与来源校验。补偿：`X-App-Code` 已在应用层校验，但**不能替代**网关                                                                                                                      |
| DV-11 | 事件与事务发件箱          | §9.3/§9.4：事件封套 + Outbox + `eventId` 幂等                                                           | 不生产、不消费存量事件；已新建 outbox 与 inbox 表及收发服务，但**无投递器**，出站靠同步接口触发                                              | DooTask 无消息中间件；接入需新增表、投递器与运维设施                                                | **中**：工坊状态变化无法自动传播到广场/档案。补偿：`event-envelope.yaml` 已定义封套与目录，outbox/inbox 表与 `PlatformEventService` 已落地（`enqueue()` 只落库、`consume()` 已可收），补投递器即可通 |
| DV-12 | 文件服务契约字段          | §10.1：`fileId/fileName/contentType/sizeBytes/accessMode/downloadUrl`                                   | 返回 `id/name/size/ext/url` 等既有字段                                                                                                       | 改字段名会破坏前端上传组件与所有引用                                                                | **低**：字段映射见数据映射文档；下载鉴权沿用 DooTask 既有权限校验                                                                                                                                    |
| DV-13 | 数据字典与 ID 形态        | §7.1：JSON `camelCase`、不暴露自增主键、时间带时区                                                      | JSON 字段 `snake_case`，暴露自增 `userid/project_id`，时间多为 `Y-m-d H:i:s`                                                                 | 前端与数据库强耦合，改名等于全量回归                                                                | **中**：跨平台对接需做字段转换层。补偿：映射表已产出；新增接口按 camelCase                                                                                                                           |
| DV-14 | 目录分层与公共包          | §3.2：`app/pages/features/components/services/types/styles/assets`；§11：公共包                         | 前端为 `resources/assets/js/{pages,components,store,functions}`；无公共包，同仓实现                                                          | 迁移目录会改变 webpack 入口与全部 import 路径                                                       | **低**：新增前端代码按 `features/services/types/styles` 组织；`styles/` 提供设计令牌兜底                                                                                                             |
| DV-17 | 成果广场作品契约          | §6.3：Work = `workId / sourceEntityId / title / authorSubjects / status / …` 字段名与状态机由广场侧定义 | `app/Services/ShowcaseClient.php` 按 §6.3 命名**推定**字段，未配 `SHOWCASE_BASE_URL` 时自动走 mock                                           | 广场接口文档与本平台未同步交付，无法据实对齐；先留扩展点保证方向正确                                | **中**：联调时字段名/状态枚举可能整体调整。补偿：映射集中在 `buildWorkFromProject()` 一个方法，改一处即可；响应原样透传给调用方，不在本平台二次解释                                                  |
| DV-18 | 事件投递器缺失            | §9.3：outbox 需由投递器以至少一次语义发往订阅方，失败重试                                               | 已建 `pre_platform_event_outbox` 与 `PlatformEventService::enqueue()`（**只落库不投递**）；收件箱 `consume()` 可用                           | 本期无消息中间件与常驻进程，加投递器需新增 worker 与运维设施                                        | **中**：项目完成事件不会自动抵达广场/档案。补偿：出站改由 `internal/projects/{id}/publish` 同步触发；`eventId` 幂等已在收件侧实现，补上投递器不会重复消费                                            |
| DV-19 | 无授权表                  | §7.3：权限点以 `{resource}:{action}` 声明并集中授权                                                     | 无授权表；`admission:read` 等判定用「项目发布者 ∪ 管理员」投影，写在控制器内                                                                 | 与 DV-06 同源；建授权表须改造存量全部控制器的权限判断，属存量改动                                   | **低**：无法声明更细粒度权限点（如按课程维度）。补偿：v1 接口当前权限面收敛在 4 个动作，投影规则在 `WorkshopController` 单点可查；补表时把投影换成策略查询即平滑迁移                                 |
| DV-20 | Cookie 前缀降级           | §5.2 / §12：会话 Cookie 应带 `__Host-` 前缀（强制 Secure + Path=/ + 无 Domain）                         | 本地 HTTP 下 `__Host-` 必然被浏览器丢弃，代码按 `WORKSHOP_SESSION_SECURE` 自动降级为 `workshop_sid`                                          | 本地无 HTTPS 与域名（见设计文档 Q4），不降级则本地无法联调                                          | **低**：本地与生产的 Cookie 名不同，易误配。补偿：实际生效名在 `GET api/v1/workshop/health` 的 `capabilities.cookieName` 直接可见；生产置 `WORKSHOP_SESSION_SECURE=true` 即恢复 `__Host-`            |

| DV-23 | L2 规范串版本不一致 | §12.4：`METHOD\npath\nquery\nbody` 四段 | 已部署网关的 `jy-l2-sign` 实际为七段：`appKey\nts\nnonce\nMETHOD\npath\n排序后的query\nsha256(body)` | 网关先落地、文档未同步；两侧任一字段不同签名必然不符，只能以实机为准 | **中**：同一份代码要服务两种对端。补偿：`GATEWAY_L2_VERSION` 切换（`gateway` / `spec`），默认 `gateway`；七段算法与官方验收脚本 `verify_gateway.py` 逐字节比对通过，并由 `tests/Unit/GatewaySignerTest.php` 锁死 |
| DV-24 | 网关未预置本平台的 workshop 服务间客户端 | §10.2.3：各平台持独立 AppKey / AppSecret | 实机：`jy-workshop-svc` 打 `workshop/internal/*` 返回 40108（未登记）；同一 AppKey 打 `answer/api/v1/account/verify` 返回 40105（已登记，见 DV-26） | 网关侧客户端由网关负责人按路由发放，本平台无权自行注册 | **高（阻塞）**：仅影响 workshop 自身的 L2 出站（准入回源、作品推送），账号确认链路不受影响。补偿：`gateway:probe` 可精确定位；凭据已于 2026-11-06 下发并填入 `EXAM_APP_SECRET` / `SHOWCASE_APP_SECRET`；剩余阻塞仅为该路由 `l2([...])` 未含 `jy-workshop-svc`，网关侧加一行即可，代码零改动 |
| DV-25 | 广场前缀与 internal 路径映射 | §12.4：跨平台调用按平台前缀寻址 | 已部署网关的服务间前缀是 `plaza` 且必须走 `internal`：`/api/v1/plaza/internal/*`；考试同理 `/api/v1/exam/internal/*` | `/api/v1/plaza/*`、`/api/v1/exam/*` 属于 L1 用户态路由，服务间签名调用会被 401 拒绝 | **中**：路径写错只表现为 401，容易误判为密钥问题。补偿：默认值已改为 internal；`php artisan gateway:probe` 会先验证路由可达再验签名 |
| DV-26 | 网关各路由的 L2 客户端白名单相互独立 | §10.2.3 隐含「一个平台一个 AppKey」 | `configs/routes.py` 每条路由各自调 `l2([...])`：`account-verify` 已含 `jy-workshop-svc`，`workshop-internal` 未含。2026-11-06 用真实 AppSecret 复验：前者签名通过、已到论坛业务层（P6b PASS，`gatewayCode=40104`），后者仍 `40108` | 网关按路由维度维护客户端清单，不是全局单点授权；同日复验已排除"密钥错/规范串错"两种可能 | **中→低**：密钥因素已排除，仅剩路由登记一项。补偿：`gateway:probe` 用 P4 与 P6 分别探测两条路由；§2 待办分列两项 |
| DV-27 | 账号确认接口不返回完整邮箱 | §10.1 最小必要 | 只返回 `e_mail_masked`（`a***@jspi.edu.cn`），无法用作本地账号邮箱 | 隐私设计：平台不需要真实邮箱，邮箱也不应跨平台流转 | **低**：首登建号用 `{sub}@sso.local` 占位（`IdentityBinder` 既有逻辑），`userinfo` 回显该占位值；真实邮箱始终只留在论坛侧 |
| DV-30 | workshop 缺匿名入口路由 | 需求 §4.1：登录入口应匿名可达（forum、learning 的登录路由均为匿名放行） | 网关 `workshop-api` 策略为 `L1 + 20 QPS/用户 + 熔断`，覆盖 `/api/v1/workshop/*`；实测匿名 `GET /health`、匿名 `POST /auth/forum-verify` 均返回 `401/40101`。对照：`/api/v1/forum/ping`、`/api/v1/learning/auth/login`、`/api/v1/plaza/works/public` 均 200 | 网关按平台前缀统一套用 L1，未为 workshop 拆分匿名子路由 | **高（阻塞前端经网关访问）**：登录接口要求令牌、而用户未登录不可能持令牌，形成死锁。当前未暴露只因前端直连 `8.134.60.240:8080` 绕过网关。补偿：新增 `workshop-auth` 路由（`/auth/*` + `/health`，匿名 + 10 次/分/IP），见变更单 CR-02；平台侧 `PUBLIC_PATHS` 已就绪，无需改代码 |

## 1.1 本次收口已消解的偏离（留档）

| 编号  | 原偏离                                      | 规范要求                                        | 消解方式                                                                                                                     | 验证                                                                   |
| ----- | ------------------------------------------- | ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| DV-15 | `pre_project_logs` 缺请求追踪列             | §10.5：业务日志可按 `requestId` 与访问日志关联  | 迁移 `2026_11_05_000012` 增加 `project_logs.request_id`；写入时取 `PlatformResponse::requestId()`                            | `DESC pre_project_logs` 可见该列；日志行含 trace                       |
| DV-16 | 无 `sub` 字段，用户主键为自增 `userid`      | §4.1：跨平台以不透明 `sub` 标识用户             | 新增 `pre_user_identity_binding`（`uk_issuer_subject`），`sub` 原值存储；首登建号用「插入 → 冲突回查」                       | `IdentityBinder` + 绑定表；`userinfo.userId` 返回 `usr_...` 而非数字   |
| DV-21 | v1 的 CORS 白名单被框架全局 CORS 覆盖成 `*` | §6.8：回显具体来源，`*` 不得与 Credentials 共存 | 新增全局中间件 `GatewayCors`（置于 `HandleCors` 之前，只接管 `api/v1/*`）：命中白名单回显来源，未命中写 `null`               | `WorkshopGatewayTest` 两个 CORS 用例；`curl -H 'Origin: ...'` 看响应头 |
| DV-22 | 发件箱一行都写不进去（无任何报错）          | §8.4：事件必须先落 outbox 再投递                | `PlatformEventService::newEventId()` 原本调用 `Str::ulid()`（Laravel 9 才有），8.48 下抛异常又被 `catch` 吞掉；改为手写 ULID | `ShowcaseBridgeTest::test_publish_returns_work_id_and_records_outbox`  |
| DV-29 | 入站内部签名密钥未下发                      | §5.5、部署报告 §2.2：网关回源注入 `X-Internal-Sign`，平台用同一把验；密钥按平台独立（32 字节 × 5） | 下发后填入 `GATEWAY_INTERNAL_SECRET`（来源 `secrets.json → internal.workshop.secret`）；读取统一走 `GatewaySigner::internalSecret()`，支持 `_FILE` 注入；新增 `SECRET_MALFORMED`、生产禁止"空密钥+allow"降级 | `GatewayInternalSecretTest` 14 项；真实密钥打 `internal/projects/12` → 200，错密钥 → `SIGN_MISMATCH`，无签名 → `SIGN_MISSING` |
| DV-31 | `trusted_cidr` 提前收紧导致直连被拒          | §5.5.1：来源网段校验应只在网关回源后启用       | 部署时 `GATEWAY_TRUSTED_CIDR` 被设为 `8.138.246.181/32`，而网关 upstream 仍是桩服务、并未回源本平台，于是浏览器直连 `GET /api/v1/workshop/projects` 返回 `403 NOT_TRUSTED_NETWORK`，全部非公开接口不可用 | 网段校验与 upstream 切换未同批执行；顺序颠倒会让"网段未放行"伪装成"密钥不对" | 已回退为空（`fromTrustedNetwork()` 在 CIDR 为空时放行），验证恢复为 `401 AUTH_REQUIRED`；网关切上游后**必须**改回 `8.138.246.181/32`。清空 CIDR 不影响身份防伪——`X-Internal-Sign` 验签仍是独立一道防线 |

> 口径说明：`docs/platform/实训平台_统一身份与网关接入说明.md` §14.1 的 DV 编号是**该文档自用**的一套早期编号，
> 与本文件不一致（例如同号 DV-12 在两处指不同事物）。两套编号各自内部自洽，**跨文件引用一律以本文件为准**。

## 2 未对齐清单（按“必须补”排序）

| 优先级 | 缺口                                   | 阻塞什么                                             | 建议动作                                                                                                                                         |
| ------ | -------------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| P0     | 网关 workshop/internal 客户端（DV-24） | workshop 自身的 L2 出站：准入回源、作品推送停在 mock | 网关侧在该路由的 `l2([...])` 加 `jy-workshop-svc`（AppSecret 已下发并配置）→ 关 mock → `php artisan gateway:probe` 验收 |
| P0     | 网关匿名入口路由（DV-30）              | 前端若改经网关访问，登录接口因要求令牌而死锁         | 网关侧新增 `workshop-auth`（`/api/v1/workshop/auth/*` + `/health`，匿名 + 10 次/分/IP，priority 高于 `workshop-api`）→ 匿名访问 `health` 应 200 → 见 CR-02 |
| P0     | 网关 upstream 指向桩服务（部署报告 §六-1） | 鉴权修好后请求仍打在 `127.0.0.1:8000` 桩上，到不了本平台 | 网关侧把 `workshop-api`/`-internal`/`-files` 的 upstream 改为 `8.134.60.240:8080` → 同批把 `GATEWAY_TRUSTED_CIDR` 设回 `8.138.246.181/32` → 见 CR-03 |
| P0     | 统一身份切真实 Issuer（DV-01）         | 真实单点登录、账号禁用全局生效                       | 论坛交付 OIDC 后改 `config/platform.php` 的 `sso.*`，v1 入口无需改代码                                                                           |
| P0     | 网关（DV-10）                          | 公网暴露前的 TLS、限流与来源校验                     | 由网关负责人实施；应用侧 `gateway.trust` 已具备验签与来源网段限制，不能替代网关                                                                  |
| P1     | 广场作品契约（DV-17）                  | 作品推送字段与状态机可与广场互通                     | 取广场 OpenAPI 后只改 `ShowcaseClient::buildWorkFromProject()`                                                                                   |
| P1     | 事件投递器（DV-18）                    | 工坊 ↔ 广场 ↔ 档案的自动联动                         | 按 `event-envelope.yaml` 写 worker 消费 outbox，收件侧幂等已就绪                                                                                 |
| P1     | 存量写接口幂等（DV-09）                | 客户端重试导致重复创建                               | 把 `Idempotency` 中间件挂到存量写路由前，须先确认前端带键                                                                                        |
| P2     | 密码代验转授权码（DV-01/§九.1）        | 长期方案：平台不应接触用户密码                       | 论坛 OIDC 就绪后改用 `auth/login`（PKCE），删掉 `forum-verify` 路由与 `ForumAccountClient`，其余不动                                             |
| P2     | REST 路径与版本（DV-02）               | 网关按资源做版本路由                                 | v1 别名已覆盖工坊 17 条；其余接口按需逐个加别名，存量路径保留                                                                                    |
| P2     | 授权表（DV-19）                        | 更细粒度权限点                                       | 建 `{resource}:{action}` 表后，把控制器内投影换成策略查询                                                                                        |

## 3 已对齐证据（可运行时复现）

| #   | 对齐项                                   | 规范条款                | 落地位置                                                   | 复现方式                                                                                                                |
| --- | ---------------------------------------- | ----------------------- | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| 1   | `X-Request-Id` 生成/沿用 + 响应头回带    | §8.1                    | `app/Http/Middleware/PlatformContract.php`                 | `curl -H "x-request-id: trace-abc-0001" .../api/system/setting` → 响应头与响应体 `requestId` 均为 `trace-abc-0001`      |
| 2   | `X-App-Code` 白名单                      | §8.1                    | 同上                                                       | `-H "x-app-code: hacker"` → HTTP 400 + `VALIDATION_ERROR`                                                               |
| 3   | 响应含 `code` 与 `requestId`             | §8.2                    | 同上（叠加式）                                             | 任意接口响应体末尾可见两个字段                                                                                          |
| 4   | 错误码集合固定 9 个                      | §8.2                    | 同上 `mapCode()`                                           | `grep constant docs/platform/openapi.yaml` → `ErrorCode` 枚举                                                           |
| 5   | 访问日志含 requestId/来源/结果/耗时      | §10.5、§12              | `storage/logs/platform-access.log`                         | `tail storage/logs/platform-access.log`                                                                                 |
| 6   | OpenAPI 3.x 随实现同仓提交               | §8.4                    | `docs/platform/openapi.yaml`                               | `node scripts/contract-check.mjs`                                                                                       |
| 7   | 契约文档与代码双向对账                   | §8.4                    | `scripts/contract-check.mjs`                               | 同上，改错方法名会失败                                                                                                  |
| 8   | 日志不记录 Token 与请求体                | §10.5                   | 中间件只记元信息                                           | 查看日志行字段，无 token/body                                                                                           |
| 9   | EditorConfig 统一                        | §3.1-4                  | 仓库既有 `.editorconfig`                                   | 文件存在，未改动                                                                                                        |
| 10  | 格式化纳入门禁                           | §3.1-4                  | `.prettierrc.json` + `npm run format:check`                | 命令通过                                                                                                                |
| 11  | Demo 标注模拟数据                        | §3.4                    | `demo/index.html`                                          | 浏览器打开，页头有「演示数据」标记                                                                                      |
| 12  | OpenAPI/事件/权限/映射/网络/日志六类文档 | §13                     | `docs/platform/`                                           | `ls docs/platform/`                                                                                                     |
| 13  | L1 可信头验签（含时间窗 ±300s）          | §5.3                    | `app/Services/GatewaySigner.php` + `GatewayTrust`          | 无签名 → 403 `SERVICE_ONLY`；错签名 → 403 `SIGN_MISMATCH`；正确签名 → 200                                               |
| 14  | 直连拒绝与来源网段限制                   | §6.2                    | `GatewayTrust`（`PUBLIC_PATHS` 精确白名单）                | 非白名单路径不带签名一律 403；白名单只有 login/callback/logged-out/health                                               |
| 15  | L2 出站签名                              | §5.4                    | `GatewaySigner::sign()`,`ExamClient`                       | 对端按 `method\npath\nquery\nbody` 重算可复现                                                                           |
| 16  | BFF 会话 + CSRF 双提交                   | §5.2                    | `app/Services/WorkshopSession.php`                         | `curl -i .../auth/login` → 302 至 IdP；回调后 `Set-Cookie`；无 CSRF 头写操作 403                                        |
| 17  | 未登录返回真实 401                       | §8.2                    | `PlatformResponse`(v1)                                     | `curl -o /dev/null -w '%{http_code}' .../auth/userinfo` → `401` + `AUTH_REQUIRED`                                       |
| 18  | `Idempotency-Key` 原子占位与回放         | §8.3                    | `app/Http/Middleware/Idempotency.php`                      | 同键重放 → `idempotentReplay:true` 且 `requestId` 为本次新值；处理中 → 409                                              |
| 19  | 准入超时降级                             | §6.6                    | `app/Services/ExamClient.php`                              | 回源超时 → `reason=pending` + HTTP 200，**不判为不合格**；未绑定课程 → `reason=not_required` 不触发回源                 |
| 20  | 事件收件箱幂等与封套校验                 | §9.4                    | `PlatformEventService::consume()`                          | 首投 `duplicated:false`；同键重放 `idempotentReplay:true`；新键同 eventId `duplicated:true`；缺字段 → 400 + `missing[]` |
| 21  | CORS 白名单回显具体来源                  | §6.8                    | `app/Http/Middleware/GatewayCors.php`                      | 白名单来源 → 回显 + `Allow-Credentials: true`；非白名单 → `null`；绝不出现 `*`                                          |
| 22  | 发件箱按契约落库                         | §8.4                    | `PlatformEventService::enqueue()`                          | 推送作品成功后 `pre_platform_event_outbox` 新增 1 行（`status=0`，待投递）                                              |
| 23  | OPTIONS 预检可被处理                     | §6.8                    | `routes/web.php` v1 组内 `Route::options`                  | `curl -X OPTIONS -H 'Origin: <白名单>' -i .../internal/projects/12` → 204 + 对应 CORS 头                                |
| 24  | 论坛账号确认并建立本平台会话             | 账号确认 v2.2.0 §五     | `WorkshopController::forumVerify()` + `ForumAccountClient` | `confirmed=true` → 建本地账号与会话（`Set-Cookie`）；同账号二次登录复用同一 `localUserId` 且 `created:false`            |
| 25  | 渠道故障与认证失败严格分离               | 账号确认 v2.2.0 §四     | `ForumAccountClient::parse()`                              | `40104` → 401 + `invalid_credentials`；`40105`/`40303`/`50200`/超时 → 503 + `channel_*`，且带 `data.gatewayCode` 原始码 |
| 26  | 账号确认路由已预置本平台客户端           | 账号确认 v2.2.0 §五步 1 | 网关实机探测                                               | 无签名 POST → `401/40105`；`jy-workshop-svc` + 假签名 → `40105`（**不是** `40108`），说明该路由白名单已含本平台         |
| 27  | 本平台 AppSecret 生效（账号确认链路）    | 账号确认 v2.2.0 §五步 2 | `.env` 的 `FORUM_APP_SECRET`；`ForumAccountClient::requestRemote()` | `FORUM_PROBE_PASS=... php artisan gateway:probe --login=<任意> ` → **P6b PASS**、`gatewayCode=40104`；`curl -X POST .../auth/forum-verify` → 401 + `data.source=remote`、`data.gatewayCode=40104` |
| 29  | 六路由对照锁定「白名单未登记」           | 部署报告 §四 路由基线   | 临时探测脚本（`GatewaySigner::callHeaders()` 七段签名，读取生产实例的真实 AppSecret） | 同一把 Key：`learning/internal/ping` → 200、`plaza/internal/ping` → 200、`exam/internal/ping` → 404（桩服务按契约拒绝，说明已过验签）、`workshop/internal/ping` → 401 `40108`。排除密钥与规范串因素 |
| 30  | 生产实例直连可用性（DV-31 修复后）       | §6.2 来源网段限制       | `8.134.60.240:8080` 的 `.env`（`GATEWAY_TRUSTED_CIDR` 空）→ `GatewayTrust::fromTrustedNetwork()` | `GET /api/v1/workshop/projects` → `401 AUTH_REQUIRED`（修复前为 `403 NOT_TRUSTED_NETWORK`）；`GET /health` → 200；`POST /auth/forum-verify` → 401 + `source=remote`、`gatewayCode=40104`、0.18s |
| 28  | 入站验签密钥生效（真实密钥端到端）       | §5.5、§5.5.1            | `.env` 的 `GATEWAY_INTERNAL_SECRET` → `GatewaySigner::internalSecret()` → `GatewayTrust::verify()` | 真实密钥签名打 `GET /api/v1/workshop/internal/projects/12` → 200；错密钥 / 篡改 `X-User-Role` → 403 `SIGN_MISMATCH`；无签名 → 403 `SIGN_MISSING`；`gateway:probe` P7 显示指纹 |
