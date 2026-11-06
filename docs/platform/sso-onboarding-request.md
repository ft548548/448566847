# 统一身份 SSO 对接要求（网关 / 身份中心侧）

> 面向对象：统一 API 网关与身份中心的运维/开发
> 关联文档：`oauth-migration-plan.md`（平台侧迁移方案）、`gateway-change-request.md`（网关变更单 CR-20261106-01）
> 编制时间：2026-11-06

---

## 0. 一句话

平台侧的 OAuth 客户端代码早已完整实现，**现在只差对方交付三样东西**。三样齐了，切换是改一行环境变量的事；缺任何一样，链路都跑不通。

| 编号 | 需要交付 | 当前状态 | 谁负责 |
|---|---|---|---|
| D-1 | 五条 OAuth/OIDC 端点注册为**真实 API 路由** | 全部落到 Dashboard 兜底页 | 网关 |
| D-2 | 注册客户端 `jy-workshop-web`，签发 `client_secret`，登记回调白名单 | 未注册 | 身份中心 |
| D-3 | `userinfo` 必须返回**十进制论坛 uid** | 未知（未拿到样例） | 身份中心 |

---

## 1. 实测证据（2026-11-06 15:55）

对 `http://8.138.246.181` 逐条探测：

```
/.well-known/openid-configuration   HTTP 200  6666B  text/html;charset=utf-8
/oauth/authorize                    HTTP 200  6666B  text/html;charset=utf-8
/oauth/token                        HTTP 200  6666B  text/html;charset=utf-8
/oauth/userinfo                     HTTP 200  6666B  text/html;charset=utf-8
/protocol/logout                    HTTP 200  6666B  text/html;charset=utf-8
```

五条路径**返回完全相同的 6666 字节 HTML**，正文是「You need to enable JavaScript to run this app.」并带 Apache License 注释头。

**这是 APISIX Dashboard 的 SPA 首页**（history 模式通配兜底），不是接口。也就是说：这五条路径**没有注册为 API 路由**，被兜底页吃掉了。

> 注意这个现象的欺骗性：HTTP 状态码是 200。任何只看状态码的健康检查都会把它们判为"正常"。

---

## 2. D-1 端点要求

### 2.1 五条路径与最低要求

| 路径 | 方法 | 最低要求 |
|---|---|---|
| `/.well-known/openid-configuration` | GET | 返回 JSON，至少含 `issuer`、`authorization_endpoint`、`token_endpoint`；建议附 `userinfo_endpoint`、`end_session_endpoint` |
| `/oauth/authorize` | GET | 支持 `response_type=code`、`client_id`、`redirect_uri`、`state`、`code_challenge` + `code_challenge_method=S256`、`scope`。未登录时呈现登录页（HTML 即可） |
| `/oauth/token` | POST | `application/x-www-form-urlencoded`；支持 `grant_type=authorization_code` + `code` + `code_verifier` + `client_id` + `client_secret`；成功返回 `access_token`、`token_type`、`expires_in`，建议附 `refresh_token`、`id_token` |
| `/oauth/userinfo` | GET | `Authorization: Bearer <access_token>`；**必须含十进制论坛 uid**（见 D-3） |
| `/protocol/logout` | GET | 支持 `client_id`、`post_logout_redirect_uri`；清会话后 302 回跳 |

### 2.2 验收判据（这一条最关键）

**不能用"HTTP 200"作为验收标准。** 正确判据：

| 端点 | 未登录/无参探测时应返回 | 说明 |
|---|---|---|
| discovery | `application/json`，含 `authorization_endpoint` | — |
| authorize | HTML 登录页，或 400 JSON | 都可以 |
| token | **400 + `{"error":"..."}`** | 标准 OAuth 错误体；返回 200 反而说明实现不对 |
| userinfo | **401 + `{"error":"..."}`** | 无令牌必须被拒 |
| logout | 302 或 HTML | — |

只要还返回那 6666 字节的 HTML，就是**没注册**，不要按"已上线"处理。

---

## 3. D-2 客户端注册要求

### 3.1 需要签发的凭据

```
client_id     = jy-workshop-web        （已在平台配置中占位）
client_secret = <由身份中心生成并下发>
```

### 3.2 回调白名单（必须逐字符一致）

当前生产：

```
http://8.134.60.240:8080/api/v1/workshop/auth/oauth/callback
```

若后续上 HTTPS + 域名，请同时登记新地址，并**保留旧地址直到切换完成**：

```
https://<平台域名>/api/v1/workshop/auth/oauth/callback
```

### 3.3 一条硬提醒：secret 不能复用网关 L2 凭据

