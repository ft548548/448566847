# 公共实体与 DooTask 字段映射

规范 §7.2 规定了 11 个公共实体的必填联动字段；§7.1 要求 JSON 使用 `camelCase`。
DooTask 的表结构与 API 沿用 `snake_case` 与自增主键（DV-13），**本期不改报文**，
跨平台对接时按本表做字段转换。

转换层落地位置建议：`app/Http/Middleware/PlatformContract.php` 之后的新增适配层，
或独立 BFF。不要在现有控制器里改名，否则会破坏前端（`resources/assets/js` 直接读字段）。

## 1 UserRef（权威：论坛）

| 规范字段                     | DooTask 来源                                    | 转换规则                                                         |
| ---------------------------- | ----------------------------------------------- | ---------------------------------------------------------------- |
| `userId:string`              | `pre_users.userid`（整型自增）                  | `String(userid)`；规范要求不可变，本项目主键天然不可变           |
| `roles:string[]`             | `pre_users.identity`（JSON 数组，可含 `admin`） | 含 `admin` → `["admin"]`；否则 `["student"]`（详见下方角色缺口） |
| `accountStatus:string`       | `pre_users.disable_at`                          | 为空 → `active`；非空 → `disabled`（无 `locked` 态）             |
| `studentNo`（可空）          | 无                                              | 恒 `null`；DooTask 不存学号                                      |
| `realName`                   | `pre_users.nickname`                            | 直传（昵称即展示名）                                             |
| `organizationId` / `classId` | 无                                              | 恒 `null`                                                        |

**角色缺口（重要）**：DooTask 只有「是否管理员」这一维，没有 `teacher` 角色，
也不区分学生与教师。规范要求的三角色语义无法在 DooTask 内部表达，
属 `deviations.md` DV-01 的一部分（身份权威在论坛，接入 OIDC 后由论坛下发）。

## 2 Project（权威：工坊）

| 规范字段                   | DooTask 来源               | 转换规则                                                                                                                          |
| -------------------------- | -------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `projectId:string`         | `pre_projects.id`          | `String(id)`                                                                                                                      |
| `title:string`             | `pre_projects.name`        | 直传                                                                                                                              |
| `status:string`            | `pre_projects.archived_at` | 为空 → `in_progress`；非空 → `closed`。**DooTask 没有 `draft/open/review/accepted/rejected` 五态**，规范状态机在 DooTask 内未实现 |
| `ownerId:string`           | `pre_projects.userid`      | `String(userid)`                                                                                                                  |
| `requiredExamIds:string[]` | 无                         | 恒 `[]`；准入校验依赖考试平台事件，本期未接（DV-11）                                                                              |

## 3 ProjectMember

| 规范字段           | DooTask 来源                     | 转换规则                                             |
| ------------------ | -------------------------------- | ---------------------------------------------------- |
| `projectId:string` | `pre_project_users.project_id`   | `String()`                                           |
| `userId:string`    | `pre_project_users.userid`       | `String()`                                           |
| `roleCode:string`  | `pre_project_users.owner`（1/0） | `1` → `owner`；`0` → `member`（DooTask 无更细角色）  |
| `status:string`    | 记录存在即成员                   | 恒 `active`；DooTask 无 `pending/rejected/left` 三态 |

## 4 Milestone / Deliverable（规范要求，DooTask 无对应实体）

DooTask 用「任务（`pre_project_tasks`）+ 文件（`pre_files`）」表达工作项与交付物，
没有里程碑与交付物的独立模型：

| 规范实体      | 最接近的 DooTask 结构                          | 差距                                                 |
| ------------- | ---------------------------------------------- | ---------------------------------------------------- |
| `Milestone`   | `pre_project_tasks`（含 `complete_at` 完成态） | 无 `sequence`、无 `submitted/approved/rework` 状态机 |
| `Deliverable` | `pre_project_task_files` + `pre_files`         | 无 `type` 分类、无「验收」语义                       |

因此 `project.milestone_submitted` / `project.accepted` 事件本期无法产生（DV-11）。
若后续要把工坊做成规范意义上的验收闭环，需要新增两张表与状态机——那属于功能变更，
不在“功能不得改动”的范围内。

## 5 文件（规范 §10.1 六字段）

| 规范字段                   | DooTask 返回字段 | 说明                                                |
| -------------------------- | ---------------- | --------------------------------------------------- |
| `fileId:string`            | `id`             | `String(id)`                                        |
| `fileName:string`          | `name`           | 直传                                                |
| `contentType:string`       | `ext`            | DooTask 不存 MIME，只有扩展名 → 需按扩展名映射 MIME |
| `sizeBytes:number`         | `size`           | 直传（字节）                                        |
| `accessMode:string`        | `share`          | `share` 为公开分享标记 → `public`/`private`         |
| `downloadUrl:string\|null` | `url`            | 下载链接需带 token，属短时链接，不可作为永久标识    |

对应接口：`GET /api/file/lists`、`POST /api/file/add`。

## 6 Work / Feedback / Course / ExamResult

这四类实体的权威平台是广场、自学、考试，DooTask 不持有，
因此**不产生映射**，跨平台读取走各平台 API（未接入）。

## 7 时间格式

DooTask 返回 `Y-m-d H:i:s`（无时区，按 APP 时区解释），规范 §7.1 要求 RFC 3339 带时区。
转换规则：`2026-11-04 19:24:10` → `2026-11-04T19:24:10+08:00`（默认 +08:00，可通过 `APP_TIMEZONE` 调整）。
