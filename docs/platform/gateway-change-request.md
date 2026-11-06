# 网关路由配对变更单 · workshop 平台

> ⚠️ **本文件为 v1.0 历史记录，状态已过期。**
> 2026-11-06 14:15 实测确认：本文 CR-01 / CR-02 / CR-03 **三项均已完成**，CR-04 仍待定。
> 最新状态与新增阻塞项（G-01）见 **[`gateway-actions-summary.md`](./gateway-actions-summary.md)（v2.0）**，
> 请以该文件为准转交网关侧。本文件保留用于追溯当时的判定依据与实测数据。

| 项 | 内容 |
| --- | --- |
| 变更单号 | CR-20261106-01 |
| 提出方 | 警务实训平台（platform = `workshop`） |
| 接收方 | 统一 API 网关运维（APISIX @ `8.138.246.181`） |
| 版本 / 日期 | v1.0 / 2026-11-06 |
| 关联基线 | 《警院WeChat 五平台统一 API 网关部署报告》§四 路由基线（19 条） |
| 平台实例 | `http://8.134.60.240:8080`（已部署，公网直连可达） |
| 平台 AppKey | `jy-workshop-svc`（AppSecret 已下发，见 §1 已验证证据） |

---

## 0. 结论摘要

**路径已配对，鉴权策略与上游未配对。** 三条 `workshop-*` 路由在网关上均可匹配（返回 401 而非 404），但：

- 本平台自己的 `internal` 路由**未把 `jy-workshop-svc` 写进 L2 白名单** → `401 / 40108`
- `workshop` 缺少**匿名入口路由**，登录接口被 L1 拦住 → `401 / 40101`（对照：forum、learning 的登录入口均为匿名放行）
- 三条路由的 upstream 仍指向**网关机本地的桩服务** `127.0.0.1:8000` → 修好鉴权也到不了本平台

共 4 项变更，其中 3 项在网关侧、1 项在本平台侧（已完成，见 CR-04）。

| 编号 | 责任方 | 变更内容 | 优先级 | 关联偏离 |
| --- | --- | --- | --- | --- |
| CR-01 | 网关侧 | `workshop-internal` 的 L2 白名单追加 `jy-workshop-svc` | P0 | DV-24 / DV-26 |
| CR-02 | 网关侧 | 新增 `workshop-auth` 匿名路由（`/auth/*`、`/health`） | P0 | 新增 DV-30 |
| CR-03 | 网关侧 | 三条 `workshop-*` 的 upstream 改为 `8.134.60.240:8080` | P0 | 部署报告遗留 §六-1 |
| CR-04 | 本平台 | 回退 `GATEWAY_TRUSTED_CIDR`，避免直连被网段策略拦截 | P0 | 新增 DV-31（已完成） |

---

## 1. 判定依据

网关的每个数字错误码对应链路中**不同的拦截点**，因此可以用响应差异精确定位问题出在哪一环，不需要读配置、也不需要猜测。

| 错误码 | HTTP | 拦截点 | 含义 |
| --- | --- | --- | --- |
| `40101` | 401 | `jy-l1-token` | 缺 L1 令牌 / 令牌无效 |
| `40105` | 401 | `jy-l2-sign` 五头校验 | 签名头不全（**此时根本还没查 AppKey**） |
| `40106` | 401 | `jy-l2-sign` 时间窗 | 时间戳超 ±300 s |
| `40107` | 401 | `jy-l2-sign` nonce | 重放 |
| `40108` | 401 | `jy-l2-sign` AppKey 白名单 | Key 不在本条路由的授权清单内 |
| `40300` | 403 | 默认拒绝路由 | API 未登记 |
| `50301` | 503 | `jy-l1-token` | 自省服务故障（fail-closed） |

一个容易踩的坑：**不带签名的探测无法判断 AppKey 是否登记**。`internal` 路由在第五个签名头缺失时就返回 `40105`，走不到 AppKey 查询。必须用真实 AppSecret 签一次才能区分。

