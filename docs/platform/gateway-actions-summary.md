# 网关侧待办事项总结 · 警务实训平台

> 本文件是 `CR-20261106-01` 的**修订版（v2.0）**，可直接转发网关负责人。
> 与 v1.0 的差别：v1.0 列出的 CR-01/02/03 三项，经 2026-11-06 14:15 实测**确认均已完成**；
> 本版更新状态，并**新增一项此前未识别的阻塞项 G-01**（P0）。

| 项 | 内容 |
| --- | --- |
| 文档版本 | v2.0 / 2026-11-06 14:20 (GMT+8) |
| 提出方 | 警务实训平台（platform = `workshop`） |
| 接收方 | 统一 API 网关运维（APISIX @ `8.138.246.181`） |
| 平台实例 | `http://8.134.60.240:8080` |
| 平台 AppKey | `jy-workshop-svc` |
| 前序文档 | `CR-20261106-01`（v1.0，其中 CR-01/02/03 已闭环，CR-04 仍待定） |

---

## 0. 结论摘要

**三项待办已完成，剩一项关键阻塞，且这一项不在原变更单里。**

| 编号 | 责任方 | 事项 | 优先级 | 状态 |
| --- | --- | --- | --- | --- |
| （原 CR-01） | 网关侧 | `workshop-internal` L2 白名单放行 `jy-workshop-svc` | P0 | **已完成** ✅ |
| （原 CR-02） | 网关侧 | 新增 `workshop-auth` 匿名路由 | P0 | **已完成** ✅ |
| （原 CR-03） | 网关侧 | 三条 `workshop-*` upstream 切至 `8.134.60.240:8080` | P0 | **已完成** ✅ |
| **G-01（新）** | **网关侧** | **`workshop-api` 的 L1 强制策略与本平台 Cookie 鉴权冲突** | **P0** | **待处理** ❌ |
| G-02 | 我方 | `GATEWAY_TRUSTED_CIDR` 收敛为网关 IP | P1 | 条件触发，暂不执行 |
| G-03 | 双方 | `workshop-files` 路由与平台实现 | P2 | 暂缓 |
| G-04 | 网关侧 | 桩服务 `jy-stub-workshop` 下线确认 | P2 | 待确认 |

一句话：**登录链路与服务间调用已经打通，但"用户登录后访问业务页面"这条路仍被网关 L1 拦住。**

---

## 1. 当前实测状态（2026-11-06 14:15，经网关 `8.138.246.181`）

| 请求 | HTTP | 响应特征 | 判定 |
| --- | --- | --- | --- |
| `GET /api/v1/workshop/health` | 200 | `{"ret":1,...,"service":"workshop"}`（平台信封） | 匿名放行 ✅ 上游已切 ✅ |
| `POST /api/v1/workshop/auth/forum-verify` | 401 | `reason=invalid_credentials`、`gatewayCode=40104`、`source=remote` | 匿名放行 ✅ 全链路通 ✅ |
| `GET /api/v1/workshop/auth/userinfo` | 401 | `code=AUTH_REQUIRED`（**平台业务码**） | 匿名放行 ✅ |
| `GET /api/v1/workshop/internal/projects/21` | **200** | `{"ret":1,"data":{"projectId":21,...}}`（平台真实数据） | L2 白名单 ✅ 上游已切 ✅ |
| `GET /api/v1/workshop/projects` | 401 | `{"message":"缺少访问令牌","code":40101}`（**网关错误码**） | **被 L1 拦截** ❌ |
| `GET /api/v1/workshop/recruitments` | 401 | `code=40101` | **被 L1 拦截** ❌ |
| `GET /api/v1/workshop/files` | 401 | `code=40101` | **被 L1 拦截** ❌ |

**如何读这张表**：`40101` 与 `AUTH_REQUIRED` 的差别就是判断依据 ——
`40101` 是网关 `jy-l1-token` 发出的，说明请求**没有到达平台**；
`AUTH_REQUIRED` 是平台发出的，说明请求**已穿透网关到达应用层**。

---

## 2. 待办事项

### G-01 · `workshop-api` 的 L1 强制策略与本平台会话鉴权冲突【P0 · 网关侧】

