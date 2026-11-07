# 三平台对接完成报告（实训平台侧）

> 依据《三平台对接方案 V2.0》与《三平台对接清单》
> 编写日期：2026-11-07 ｜ 交付方：实训平台（编码 `training`，旧编码 `workshop`）

---

## 1 对接范围与目标

先界定本平台在三平台中的职责边界，再说明本次交付覆盖什么、不覆盖什么，避免把对端职责算进本次工作量。

### 1.1 本平台在对接中的角色

三平台按职责分工：**自学平台（learning）** 提供课程进度，**考试平台（exam）** 提供考试与成绩，**实训平台（training，本平台）** 是勾选清单的发起方与实训闸门的判定方。

因此本平台承担三件事：**发布绑定**（告诉另两方这门实训任务要什么）、**接收成绩**（把考试成绩落到本平台的成绩库）、**判定准入**（用绑定清单与成绩算出 `canStart`）。

### 1.2 本次范围

| 类别 | 内容 |
|---|---|
| **范围内** | 勾选绑定的发布与查询；成绩事件接收、落库、覆盖；实训闸门判定；能力发现与握手；出站发件箱与投递；闭环补拉与回执 |
| **范围外** | 自学平台的课程进度推送；考试平台的闸门回源与拦截；考试成绩的产生；题库与题目正文 |
| **明确不交换** | 题目正文、试题答案、用户密码与令牌、身份档案明细（仅交换十进制 uid） |

范围外三项均为对端职责，本平台只需保证接口契约可供其调用。其中「考试闸门回源」是 `learning` 提供、`exam` 调用，与本平台无直接调用关系。

### 1.3 目标（可验证）

1. 实训任务能勾选「课程 + 考试」形成绑定清单，并推送给另两方；
2. 考试成绩能按任务归属落库，补考可刷新，乱序不误覆盖；
3. 接取实训任务时，能按绑定清单判定 `canStart`，并给出可读的未达标原因；
4. 所有服务间调用可按方案第四节签名验证，越权与超窗被拒；
5. 全链路响应为 JSON，杜绝「2xx + HTML」的假成功。

---

## 2 与方案的一致性核对

对接的前提是「与方案一字不差」，因此逐条列出方案条款与实现的对应关系，接口、字段、流程三层分别核对。

### 2.1 接口对照

| 方案条款 | 方案定义 | 本平台实现 | 一致性 |
|---|---|---|---|
| 清单 3.1 | `GET /peers` 能力发现（免鉴权） | `GET /api/v1/training/peers` | 一致 |
| 清单 3.1 | `GET /peers/{source}/handshake` 握手（免鉴权） | `GET /api/v1/training/peers/{source}/handshake` | 一致 |
| 清单 3.5 | 实训推送任务绑定，自学/考试接收 | `POST /api/v1/training/tasks/{taskId}/bindings` 发布、`GET` 查询 | 一致 |
| 清单 3.3 | 成绩回传：考试推送、实训接收 | `POST /api/v1/training/peers/exam/events` | 一致 |
| 清单 3.4 | 实训闸门判定 | `GET /api/v1/training/peers/{source}/students/{userId}/training-eligibility/{taskId}` | 一致 |
| 方案 6.1 | 发件箱模式与补拉 | `GET /api/v1/training/peers/{source}/pull?afterId=` | 一致 |
| 方案 6.2 | 闭环回执 | `POST /api/v1/training/peers/{source}/receipts` | 一致 |
| 方案 6.2 | 失败重放 | `POST /api/v1/training/peers/{source}/replay` | 一致 |

两种前缀指向同一控制器：`/api/v1/training/peers/...`（内网直连，仅对端层签名）与 `/api/v1/training/internal/peers/...`（经网关，网关先做 L2、本平台再做对端层）。应用侧不重复验 L2，避免同一签名验两遍。

### 2.2 字段对照

**事件封套（方案 §4.1，6 个必填字段）**