### 1.1 实测：同一把 Key，六条路由对照

执行环境：生产实例 `8.134.60.240:8080`，读取 `.env` 的真实 `jy-workshop-svc` AppSecret，按七段规范串签名后直连网关。

```
PATH                                         HTTP  CODE      MESSAGE
/api/v1/workshop/internal/ping               401   40108     AppKey 不存在或已停用
/api/v1/workshop/internal/projects/1         401   40108     AppKey 不存在或已停用
/api/v1/exam/internal/ping                   404   NOT_FOUND 未定义的契约接口：GET /api/v1/exam/internal/ping
/api/v1/learning/internal/ping               200   -         {"platform": "learning", "method": "GET", "path": ...
/api/v1/plaza/internal/ping                  200   -         {"platform": "plaza", "method": "GET", "path": ...
/api/v1/forum/internal/ping                  401   40108     AppKey 不存在或已停用
```

**三个关键推论：**

1. `learning-internal` 与 `plaza-internal` 返回 **200** —— 同一把 AppSecret 在别的路由上被接受，**排除了"密钥错误""AppKey 不存在"两种可能**。`40108` 的提示文案"AppKey 不存在或已停用"在这里是误导的。
2. `exam-internal` 返回 **404 且文案来自桩服务** —— 说明 L2 验签已通过、请求已抵达上游，只是桩服务按契约白名单拒绝了该路径。这从另一侧证明签名有效。
3. `workshop-internal` 返回 `40108`，而 `learning/plaza/exam` 三条对本 Key 均放行 —— 说明**白名单是按路由各自维护的**，与本平台的密钥无关。

> `forum-internal` 同样 40108，但本平台不调 forum 的 internal 接口，无需处理。

### 1.2 实测：匿名可访问性对照

| 路径 | HTTP | 结果 |
| --- | --- | --- |
| `/api/v1/forum/ping` | 200 | 匿名放行（`forum-api` 策略为「L1 允许匿名」） |
| `/api/v1/learning/auth/login` | 200 | 匿名放行（`learning-auth` 独立匿名路由） |
| `/api/v1/plaza/works/public` | 200 | 匿名放行（`plaza-public`） |
| `/api/v1/workshop/health` | **401 / 40101** | 被 L1 拦下 |
| `/api/v1/workshop/auth/forum-verify` | **401 / 40101** | 被 L1 拦下 |

`health` 与登录接口在语义上都属于匿名可达资源，只有 workshop 一律要求令牌 —— 这是 CR-02 的依据。

### 1.3 上游仍是桩服务的证据

`learning` / `plaza` 的 200 响应体是 `{"platform":"learning","method":"GET","path":...}` —— 这是桩服务 `stub_upstream.py` 的 echo 格式，真实业务接口不会这样回话；`exam` 的 404 文案「未定义的契约接口」也是桩按契约白名单生成的拒绝。与部署报告 §四 基线（`workshop-*` upstream = `127.0.0.1:8000`）一致。

---

## 2. 变更项

### CR-01 · `workshop-internal` 的 L2 白名单缺本平台 AppKey

| 字段 | 内容 |
| --- | --- |
| 现状 | 路由 `workshop-internal`：`/api/v1/workshop/internal/*` → `127.0.0.1:8000`，策略 `L2 + 200 QPS/AppKey`；真实签名调用返回 `401 / 40108` |
| 目标 | 在该路由的 L2 客户端清单中放行 `jy-workshop-svc` |
| 位置 | `/root/gw-deploy/configs/routes.py` → `workshop-internal` 条目的 `l2([...])` 调用（与 `learning-internal`、`plaza-internal` 同形） |
| 操作 | 追加 `"jy-workshop-svc"` → `cd /root/gw-deploy/configs && python3 routes.py` → `apisix test && apisix reload` |
| 影响面 | 仅扩大本平台出站权限；不影响其他平台，不涉及密钥下发（密钥已在两端就位） |
| 回退 | 从清单中移除该项，重跑 `routes.py` |