| 字段 | 内容 |
| --- | --- |
| **现状** | `/api/v1/workshop/*` 下，`auth/*` 与 `health` 已匿名可达（原 CR-02 生效）；但 `projects`、`recruitments`、`files` 等业务路径仍要求 L1 令牌，无令牌一律 `401 / 40101` |
| **具体修改内容** | 将 `workshop-api` 路由的鉴权策略由「L1 令牌必须」改为「**L1 可选（匿名透传）**」。即：请求带 L1 令牌时照旧验签并注入 `X-User-Id`；不带令牌时**不拦截**，直接透传到上游，由平台应用层自行鉴权 |
| **涉及位置** | `/root/gw-deploy/configs/routes.py` → `workshop-api` 条目的鉴权策略节点。参照 `learning-auth` / `plaza-public` 的匿名写法，但**保留** L1 插件（改为 non-blocking / 可选），使两种模式共存 |
| **预期效果** | ① 未登录访问 `/projects` → 401，但 `code` 为平台业务码 `AUTH_REQUIRED`，**不再是 `40101`**；② 已登录（携带 `workshop_sid` Cookie）→ 200；③ 带有效 L1 令牌 → 仍注入 `X-User-Id`，行为不变 |
| **责任方** | **网关负责人** |
| **连带影响（必须知晓）** | `workshop-api` 现有策略为 `L1 + 20 QPS/用户`。L1 变为可选后，**匿名请求网关无法识别用户身份**，"20 QPS/用户"对这部分流量退化为按 IP 或全局限流。若需保留用户级限流，需网关侧改为「有令牌按用户、无令牌按 IP」的双轨限流；否则请知悉该限流维度将部分失效（平台侧另有 `forum-verify` 5 次失败节流兜底，但业务接口无应用层限流） |
| **理由** | ① 策略挂在网关路由上，只有网关侧能改，我方无权限；② **我方不需要改一行代码** —— `GatewayTrust::verify()` 已有五条分支，其中「签名缺失 + `GATEWAY_DIRECT_ACCESS=allow`」会降级为匿名放行；`GatewayIdentity::resolve()` 已实现「会话 Cookie → 网关可信头 → 存量 token」三级解析。两条身份路径已并存且经过实测 |
| **验收标准** | 见下方命令 |

**验收命令**：

```bash
GW=http://8.138.246.181

# 1) 匿名访问业务接口：不再被网关拦截
curl -s -o /tmp/a.json -w '%{http_code}\n' $GW/api/v1/workshop/projects
grep -q '"code":"AUTH_REQUIRED"' /tmp/a.json && echo "PASS 已到应用层" || echo "FAIL 仍被网关拦截"
# 期望：401 + code=AUTH_REQUIRED（不是 40101）

# 2) 带有效会话访问：应返回数据
#    （需先取得 workshop_sid，由平台侧提供测试会话）
curl -s -o /dev/null -w '%{http_code}\n' -H 'Cookie: workshop_sid=<SID>' $GW/api/v1/workshop/projects
# 期望：200

# 3) 确认 L1 令牌路径未被破坏
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer <有效L1令牌>" $GW/api/v1/workshop/projects
# 期望：200（行为与改动前一致）
```

**为什么这一条必须做**：本平台的登录流程是「论坛账号确认 → 建立平台会话 → 下发 `workshop_sid` Cookie」（见接入说明 §4.3.2），**全程不产生也不持有 L1 令牌**。网关若强制要 L1，用户在网关入口登录成功后，访问任何业务页面仍会 `40101` —— 表现为"登录成功但页面一片空白"，且错误码来自网关，前端和运维都会误判成密钥问题。

**匿名透传有两种实现方式，请网关侧二选一（连带影响不同，须一并确认）**：

| | 方式 A（推荐） | 方式 B（网关侧改动更小） |
| --- | --- | --- |
| 网关动作 | 透传时**仍计算并注入有效 `X-Internal-Sign`**，`X-User-Id` 留空 | 完全不带签名，直接透传 |
| 平台侧判定 | 验签通过（`trusted=true`）但无身份 → 匿名 → `401 AUTH_REQUIRED` | 走"签名缺失 + 允许直连"分支降级为匿名（`trusted=false`） |
| 平台配置要求 | `GATEWAY_DIRECT_ACCESS` **可收紧为 `reject`** | `GATEWAY_DIRECT_ACCESS` **必须保持 `allow`** |
| 安全收益 | 能在应用层区分"经网关"与"直连"，直连可彻底关闭 | 无法区分；安全性依赖"无签名则可信头不被采信"这一条 |
| 结论 | **建议采用 A** | 可接受，但 R-3 / R-6 的收敛空间会丧失 |