平台已持有网关 L2 的服务间签名凭据 `AppKey=jy-workshop-svc` / `AppSecret=***`。这组是**服务间信任域**的凭据。

OAuth 的 `client_secret` 属于**客户端信任域**，按标准 OAuth 2.0 必须另发。若复用，表现是：

> 授权页能正常打开、用户能登录、回调回来换码时返回 `invalid_client`，前端表现为"登录失败"。

排查时极易误判成网络问题或代码 bug。**平台侧已在 `sso:probe` 内置了同值检测，会在切换前拦下。**

若身份中心坚持认为二者可以复用，请书面确认——但请先看这条：复用意味着任何拿到 L2 凭据的服务都能冒充这个客户端换码。

---

## 4. D-3 `userinfo` 字段要求（唯一可能推翻方案的项）

平台的身份契约（R1/R2）规定：**本地主键直接等于论坛 `user.id` 的十进制原值**，不生成替代标识、不建影子账号。

因此 `userinfo` 响应里**必须存在一个十进制的论坛 uid 字段**。任取其一即可：

```json
{
  "user_id": 5,
  "userId": 5,
  "forum_uid": "5",
  "username": "zhangsan",
  "nickname": "张三",
  "roles": ["student"]
}
```

### 关键分歧点

平台文档《统一身份与网关接入说明》V3.0 §3.2 把 `sub` 定义为**不透明字符串**（如 `usr_01J8ZQ7M2K`）。如果 `userinfo` **只有**这种不透明 `sub`、没有任何数字 id，那么：

- 平台会拒绝建号（返回 `VALIDATION_ERROR`），这是刻意设计——不能用随机串当身份；
- uid 契约需要重新裁决，涉及绑定表重建与历史数据回填。

**所以请先给一样东西**：任取一个已知 uid 的账号（例如 uid 5），走完一次真实授权，把 `GET /oauth/userinfo` 的**原始响应**贴出来。这一步做完，后面才不会返工。

---

## 5. 交付后如何验证（平台侧自检）

平台已内置就绪自检命令，**不接触真实凭据也能判定端点是否注册**：

```bash
php artisan sso:probe --base=http://8.138.246.181
```

输出示例（当前真实结果）：

```
+-----------+--------+---------------------------------------------------+
| 协议端点  | 判定   | 说明                                              |
+-----------+--------+---------------------------------------------------+
| discovery | 未注册 | 返回 APISIX Dashboard 兜底页：该路径没有 API 路由 |
| authorize | 未注册 | 返回 APISIX Dashboard 兜底页                      |
| token     | 未注册 | 返回 APISIX Dashboard 兜底页                      |
| userinfo  | 未注册 | 返回 APISIX Dashboard 兜底页                      |
| logout    | 未注册 | 返回 APISIX Dashboard 兜底页                      |
+-----------+--------+---------------------------------------------------+

结论：BLOCKED —— 存在 6 项阻塞
```

端点交付后，同一条命令应输出 `结论：READY`，退出码 0。CI 可用：

```bash
php artisan sso:probe --json   # 输出 {"ready":true|false,"blockers":[...]}
```

---

## 6. 交付清单（可直接勾选）

- [ ] 五条路径登记为 API 路由，不再是 Dashboard 兜底页
- [ ] `authorize` 支持 PKCE（S256）与 `state` 透传
- [ ] `token` 支持 `authorization_code` + `code_verifier`，错误返回标准 `error` 体
- [ ] `userinfo` 返回十进制论坛 uid，并给出一个真实样例
- [ ] `client_id=jy-workshop-web` 已注册，`client_secret` 已下发（与 L2 AppSecret 不同值）
- [ ] 回调白名单登记 `http://8.134.60.240:8080/api/v1/workshop/auth/oauth/callback`
- [ ] `php artisan sso:probe` 输出 READY

---

## 7. 平台侧已完成、不需要对方配合的部分

| 项 | 状态 |
|---|---|
| OAuth 客户端（PKCE、换码、userinfo、discovery 降级） | 已实现 |
| 回调处理：识别 `?error=`、区分 503/401、浏览器与脚本分通道 | 已实现（2026-11-06 P1） |
| `state` 一次性消费、会话固定防护、旧 sid 立刻删除 | 已实现 |
| mock 仅限 local/testing，生产 fail closed | 已实现 |
| 就绪自检命令 `sso:probe` | 已实现（本轮） |

> 另需注意：**OAuth 只解决"身份怎么来"**。用户拿到会话后调用业务接口仍要过网关 L1 令牌策略（`gateway-actions-summary.md` 的 G-01）。两件事必须都解决，不要指望 OAuth 顺带解决 `40101`。