**验收**（在平台实例上执行，需先确保 CR-03 的 upstream 已切）：

```bash
# 在平台实例 /www/wwwroot/dootask 下执行
php artisan gateway:probe
# P4「L2 服务签名」：由 FAIL(40108) 变为 PASS 或 404 —— 只要不再是 40108 即表示白名单已生效。
# 404 也算通过：说明请求已穿过网关抵达上游，只是该探测路径在平台上不存在。
```

**为什么要这一条**：本平台调 exam 做准入回源、调 plaza 推作品，都走 `internal` 路由。缺这项时两条链路会被网关拦在门外，表现是准入降级为 `pending`、作品发布失败。

---

### CR-02 · workshop 缺匿名入口路由，登录存在死锁

| 字段 | 内容 |
| --- | --- |
| 现状 | `workshop-api`：`/api/v1/workshop/*`，策略 `L1 + 20 QPS/用户 + 熔断`；`health` 与 `auth/*` 均被 L1 拦截（`401 / 40101`） |
| 目标 | 新增路由 `workshop-auth`，策略对齐 `learning-auth`：匿名 + 10 次/分/IP，路径覆盖 `/api/v1/workshop/auth/*` 与 `/api/v1/workshop/health` |
| 位置 | `routes.py` 新增一条，`priority` 必须**高于** `workshop-api`（APISIX 按 priority 降序匹配，同前缀取高者） |
| 注意 | `POST /api/v1/workshop/auth/forum-verify` 携带 JSON body，需允许 body 透传（继承 `workshop-api` 的体积/超时设置即可，2 MB / M） |
| 回退 | 删除该路由并重跑 `routes.py` |

**验收**：

```bash
GW=http://8.138.246.181

# 1) 健康检查匿名可达，且响应体是平台信封（不是网关错误 JSON）
curl -s $GW/api/v1/workshop/health | head -c 200
# 期望：{"ret":1,"msg":"success","data":{"status":"ok","service":"workshop",...}}

# 2) 登录接口不再是 40101
curl -s -o /dev/null -w '%{http_code}\n' -X POST $GW/api/v1/workshop/auth/forum-verify \
  -H 'Content-Type: application/json' \
  -d '{"login":"probe_not_exist_001","pass":"x","login_type":"auto"}'
# 期望：401 但 body 的 code 是平台业务码（INVALID_CREDENTIALS），而不是 40101
```

**为什么必须先做这一条**：一旦前端改为经网关访问，登录接口要求令牌、而用户尚未登录不可能持有令牌，形成**死锁**。目前没暴露，是因为前端直连 `8.134.60.240:8080`，绕过了网关。CR-03 一执行，这个死锁就会显形，所以 CR-02 必须与 CR-03 同批、且先验收。

**安全提示**：匿名不等于无防护。该路由仍是 10 次/分/IP 限流；平台侧对 `forum-verify` 另有 5 次失败节流与账号锁定逻辑，两层叠加。

---

### CR-03 · 三条路由的 upstream 仍指向桩服务

| 字段 | 内容 |
| --- | --- |
| 现状 | `workshop-api` / `workshop-internal` / `workshop-files` 的 upstream 均为 `127.0.0.1:8000`（= `jy-stub-workshop`） |
| 目标 | 三条全部改为 `8.134.60.240:8080` |
| 位置 | `routes.py` 对应三条的 `upstream` 节点 |
| 附带 | 切换并验收通过后：`systemctl disable --now jy-stub-workshop`（保留 unit 文件便于回滚） |
| 回退 | upstream 改回 `127.0.0.1:8000`，`systemctl enable --now jy-stub-workshop` |

**验收**：