> 我方已具备承接两种方式的代码（`app/Http/Middleware/GatewayTrust.php::verify()` 五分支），**无需改代码**，只需据此决定平台 `.env` 的 `GATEWAY_DIRECT_ACCESS` 取值。请网关侧在实施时明确告知采用哪种方式。

**替代方案（不推荐）**：若网关侧要求必须保留 L1 强制，则本平台需改接网关的 OAuth 登录流程、放弃论坛账号确认链路，属架构级返工，涉及登录页、身份绑定、会话三处重写。

---

### G-02 · `GATEWAY_TRUSTED_CIDR` 收敛【P1 · 我方 · 条件触发】

| 字段 | 内容 |
| --- | --- |
| 现状 | 值为空。任意来源可直连 `8.134.60.240:8080`，可信身份仍受 `X-Internal-Sign` 验签保护 |
| 具体修改内容 | 将 `GATEWAY_TRUSTED_CIDR` 设为 `8.138.246.181/32`，仅允许网关回源 |
| 涉及位置 | 平台 `.env`（`/www/wwwroot/dootask/.env`），改后执行 `php artisan config:clear` |
| 预期效果 | 非网关来源访问非公开路径 → `403 / NOT_TRUSTED_NETWORK`；网关回源正常 |
| 责任方 | **我方** |
| 理由 | `.env` 与平台代码在我方控制范围 |
| **执行时机（关键）** | **必须满足两个前提才可执行**：① G-01 已完成；② 前端确认已改为经网关访问。若前端仍直连 8080（当前状态），**执行此项会导致浏览器端全部业务接口 403** |
| 验收标准 | 经网关访问 `/projects` 返回 401 `AUTH_REQUIRED`（正常）；直连 `curl http://8.134.60.240:8080/api/v1/workshop/projects` 返回 403 `NOT_TRUSTED_NETWORK`（预期） |

> 历史教训：2026-11-06 上午曾在 upstream 尚未切换时提前把该项设为网关 IP，导致线上业务接口全部 403，已回退。**顺序不能颠倒。**

---

### G-03 · `workshop-files` 路由与平台实现【P2 · 双方 · 暂缓】

| 字段 | 内容 |
| --- | --- |
| 现状 | 网关侧存在 `workshop-files` 路由（`L1 + 5 QPS/用户`，50 MB），经网关访问返回 `40101`；平台侧**尚未实现** `/api/v1/workshop/files*` 路径（`routes/web.php` 无对应路由） |
| 具体修改内容 | 暂无。待平台实现文件接口后，反向确认该路由的 upstream 与限流参数 |
| 责任方 | **双方**（我方先实现，网关侧再对参数） |
| 理由 | 平台接口不存在，网关侧单独放开无意义 |
| 验收标准 | 平台侧 `routes/web.php` 出现 files 路由后，经网关访问不再 `40101` |

---

### G-04 · 桩服务 `jy-stub-workshop` 下线确认【P2 · 网关侧】

| 字段 | 内容 |
| --- | --- |
| 现状 | 实测确认 `workshop-*` 三条路由已指向真实平台（health 与 internal 均返回平台真实数据）。但**桩服务进程是否仍在运行未确认** |
| 具体修改内容 | 确认后执行 `systemctl disable --now jy-stub-workshop`，**保留 unit 文件**以便回滚 |
| 涉及位置 | 网关机 systemd |
| 预期效果 | 消除"请求打到桩却以为打到真实平台"的误判风险 |
| 责任方 | **网关负责人** |
| 理由 | 只有网关机有 systemd 权限 |
| 验收标准 | `systemctl is-active jy-stub-workshop` → `inactive`；且 `curl $GW/api/v1/workshop/health` 仍返回 `"service":"workshop"` |

---

## 3. 彻底性评估

**问：这些修改全部完成后，问题是否彻底解决？**

