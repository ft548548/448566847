# 权限点、角色与审计

规范 §12 要求：权限点格式 `{resource}:{action}`；服务端每个写接口都校验
「身份有效 + 角色/权限点 + 资源归属 + 状态允许」；前端隐藏按钮不算授权。

## 1 现状：没有权限点引擎，只有三类判定

DooTask 没有 `permission_points` 式的授权表，实际授权由三处逻辑组成：

| 判定方式       | 实现位置                                                                                                     | 语义                                                          |
| -------------- | ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------- |
| 数据范围作用域 | `Project::authData()` / `ProjectTask::authData()`                                                            | 查询自动附加「我是成员」条件，越权对象查不到（表现为 404/空） |
| 显式归属判断   | 各控制器内的 owner 校验，如 `RecruitmentController` 的「只有发布者可以执行此操作」、`ProjectTask::isOwner()` | 高敏操作要求调用者就是资源所有者                              |
| 管理员标志     | `User::isAdmin()`（读 `pre_users.identity` 含 `admin`）                                                      | 系统级配置与跨项目操作                                        |

**结论**：规范要求的 `{resource}:{action}` 权限点在本项目**未实现**，
属 `deviations.md` DV-01 的一部分。下表把规范权限点映射到实际生效的服务端判定，
供对接方核对「这个动作到底会不会被拦」。

## 2 权限点 → 实际服务端判定

| 权限点                 | 是否需要登录 | 实际判定位置                               | 状态               |
| ---------------------- | ------------ | ------------------------------------------ | ------------------ |
| `project:read`         | 是           | `Project::authData()` 作用域               | 已实现（数据范围） |
| `project:create`       | 是           | `ProjectController::add()`                 | 已实现（登录即可） |
| `project:update`       | 是           | 项目归属：仅成员/所有者                    | 已实现（归属校验） |
| `project:invite`       | 是           | `ProjectController::invite()` + 项目邀请表 | 已实现             |
| `project:transfer`     | 是           | 仅项目所有者                               | 已实现（归属校验） |
| `project:archive`      | 是           | 仅项目所有者                               | 已实现（归属校验） |
| `project:audit`        | 是           | **无此能力**（DooTask 无验收流程）         | **未实现**         |
| `task:read`            | 是           | `ProjectTask::authData()`                  | 已实现（数据范围） |
| `task:create`          | 是           | 项目成员                                   | 已实现             |
| `task:update`          | 是           | 项目成员/负责人，`ProjectTask::isOwner()`  | 已实现             |
| `recruitment:read`     | 是           | `RecruitmentController::lists()`           | 已实现             |
| `recruitment:publish`  | 是           | 仅项目成员可发布该项目招募                 | 已实现（归属校验） |
| `recruitment:apply`    | 是           | 登录用户；重复报名被唯一索引拦截           | 已实现             |
| `recruitment:review`   | 是           | 仅招募发布者                               | 已实现（归属校验） |
| `recruitment:close`    | 是           | 仅发布者，且受状态机约束                   | 已实现             |
| `file:read`            | 是           | 文件所属项目的数据范围                     | 已实现             |
| `file:upload`          | 是           | 项目成员                                   | 已实现             |
| `system:setting:read`  | 否           | `SystemController::setting()` 匿名可读     | 已实现（设计如此） |
| `system:setting:write` | 是           | `User::isAdmin()`                          | 已实现（管理员）   |

状态机校验：招募状态流转由 `ProjectRecruitment` 的状态常量
（`recruiting` / `full` / `in_progress` / `completed` / `closed`）与控制器内
显式校验共同约束，非法流转返回 `ret=-1`（见 DV-08）。

## 3 角色映射

| 规范角色  | DooTask 对应                    | 说明                                           |
| --------- | ------------------------------- | ---------------------------------------------- |
| `student` | 普通登录用户                    | 无显式标记；`identity` 不含 `admin` 即视为学生 |
| `teacher` | **无**                          | DooTask 不区分教师，教师操作与普通成员相同     |
| `admin`   | `pre_users.identity` 含 `admin` | 系统管理员                                     |

规范 §5.1 要求角色由论坛身份中心下发，本项目不得自行推断——接入 OIDC 前，
上述映射只是**投影**，不构成权威来源（DV-01）。

## 4 审计与日志

| 审计能力          | 现状                                                                                                          | 位置                               |
| ----------------- | ------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| 业务操作留痕      | 项目/任务变更写入 `pre_project_logs`（含 `project_id`、`userid`、`detail`、`created_at`）                     | `pre_project_logs`                 |
| 请求级访问日志    | 新增：每次 `/api/**` 请求一行 JSON，含 `requestId`、`appCode`、`method`、`path`、`status`、`durationMs`、`ip` | `storage/logs/platform-access.log` |
| 按 requestId 追查 | 支持：响应头/响应体 `requestId` 与访问日志一一对应                                                            | 同上                               |
| 审计字段完整性    | `pre_project_logs` **缺** `requestId`/`result`（规范 §12 要求）                                               | 缺口见 DV-11 表格说明              |

规范 §12 要求的审计字段为：`actorId`、动作、资源类型/ID、结果、时间、`requestId`。
DooTask 的业务日志满足前五项的前四项（有 `userid`/动作/资源/时间），
**缺结果与 requestId**。补齐需改写入逻辑即改功能，故登记为偏离；过渡方案是
「业务日志 + 访问日志按时间与用户关联」。

## 5 不得做的事

- 不得在前端以「隐藏按钮」作为授权手段：所有拦截均在服务端；
- 不得在日志中写入 `token`、密码、完整请求体（`PlatformContract` 中间件只记元信息）；
- 不得新增 `teacher` 角色或据学号推断角色（规范 §5.1 明确禁止）。
