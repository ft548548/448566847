# 日志字段说明

规范 §10.5：日志不得记录密码、Token、完整敏感身份信息、考试答案或私有文件内容；
跨服务用 `requestId` 关联单次请求。

## 1 访问日志（新增）

**位置**：`storage/logs/platform-access.log`，一行一个 JSON 对象，追加写入（`FILE_APPEND | LOCK_EX`）。

| 字段         | 类型    | 说明                                                        |
| ------------ | ------- | ----------------------------------------------------------- |
| `ts`         | string  | UTC 时间，RFC 3339（`2026-11-04T11:39:52Z`）                |
| `requestId`  | string  | 与响应头 `X-Request-Id`、响应体 `requestId` 完全一致        |
| `appCode`    | string  | 请求头 `X-App-Code` 的值；未提供时为 `dootask`              |
| `method`     | string  | HTTP 方法                                                   |
| `path`       | string  | 以 `/` 开头的请求路径（不含 query，避免把敏感参数写进日志） |
| `status`     | integer | HTTP 状态码                                                 |
| `durationMs` | integer | 服务端处理耗时（毫秒）                                      |
| `ip`         | string  | 客户端 IP（经 `TrustProxies` 解析后的来源）                 |

示例：

```json
{
  "ts": "2026-11-04T11:39:52Z",
  "requestId": "trace-abc-0001",
  "appCode": "dootask",
  "method": "GET",
  "path": "/api/system/setting",
  "status": 200,
  "durationMs": 4,
  "ip": "127.0.0.1"
}
```

**刻意不记录**：请求体、响应体、`token`、Cookie、密码、文件名与文件内容。
需要按用户追查时，用业务侧的 `pre_project_logs.userid` 与访问日志的时间/IP 关联。

## 2 业务日志（既有）

`pre_project_logs`：`project_id`、`column_id`、`task_id`、`userid`、`detail`、`record`、`created_at`。
用于项目内操作历史展示。缺 `requestId` 与执行结果字段（规范 §12 要求），见 `permissions.md` §4。

## 3 应用日志（既有）

Laravel 默认通道，位置 `storage/logs/laravel.log`（`LOG_CHANNEL=stack`）。
生产环境应改为按天切分并将级别调到 `warning` 以上，避免 `debug` 写入敏感上下文。

## 4 排查流程

1. 上游报错 → 拿到它手里的 `X-Request-Id`（或从浏览器网络面板响应头取）；
2. `grep <requestId> storage/logs/platform-access.log` → 得到方法、路径、状态、耗时、来源；
3. 若需追业务原因 → 按同一时间窗口与 `ip`/`userid` 查 `pre_project_logs` 或 `laravel.log`；
4. `status=400` 且 `appCode` 异常 → 检查是否发送了非法 `X-App-Code`（只接受五平台编码）。

## 5 保留与轮转

当前无轮转。建议：按天切分、保留 30 天后归档；访问日志量约为业务请求数 × 1 行，
预估每天百万行以内时单文件可接受。**上线前必须配置轮转**，否则磁盘会被写满。