**答：解决 2/3，剩 1/3 取决于 G-01。分三条链路看：**

| 链路 | 现状 | 完成 G-01 后 | 是否根治 |
| --- | --- | --- | --- |
| ① 用户登录（浏览器 → 网关 → 平台 → 网关 → 论坛） | **已通** | 不变 | ✅ **已根治** |
| ② 服务间调用（平台 → 网关 → exam/plaza） | **已通** | 不变 | ✅ **已根治** |
| ③ 用户业务操作（浏览器 → 网关 → 平台业务接口） | **被 L1 拦（40101）** | 放行，由平台 Cookie 鉴权 | ⚠️ **取决于 G-01** |

**结论**：G-01 是最后一块拼图，且**只有网关侧能完成**。在 G-01 落地前，若前端改为经网关访问，业务页面会整体不可用；若前端保持直连 8080，则功能可用但网关的限流、审计、统一入口全部落空 —— 两条路都不理想。**因此 G-01 应在任何前端切换动作之前完成。**

---

## 4. 遗留风险清单

| 编号 | 风险 | 等级 | 归属 | 说明与建议 |
| --- | --- | --- | --- | --- |
| R-1 | G-01 未解决前，前端一旦切网关入口，业务页面全白（`40101`） | **高** | 网关侧 | 见 §3。建议 G-01 与前端切换**同批**，或 G-01 先行 |
| R-2 | 三套 AppSecret 取同一值（`FORUM` / `EXAM` / `SHOWCASE`） | 中 | **我方** | 违反接入说明 §10.2.3「三套凭据不得复用」，任一泄露等于三处失守。需向网关侧申请另发两套独立 AppSecret 后分别替换 |
| R-3 | `GATEWAY_TRUSTED_CIDR` 为空，任意 IP 可直连平台 | 中 | **我方** | 当前安全性完全依赖 `X-Internal-Sign` 验签单点。G-02 执行后消除，但受前端接入方式制约 |
| R-4 | 桩服务若仍在线，可能与真实平台产生混淆 | 中 | 网关侧 | G-04 消除 |
| R-5 | `/api/v1/workshop/internal/ping` 落到平台 SPA 兜底，返回 `301` 跳转而非 JSON | 低 | **我方** | 影响网关侧健康探测的判读。建议我方补一个 `/internal/ping` 探活接口 |
| R-6 | `GATEWAY_DIRECT_ACCESS=allow` 处于过渡态 | 低 | **我方** | 该值允许无签名的直连请求降级为匿名（降级的是"信任",不是"鉴权"）。**取值取决于 G-01 的实现方式**：方式 A（网关签名透传）→ 应改为 `reject`，强制所有流量经网关；方式 B（不签名透传）→ 必须保持 `allow`，否则匿名请求会被我方 403 |
| R-7 | L1 可选后，网关失去用户身份，"20 QPS/用户"限流维度部分失效 | 中 | 网关侧 | 见 G-01 连带影响。建议网关侧实施双轨限流（有令牌按用户、无令牌按 IP），或明确接受该维度降级。平台侧业务接口目前**无应用层限流** |

---

## 5. 责任矩阵

| 编号 | 事项 | 责任方 | 依据 |
| --- | --- | --- | --- |
| G-01 | `workshop-api` L1 策略改为可选 | **网关负责人** | 路由鉴权策略在 `routes.py`，我方无权限；且我方代码已具备承接能力，无需配合改动 |
| G-02 | `GATEWAY_TRUSTED_CIDR` 收敛 | **我方** | `.env` 在平台实例；需按 G-01 进度决定时机 |
| G-03 | `workshop-files` 对参数 | **双方** | 平台先实现接口，网关后确认 upstream/限流 |
| G-04 | 桩服务下线 | **网关负责人** | 需网关机 systemd 权限 |
| R-2 | 拆分三套 AppSecret | **我方发起 + 网关侧签发** | 密钥由网关侧登记，我方负责替换与回归 |
| R-5 | 补 `/internal/ping` | **我方** | 平台路由 |
| R-7 | 双轨限流 | **网关侧** | 限流策略在 `workshop-api` 路由上 |

**平台侧已完成、无需网关侧等待的项**（供确认）：