| 字段 | 类型 | 本平台处理 |
|---|---|---|
| `eventId` | string | 幂等键，与 `sourcePlatform` 组成唯一索引 |
| `eventType` | string | 必须命中目录，本期仅 `exam.result.published` |
| `eventVersion` | string | 仅接受 `1.0` |
| `occurredAt` | RFC3339 | 记录，不参与判定 |
| `sourcePlatform` | string | 必须为 `exam`，且与通道编码一致 |
| `data` | object | 见下 |

**成绩 data（清单 3.3）**

| 字段 | 必填 | 本平台落库列 | 备注 |
|---|---|---|---|
| `userId` | 是 | `subject` | 归一化为十进制 uid |
| `examId` | 是 | `exam_id` | 兼容 `exam_2` 与裸数字 |
| `taskId` | 是 | `task_id` | **缺则整笔拒收**（红线） |
| `courseId` | 否 | `course_id` | 路径中不传 `course_` 前缀 |
| `score` | 否 | `score` | 整数 |
| `maxScore` | 否 | `max_score` | 缺省 100 |
| `passed` | 否 | `passed` | 布尔 |
| `takenAt` | 否 | `taken_at` | 转 UTC；用于乱序保护 |

**响应信封**：沿用平台既有 `{ret, msg, data, code, requestId}`，`code === 'OK'` 是对外的成功判据。

### 2.3 流程对照

方案要求的三段流程逐段落实：

1. **勾选发布**：管理端发布实训任务时勾选课程与考试 → 写 `training_task_bindings` → 同事务写 `training_peer_outbox`（`training.task.published`，目标 learning 与 exam 各一条）→ 由 `peer:dispatch` 投递。
2. **成绩回传**：考试考后推送 `exam.result.published` → 验签、验封套、幂等去重 → 按 `(userId, examId, taskId)` 落库，最新覆盖。
3. **实训闸门**：接取任务时读绑定清单，逐项比对成绩，全部达标返回 `canStart=true`；否则返回 false 并给出 `reason`。

### 2.4 与既有 V3.0 契约的隔离

平台内另有一套 V3.0 事件契约（`PlatformEventService`，封套 10 字段）。本方案封套只有 6 字段，**直接复用会把合法事件全部拒掉**，因此新建独立实现，不复用旧校验。两套契约的边界已写在 `PeerInboxService` 类注释里，后续维护者不应合并。

---

## 3 异常与边界处理

正常路径只有一条，异常路径有十几条。以下按鉴权、事件、成绩覆盖、出站投递、闸门、红线六类分别说明处理方式与返回码。

### 3.1 鉴权异常

| 场景 | 返回 | 处理要点 |
|---|---|---|
| 缺 `X-App-Code` | 401 `AUTH_REQUIRED` | 提示缺哪个头 |
| 通道未登记 / 未开启 / 未配密钥 | 403 `PEER_NOT_OPEN` | 未配密钥按「通道不可用」处理，**不跳过验签** |
| 平台编码本身非法 | 400 `APP_CODE_INVALID` | 契约层拦截，早于验签 |
| `X-Request-Id` 格式非法 | 401 `AUTH_REQUIRED` | 8~128 位 `[A-Za-z0-9._:-]` |
| 时间戳超窗 | 401 `AUTH_REQUIRED` | 窗口 ±300 秒，报文中回显实际偏移秒数 |
| 签名不符 | 401 `AUTH_REQUIRED` | 常量时间比对；对**原始字节**求哈希 |
| 用 exam 的密钥访问 learning 通道 | 403 `PEER_SCOPE_DENIED` | 通道绑定校验 |

时钟偏移是三端联调最常见的 401 来源，因此错误信息直接给出「偏移 N 秒，上限 300 秒」，便于对端自查。

### 3.2 事件异常