```bash
curl -s http://8.138.246.181/api/v1/workshop/health | head -c 200
# 期望 {"ret":1,...,"service":"workshop"}；若为 {"platform":"workshop","method":...} 说明仍打在桩上
```

**平台侧配套（必须同批）**：upstream 切换后，网关回源请求的源 IP 为 `8.138.246.181`，平台 `GatewayTrust` 会校验来源网段。当前该值为**空**（见 CR-04），切换完成后本平台将于同一窗口内执行：

```
GATEWAY_TRUSTED_CIDR=8.138.246.181/32
```

**若该值未配置**，回源会返回 `403 / NOT_TRUSTED_NETWORK`，症状看起来像"密钥不对"，实际是网段未放行 —— 排查时请注意区分（平台侧 `reason` 字段会明确给出 `NOT_TRUSTED_NETWORK`）。反之，若在 upstream 切换**之前**就把该值设为网关 IP，浏览器直连会被一律拒绝（CR-04 即此情形），所以顺序不能颠倒。

**未纳入本次变更**：`workshop-files`（`/api/v1/workshop/files*`，`L1 + 5 QPS/用户`，50 MB）。文件下载由已登录用户发起、携带用户令牌，本次仅改 upstream，鉴权策略维持不变。

---

### CR-04 · 本平台侧：`trusted_cidr` 导致直连业务接口被拒【已完成】

| 字段 | 内容 |
| --- | --- |
| 现象 | 部署后远端 `.env` 的 `GATEWAY_TRUSTED_CIDR=8.138.246.181/32`，而网关尚未回源到本平台 → 浏览器直连访问非公开接口一律 `403 NOT_TRUSTED_NETWORK` |
| 实测 | `curl http://8.134.60.240:8080/api/v1/workshop/projects` → `403`，`data.reason=NOT_TRUSTED_NETWORK`；`/health` 因在公开路径内 → `200` |
| 处置 | 网关 upstream 切换**之前**，该值保持为空（`fromTrustedNetwork()` 在 CIDR 为空时返回 true）；切换完成后立即设为 `8.138.246.181/32` |
| 状态 | 已于 2026-11-06 回退为空并验证直连恢复 |

说明：清空 CIDR **不等于**放弃验签。可信身份头仍受 `X-Internal-Sign`（HMAC-SHA256 + ±300 s）保护，伪造 `X-User-Id` 会被拒并降级为匿名。CIDR 与验签是两道独立防线，前者管"谁能敲这个门"，后者管"这话是不是网关说的"。

---

## 3. 执行顺序与依赖

```
CR-02（匿名路由）─┐
                  ├─→ CR-03（切 upstream）─→ CR-04 打开 CIDR ─→ CR-01 验收 ─→ 全链路联调
CR-01（白名单）───┘
```

- CR-02、CR-01 都是路由配置，可一次 `routes.py` 提交、一次 `reload`
- **CR-03 必须在 CR-02 之后验收**，否则切完 upstream 登录会立刻 40101
- CR-04 与 CR-03 同一时间窗执行，否则回源被 403
- 每步之间间隔 ≥ 30 s，便于从 `access.log` 区分是哪一步引起的异常

---

## 4. 总验收清单

| # | 检查 | 命令 | 期望 |
| --- | --- | --- | --- |
| 1 | 路由可达 | `curl -o /dev/null -w '%{http_code}' http://8.138.246.181/api/v1/workshop/health` | `200` |
| 2 | 响应来自本平台 | `curl -s http://8.138.246.181/api/v1/workshop/health` 的输出含 `"service":"workshop"` | 命中（桩服务回的是 `{"platform":"workshop",...}`） |
| 3 | 匿名登录不死锁 | 匿名 POST `/workshop/auth/forum-verify` | 非 `40101` |
| 4 | internal 白名单生效 | `php artisan gateway:probe` 的 P4 | 非 `40108` |
| 5 | 出站签名仍有效 | `gateway:probe` 的 P6b | `PASS` |
| 6 | 可信头验签 | `curl .../workshop/auth/userinfo`（无令牌） | `401` + 平台 `AUTH_REQUIRED`，**非** `403 NOT_TRUSTED_NETWORK` |
| 7 | 桩服务已下线 | `systemctl is-active jy-stub-workshop` | `inactive` |
| 8 | 网关侧无 5xx | `tail -50 /usr/local/apisix/logs/error.log` | 无 upstream connect 错误 |