| 项 | 状态 | 证据 |
| --- | --- | --- |
| L2 出站签名 | ✅ 已验证 | 经网关 `forum-verify` 返回 `gatewayCode=40104`、`source=remote` |
| L1 入站验签 | ✅ 已验证 | 正确内部密钥 → 200；错误密钥 → 403 `SIGN_MISMATCH` |
| 匿名路径白名单 | ✅ 已实现 | `auth/login`、`auth/oauth/callback`、`auth/forum-verify`、`auth/logged-out`、`health` |
| AppSecret | ✅ 已配置 | 指纹 `ab1f05b5e877`，网关侧已接受 |
| 内部签名密钥 | ✅ 已配置 | 指纹 `572834ad9d81`，自检 `isWellFormed=true` |
| uid 契约（对外只返 `uid`/`forum_uid`） | ✅ 已上线 | 实测 `userinfo` 返回 `uid:"5"`、`platform_user_id:null` |
| CORS | ✅ 已配置 | 白名单含 `http://8.134.60.240:8080` |

---

## 6. 执行顺序

```
G-01（L1 策略改可选）
   │
   ├─→ 验证：匿名访问 /projects 返回 AUTH_REQUIRED 而非 40101
   │
   ├─→ 前端切换为经网关访问（我方 + 前端）
   │
   └─→ G-02 打开 CIDR（我方，同窗口内）
           │
           └─→ G-04 桩服务下线（网关侧，验收后）

G-03 独立，按业务需要推进
```

**顺序不能颠倒的两处**：
1. G-02 必须在「前端改经网关」之后 —— 否则直连被拒。
2. G-04 必须在「G-02 验收通过」之后 —— 保留桩服务作为回滚手段。

---

## 7. 总验收清单

| # | 检查 | 命令 | 期望 |
| --- | --- | --- | --- |
| 1 | 健康检查可达 | `curl -s $GW/api/v1/workshop/health` | 含 `"service":"workshop"` |
| 2 | 登录匿名可达 | 匿名 POST `/auth/forum-verify` | 非 `40101` |
| 3 | **业务接口不再被网关拦** | `curl -s $GW/api/v1/workshop/projects` | `code=AUTH_REQUIRED`，非 `40101` |
| 4 | 登录用户可访问业务数据 | 带 `workshop_sid` 请求 `/projects` | `200` |
| 5 | internal 白名单生效 | 签名请求 `/internal/projects/21` | `200`（已达成） |
| 6 | L1 令牌路径未破坏 | 带 Bearer 请求 `/projects` | `200` |
| 7 | 回源网段生效 | 直连平台 `/projects` | `403 NOT_TRUSTED_NETWORK`（G-02 后） |
| 8 | 桩服务已停 | `systemctl is-active jy-stub-workshop` | `inactive`（G-04 后） |

---

## 8. 回滚

| 变更 | 回滚动作 |
| --- | --- |
| G-01 | `workshop-api` 策略恢复为「L1 必须」，重跑 `routes.py` + `apisix reload` |
| G-02 | `.env` 中 `GATEWAY_TRUSTED_CIDR` 置空 → `php artisan config:clear` |
| G-04 | `systemctl enable --now jy-stub-workshop`，upstream 临时指向 `127.0.0.1:8000` |

> **切勿重跑全量 `routes.py` 重建**：手工编辑期间若丢失 `forum-root` 兜底或 `deny-api-unregistered`，未登记 API 会变成 404 而非 `403/40300`，掩盖后续问题。改前先 `cp routes.py routes.py.bak.$(date +%Y%m%d-%H%M%S)`。

---

## 附录 · 平台侧代码位置（便于双方对照排查）

| 关注点 | 位置 |
| --- | --- |
| 入站验签（L1 可信头） | `app/Http/Middleware/GatewayTrust.php`（五分支，含 `direct_access` 降级） |
| 身份解析（Cookie → 可信头 → token） | `app/Services/GatewayIdentity.php` |
| 会话（`workshop_sid`） | `app/Services/WorkshopSession.php` |
| L2 出站签名 | `app/Services/GatewaySigner.php::callCanonicalGateway()` |
| 论坛账号确认 | `app/Services/ForumAccountClient.php` |
| 平台自检 | `php artisan gateway:probe`（P1–P7 七段） |