| 场景 | 返回 | 处理 |
|---|---|---|
| 封套缺字段 | 400 `VALIDATION_ERROR` | 列出缺失字段名 |
| `eventType` 不在目录 | 422 `EVENT_NOT_ALLOWED` | 不重试 |
| `eventVersion` 非 1.0 | 422 `EVENT_NOT_ALLOWED` | 防止未来版本静默写入 |
| `sourcePlatform` 非 exam | 422 `EVENT_NOT_ALLOWED` | 不认「用 learning 密钥发 exam 事件」 |
| 缺 `taskId` | 400 `VALIDATION_ERROR` | **整笔回滚，不写脏行** |
| 同一 `eventId` 重投 | 200，`duplicated=true` | 唯一索引 + 先查后写双保险 |
| 处理过程抛异常 | 500 `INTERNAL_ERROR` | 事务回滚，收件箱不留半条 |

### 3.3 成绩覆盖与乱序

补考依赖「最新覆盖」，但网络重投会让历史事件晚到。因此覆盖前比较 `takenAt`：**更早的成绩不得覆盖更晚的成绩**。这条不做的话，一次重投就能把 93 分打回 85 分，且极难排查（数据看着合法、时间戳也对）。

### 3.4 出站投递异常

| 场景 | 处理 |
|---|---|
| 2xx 且 `Content-Type` 为 JSON | 视为送达 |
| **2xx 但返回 HTML** | **判失败并重试**——网关 catch-all 会对未知路径回 200 页面，只看状态码会得到假成功 |
| 2xx 但 JSON 不可解析 | 判失败并重试 |
| 400/401/403/404/422 | 判**永久失败**，直接死信，不做无谓重试 |
| 其他（超时、5xx、429） | 退避重试：1s → 5s → 30s → 2min → 10min → 1h，累计 6 次后死信 |
| 死信 | 落 `status=2` 并写 `last_error`，可用 `replay` 重放 |

### 3.5 闸门边界

| 场景 | `reason` | 说明 |
|---|---|---|
| 任务未绑定任何考试 | `not_bound` | 红线：未绑定视为未达标，不因「没要求」而放行 |
| 绑定了但无成绩 | `missing_exam` | 分清「没考」与「考了没过」，提示文案不同 |
| 有成绩但未达 90 | `scores_insufficient` | 返回 `minScore` 与逐项 `requirements` |
| 全部达标 | 空 | `canStart=true` |

`min_score_mode` 支持 `all`（逐项全达标，默认，符合方案 §3.2）与 `average`（均分达标），便于后续调整口径而不改代码。闸门结果**不缓存**，每次都取最新成绩——方案红线明确禁止信任缓存。

### 3.6 红线落实

| 红线 | 落实方式 |
|---|---|
| 成绩不回自学 | 收件箱仅接受 `sourcePlatform=exam`，learning 通道无法投递成绩 |
| 不绕过勾选 | 闸门判定唯一输入是 `training_task_bindings` |
| 不缓存闸门状态 | 每次实时计算，写成绩后主动失效缓存键 |
| 成绩不归错任务 | 缺 `taskId` 整笔拒收，落库唯一键含 `task_id` |
| 路径不传 `course_2` | `PeerIdentity::courseId()` 归一化去掉前缀 |
| 密钥不落盘明文 | 密钥只从环境变量读取，日志不打印；报错只回布尔与码 |

---

## 4 完成情况

按对接清单的 15 项动作逐项标记状态，并给出代码交付清单，便于对端与验收方核对。

### 4.1 清单动作完成表