---

## 5. 回滚

单条变更均可独立回退；整体回退如下：

```bash
cd /root/gw-deploy/configs
cp routes.py routes.py.bak.$(date +%Y%m%d-%H%M%S)
# 恢复 workshop 三条的 upstream 与 l2 清单、删除 workshop-auth 路由后
python3 routes.py && apisix test && apisix reload
systemctl enable --now jy-stub-workshop
```

平台侧同步回退：`.env` 的 `GATEWAY_TRUSTED_CIDR` 置空 → `php artisan config:clear`。

**切勿重跑 `setup-site.sh` / 覆盖 `routes.py` 全量重建**：`routes.py` 是幂等 PUT 且会清理多余路由，但手工编辑期间若丢失 `forum-root` 兜底或 `deny-api-unregistered`，会导致未登记 API 直接 404 而非 `403 / 40300`，掩盖后续问题。

---

## 6. 本平台侧已就绪项（供网关侧确认无需等待）

| 项 | 状态 | 证据 |
| --- | --- | --- |
| 平台部署 | 已上线 | `http://8.134.60.240:8080`，`/health` 返回 `ret:1` |
| L2 出站签名 | 已验证 | `gateway:probe` P6b = PASS，`gatewayCode=40104`（穿过网关到达论坛业务层） |
| AppSecret | 已配置 | `jy-workshop-svc`，账号确认链路实调通过 |
| 入站内部签名密钥 | 已配置并自检 | 指纹 `572834ad9d81`，`internalSecretValid=true` |
| 匿名路径白名单 | 已实现 | `auth/login`、`auth/oauth/callback`、`auth/forum-verify`、`auth/logged-out`、`health` |
| CORS | 已配置 | 白名单含 `http://8.134.60.240:8080`，非法 Origin 写回 `null` |
| 回源网段 | 待 CR-03 时打开 | 当前为空，切换后置 `8.138.246.181/32` |

---

## 附录 A · 实测环境与时间

| 项 | 值 |
| --- | --- |
| 探测时间 | 2026-11-06 11:2x（GMT+8） |
| 探测发起方 | 平台实例 `8.134.60.240:8080`（读取其 `.env` 密钥） |
| 探测目标 | `http://8.138.246.181` |
| 签名规范串 | 七段：`appKey\nts\nnonce\nMETHOD\npath\nurlencode(sorted(query))\nsha256(body)` |
| 平台侧自检 | `php artisan gateway:probe`（P1 可达 / P2 登记 / P3 默认拒绝 / P4 L2 / P5 L1 / P6 账号确认 / P7 入站密钥） |

## 附录 B · 平台侧对应代码位置（便于双方对照）

| 关注点 | 平台侧位置 |
| --- | --- |
| L2 出站签名 | `app/Services/GatewaySigner.php::callCanonicalGateway()` |
| 入站验签 | `app/Http/Middleware/GatewayTrust.php` |
| 账号确认（网关回环） | `app/Services/ForumAccountClient.php`，路径 `/answer/api/v1/account/verify` |
| 准入回源 | `app/Services/ExamClient.php`，路径 `/api/v1/exam/internal/*` |
| 作品推送 | `app/Services/ShowcaseClient.php`，路径 `/api/v1/plaza/internal/*` |

---

**联系人**：警务实训平台开发方
**期望完成窗口**：CR-01 / CR-02 可与 CR-03 同批，一次维护窗口内完成