| # | 对接动作 | 负责 | 状态 | 落地位置 |
|---|---|---|---|---|
| 1 | 能力发现 | 三方 | 完成 | `TrainingPeerController::discover` |
| 2 | 握手自检 | 三方 | 完成 | `TrainingPeerController::handshake` |
| 3 | 申请并保管密钥 | 三方 | **待办** | 需线下交换后写入 `PEER_*_SECRET` |
| 4 | 实现勾选发布 | 实训 | 完成 | `TrainingTaskController::publish` + `TaskBindingService` |
| 5 | 接收任务绑定 | 自学/考试 | 对端职责 | 本平台已推送事件 |
| 6 | 进度推送 | 自学 | 对端职责 | — |
| 7 | 考试闸门回源 | 考试 | 对端职责 | — |
| 8 | 考试闸门拦截 | 考试 | 对端职责 | — |
| 9 | 成绩回传 | 考试 | 对端职责 | 本平台提供接收端 |
| 10 | 成绩接收与覆盖 | 实训 | 完成 | `PeerInboxService::consume` |
| 11 | 实训闸门判定 | 实训 | 完成 | `TrainingEligibilityService::evaluate` |
| 12 | 实训闸门拦截 | 实训 | **部分** | 判定接口已就绪，前端锁定交互未接 |
| 13 | HMAC 签名 | 三方 | 完成 | `PeerSigner` |
| 14 | 幂等去重 | 三方 | 完成 | 唯一索引 + 收件箱 |
| 15 | 响应格式约束 | 三方 | 完成 | 全部走 `PlatformResponse` |

### 4.2 代码交付清单

| 类型 | 文件 |
|---|---|
| 配置 | `config/platform.php`（新增 `peers` 与 `training` 段） |
| 迁移 | `2026_11_06_000001_create_training_peer_tables.php`（4 张表） |
| 模型 | `TrainingTaskBinding`、`TrainingExamScore`、`TrainingPeerInbox`、`TrainingPeerOutbox` |
| 服务 | `PeerSigner`、`PeerIdentity`、`TrainingEligibilityService`、`PeerInboxService`、`PeerOutboxService`、`TaskBindingService` |
| 中间件 | `PeerAuth` |
| 控制器 | `TrainingPeerController`、`TrainingTaskController` |
| 命令 | `peer:dispatch` |
| 路由 | `routes/web.php`（`api/v1/training` 前缀，三组） |
| 测试 | `TrainingPeerIntegrationTest`（24）、`PeerOutboxDispatchTest`（8） |

---

## 5 验证结果

分两层验证：自动化测试保证逻辑正确与无回归，端到端实测保证「线上真的这样走」，后者使用真实 HTTP 与真实签名。

### 5.1 自动化测试

| 项 | 结果 |
|---|---|
| 对接新增用例 | **32 个**（集成 24 + 出站 8） |
| 全量回归 | **154 tests / 590 assertions，全部通过** |
| 基线对比 | 改造前 122 tests / 490 assertions，**零回归** |

### 5.2 端到端实测

用真实 HTTP（`php -S` + 真实 HMAC 签名 + 真实数据库）跑通 24 项，全部通过：

| 编号 | 用例 | 结果 |
|---|---|---|
| S1 | 能力发现免鉴权可访问，返回契约与事件目录 | PASS |
| S2 | 握手：合法签名，`peerEnabled=true` | PASS |
| S3 | 错误签名被拒 401 `AUTH_REQUIRED` | PASS |
| S3b | 握手能识别编码不匹配（`appCodeMatched=false`） | PASS |
| S4 | 成绩事件首次推送被接收 | PASS |
| S5 | 同一 `eventId` 重投幂等，不重复入库 | PASS |
| S6 | 成绩达标 → `canStart=true` | PASS |
| S7 | 缺 `taskId` 拒收 400 | PASS |
| S8 | 非目录事件类型拒收 422 `EVENT_NOT_ALLOWED` | PASS |
| S9 | 跨通道越权 403 `PEER_SCOPE_DENIED` | PASS |
| S10 | 时间戳超窗 401，回显偏移 400 秒 | PASS |
| S11 | 补考未达标 → `scores_insufficient` | PASS |
| S12 | 乱序旧成绩不回冲（仍为 85） | PASS |
| S12b | 补考 93 覆盖 85，闸门重新开放 | PASS |
| S13 | 未绑定任务 → `not_bound` | PASS |
| S14 | 绑定考试无成绩 → `missing_exam` | PASS |
| S15 | 补拉返回待投递事件，游标正确 | PASS |
| S16 | 回执 `accepted` 后置为已投递 | PASS |
| S17 | 回执指向不存在事件 422 | PASS |
| S18 | 通道未登记 403 `PEER_NOT_OPEN` | PASS |
| S18b | 非法平台编码 400 `APP_CODE_INVALID` | PASS |
| S18c | `training` 编码通过契约层 | PASS |
| S19 | 响应为 JSON，无 HTML 兜底 | PASS |
| S20 | 收件箱去重后仅 4 条有效事件 | PASS |

**24 / 24 通过**，对应清单 7.2 的八条验收用例全部命中（勾选发布、成绩回传、实训达标、实训未达标、补考刷新、签名错误、幂等、缺 taskId），另补边界 16 项。

### 5.3 验证中发现并修正的缺陷

**契约层白名单缺 `training` 编码。** `PlatformContract::PLATFORM_CODES` 仍是旧的五平台集合（`forum/learning/exam/workshop/showcase`），而方案已把实训平台编码定为 `training`。任何携带 `X-App-Code: training` 的请求会在契约层被拦成 400 `APP_CODE_INVALID`，**根本到不了验签**。

已修正为六码集合（保留 `workshop` 兼容存量），并把错误提示文案同步。这类缺陷在单测里不易暴露——单测直接调用中间件或控制器，不经过完整的 HTTP 栈；是端到端实测才发现的。

另记录一条**设计特性**（非缺陷）：握手端点按方案 §4.2 免鉴权，因此不能用它验证签名是否生效，也无法在握手阶段拦截越权。它只回 `appCodeMatched`、`peerEnabled`、`authMode` 供联调自检。要验证验签必须打业务端点。

---

## 6 未完成项与依赖

以下事项不在本次代码交付范围内，或需要对方配合，上线前必须逐项清零。

| 项 | 类型 | 说明 |
|---|---|---|
| 生产环境 `PEER_*` 配置 | 待办 | 生产 `.env` 当前无任何 `PEER_` 变量，通道全部 `enabled=false`，需配 secret 与 base_url |
| 密钥线下交换 | 依赖三方 | 三份 secret 需各自申请、线下交换，不入库不进日志 |
| 前端勾选与锁定交互 | 待办 | 后端接口已就绪；发布页勾选组件、接取页未达标提示与置灰未实现 |
| 出站投递调度 | 待办 | `peer:dispatch` 需 cron 或 supervisor 常驻，当前仅能手工触发 |
| 自学进度推送、考试闸门回源 | 对端职责 | 需 learning 提供回源接口，exam 调用 |
| 生产部署 | 待办 | 本次仅本地验证，未部署 |

---

## 7 上线前待办

按执行顺序排列，前四项完成前不建议对外宣称「已对接」。

1. 生产 `.env` 增加 `PEER_SELF=training`、`PEER_APP_CODE=training`、`PEER_AUTH_MODE=auto`、`PEER_EXAM_ENABLED/SECRET/BASE_URL`、`PEER_LEARNING_ENABLED/SECRET/BASE_URL`；
2. 执行迁移建 4 张表；
3. 配置 `peer:dispatch` 定时任务（建议每分钟）；
4. 三方约定同一时刻把 `auth_mode` 从 `none` 切到 `auto`，切换前用 `handshake` 确认 `appCodeMatched=true` 与 `peerEnabled=true`；
5. 前端补勾选与锁定交互后，按清单 7.2 再走一遍人工验收并签字。

---

## 附：验证环境

以下为本次验证所使用的实际环境与参数，便于复现。

| 项 | 值 |
|---|---|
| 应用 | Laravel（本地 `php -S 127.0.0.1:2222`） |
| 数据库 | MariaDB 10.11（本地 3307） |
| 缓存 | Redis 5.0（本地 6379） |
| 证书/签名 | HMAC-SHA256，测试密钥 `local-e2e-*`（仅本地，未入库） |
| 验证脚本 | `test-peer-e2e`（24 项，真实 HTTP） |
