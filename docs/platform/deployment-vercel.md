# DooTask 部署到 Vercel：配置、环境变量与验证

> 适用版本：DooTask 0.13.0 / Laravel 8.48 / PHP 8.0.30
> 本文对应仓库中新增的 4 个部署文件：`vercel.json`、`api/index.php`、`api/php.ini`、`.vercelignore`。
> 相关偏离登记见 [`deviations.md`](./deviations.md)，接口契约见 [`openapi.yaml`](./openapi.yaml)。

---

## 0. 先给结论：能部署，但不是"完整部署"

DooTask 是**常驻进程形态**的 Laravel 应用，Vercel 是**函数形态**平台。二者不是"配置差异"，而是执行模型的根本差异。按现有代码，部署到 Vercel 后能跑起来，但下列 7 项能力会失效：

| 能力                                       | Vercel 上的结果                    | 代码依据                                                                                                                                 |
| ------------------------------------------ | ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| 文件上传 / 附件下载                        | **不可用**，接口 500               | 上传走 `$file->move(public_path())` 与 `file_put_contents(public_path())`，全项目 25 处 `public_path()`；Vercel 文件系统除 `/tmp` 外只读 |
| 超过 4MB 的文件                            | **413 FUNCTION_PAYLOAD_TOO_LARGE** | Vercel 函数请求体上限 4.5MB；而容器版 nginx 配的是 `client_max_body_size 1024M`                                                          |
| 实时消息（WebSocket）                      | **不可用**                         | 依赖 `php bin/laravels start -i` 常驻 Swoole 进程；函数实例会被回收，无法维持长连接                                                      |
| 异步任务（`Task::deliver`）                | **相关接口 500**                   | 17 处调用 `Hhxsv5\LaravelS\Swoole\Task\Task`，该类在 `require-dev`，且必须有 Swoole Server 实例                                          |
| 在线文档（OnlyOffice / drawio / fileview） | **不可用**                         | 三个依赖是常驻 sidecar 容器，由容器版 nginx 反向代理                                                                                     |
| 定时任务（自动归档、临时文件清理）         | **不可用**                         | `docker/crontab/crontab.conf` 常驻 crontab；Vercel Cron 只能 HTTP 触发且 Hobby 每天一次                                                  |
| 会话 / 缓存默认落盘                        | **需改配置**                       | 只读文件系统下 `file` 驱动会丢会话，必须切 Redis                                                                                         |

**其余能力可用**：页面渲染、登录鉴权、项目/任务/招募/消息记录等 CRUD 接口、契约适配层（`code` + `requestId`）。

> 官方态度也要如实告知：`vercel-php` 是**社区维护的第三方运行时**，Vercel 官方不支持它，并在其 README 中建议新项目改用容器方式部署 PHP。详见 <https://github.com/vercel-community/php>。

### 0.1 部署配置速查（逐项对应 Dashboard 字段）

| 项目           | 值                                            | 依据                                                                                     |
| -------------- | --------------------------------------------- | ---------------------------------------------------------------------------------------- |
| 项目类型       | PHP / Laravel 8 应用（非前端静态站）          | `composer.json` 要求 php ^8.0 + laravel/framework ^8.48；无 Next/Nuxt 产物               |
| Framework 预设 | **Other**（不能选 Node.js / Next.js）         | 选错会注入 `npm run build`，触发仓库里 docker 化的 `./cmd prod` 而失败                   |
| 构建命令       | **空**（`vercel.json` 覆盖为 no-op）          | 真正的构建由 `vercel-php` 运行时完成：composer 装依赖 + 打包函数                         |
| 输出目录       | **空**                                        | 没有静态产物目录；页面由 `api/index.php` 这个函数动态渲染                                |
| 安装命令       | **空**                                        | 前台 `npm install` 要装 427MB 且对 PHP 无用                                              |
| 函数入口       | `api/index.php`                               | 在 `vercel.json` 的 `functions` 中声明，路由兜底到它                                     |
| 运行时         | `vercel-php@0.4.5`（PHP 8.0，Amazon Linux 2） | 与 `composer.json` 的 `^8.0` 对齐；更高版本运行时未验证                                  |
| 区域           | `hkg1`                                        | 与云库同区或就近，跨洋每次查询多 200ms                                                   |
| 函数规格       | 1024MB / 60s                                  | 够 Laravel 启动 + 68MB autoload；Hobby 上限 300s                                         |
| 部署包体积     | 约 33MB（源码），依赖在云端安装               | `.vercelignore` 排除 vendor 68MB、node_modules 427MB、resources/assets 24MB、docker 11MB |
| 依赖安装       | 云端 composer 安装，不装 npm                  | 本地 `vendor/` 是 Windows/PHP 8.0 产物，不能上传                                         |

---

## 1. 部署前置条件

### 1.1 平台与工具

| 项          | 要求                                                     |
| ----------- | -------------------------------------------------------- |
| Vercel 账号 | Hobby 足够跑通；函数默认超时 300s，请求/响应体上限 4.5MB |
| Vercel CLI  | `npm i -g vercel`（也可用 Git 仓库集成自动部署）         |
| 本地 PHP    | 仅用于 `vercel dev` 调试，需 PHP 8.0（与运行时一致）     |

### 1.2 外部依赖（Vercel 一个都不提供，必须自备）

| 依赖           | 用途              | 选型建议                                   | 关键要求                                                                                      |
| -------------- | ----------------- | ------------------------------------------ | --------------------------------------------------------------------------------------------- |
| MySQL 5.7+/8.0 | 业务库（34 张表） | PlanetScale、TiDB Cloud、Aiven、阿里云 RDS | 必须**公网可达**；Vercel 出口 IP 不固定，需放开白名单或用 Vercel Secure Compute；建议强制 TLS |
| Redis 6+       | 会话、缓存、队列  | Upstash Redis                              | 若只提供 `rediss://` URL，需自行拆成 host/port/password（项目读的是分项配置）                 |

导入表结构：在本地执行 `mysqldump` 导出后导入云库，或把 `docker/mysql` 初始化脚本改造后执行。**不要**把 `prisma`/SQLite 那套思路套过来，本项目没有迁移文件体系。

### 1.3 APP_KEY

```bash
php artisan key:generate --show     # 输出 base64:xxxxx，填入 Vercel 环境变量
```

---

## 2. 构建配置

### 2.1 `vercel.json`（已在仓库根目录）

```json
{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "regions": ["hkg1"],
  "installCommand": "node -e \"console.log('[vercel] skip npm install')\"",
  "buildCommand": "node -e \"console.log('[vercel] skip npm build')\"",
  "functions": {
    "api/index.php": {
      "runtime": "vercel-php@0.4.5",
      "memory": 1024,
      "maxDuration": 60
    }
  },
  "routes": [
    { "src": "/api/(.*)", "dest": "/api/index.php" },
    { "src": "/[^/]*\\.php$", "dest": "/api/index.php" },
    { "src": "/", "dest": "/api/index.php" },
    { "handle": "filesystem" },
    { "src": "/(.*)", "dest": "/api/index.php" }
  ]
}
```

四处的用意，逐条说明：

1. **`installCommand` / `buildCommand` 必须改成空操作**。仓库 `package.json` 的 `build` 是 `./cmd prod`（docker compose 编排），Vercel 若按 Node 项目自动执行会直接失败。依赖安装交给 PHP 运行时自己跑 composer。
2. **`runtime` 选 `vercel-php@0.4.5`（PHP 8.0.x）**，与 `composer.json` 的 `"php": "^8.0"` 和本地已验证的 8.0.30 对齐。可选项：`0.5.5`→PHP 8.1、`0.6.2`→8.2。**注意 PHP 8.0 已停止安全支持**，若要升到 8.1/8.2，只需改这一个字符串并在本地跑一遍全量测试。
3. **不要给 `/js/*`、`/css/*` 加改写**。`vercel-php` 把静态根设为 `public/`，`/js/app.js` 天然就落到 `public/js/app.js`。早期版本误加了 `dest: /public/$1/$2`，等于多套一层变成 `public/public/js/app.js`，结果所有静态资源都掉进函数兜底返回 500。
4. **`/` 与 `*.php` 必须抢在 filesystem 之前送进函数**。静态根是 `public/`，意味着 `/` 会被解析成 `public/index.php` 并**当静态文件原样吐出**——响应头是 `Content-Type: application/x-httpd-php`，等于公开 PHP 源码。`/[^/]*\.php$` 同理拦下 `/index.php`。这两条不是可选项。
5. **最后一条是前端控制器兜底**，让所有非静态、非 `/api` 的请求（SPA 路由、邀请链接等）都进 Laravel。

### 2.2 `api/index.php`（Vercel 专用入口，不影响 `public/index.php`）

这个入口解决四个只有 Serverless 才会遇到的问题：

| 问题                                            | 处理                                                                                                                            |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| 运行期文件系统只读                              | `$app->useStoragePath('/tmp/dootask-storage')`，并预先建好 `framework/{cache,sessions,views}`、`logs`、`app/public`             |
| `bootstrap/cache` 只读                          | 用 `APP_PACKAGES_CACHE` / `APP_SERVICES_CACHE` 等环境变量把落盘位置指向 `/tmp`，**必须在 `require bootstrap/app.php` 之前写入** |
| `PATH_INFO` 污染导致 `/api/*` 全部落到 SPA 兜底 | 把 `SCRIPT_NAME`/`PHP_SELF` 归一为 `/index.php` 并清掉 `PATH_INFO`                                                              |
| Windows 上盘符路径不被认作绝对路径              | `$app->addAbsoluteCachePathPrefix('D:')`，仅在 `preg_match('#^[A-Za-z]:[\\\\/]#')` 命中时执行，线上走 `/tmp` 不触发             |

**不要用 `Application::useBootstrapPath()`**：那是 Laravel 9 才有的方法，本仓库是 8.48，调用会直接 `Call to undefined method`（本地无法复现，因为本地有可写目录、走不到那个分支）。

`PATH_INFO` 那一条是**实际踩到的坑**，值得单独说：以 router 脚本方式执行时，PHP 会把 `PHP_SELF` 写成 `/api/index.php/system/setting`、`PATH_INFO` 写成 `/system/setting`，Symfony 据此把 `/api` 当作 baseUrl 剥掉，于是 `/api/system/setting` 被当成 `/{method}/{action}` 命中 SPA 兜底路由——**返回 200 和一段 HTML，而不是 JSON**。这种"看起来成功、其实进错路由"的故障最难排查，已在入口里修掉，改动入口时不要删这段。

### 2.2.1 运行时助手函数不能挂在 `autoload-dev`

`asset_main()` 定义在 `app/Helpers/Function.php`，`main.blade.php` 渲染时就会调用。

它在 `composer.json` 里原本写在 **`autoload-dev.files`** 下，而 `vercel-php` 构建期执行的是 `composer install --no-dev`，这个文件因此不会被自动加载，线上表现为首页 500：`Illuminate\View\ViewException: Call to undefined function asset_main()`。

已把该文件移到 `autoload.files`（运行时必需的东西不属于 dev）。改 `autoload` 不会让 `composer.lock` 的 `content-hash` 失效，无需重新锁版本。

### 2.3 `api/php.ini`

只做覆盖，不是完整 ini（Vercel 侧为合并语义）。关键值：`memory_limit=512M`、`upload_max_filesize=4M`、`post_max_size=5M`、`max_execution_time=60`、`display_errors=Off`。

⚠️ 本地验证时**不要**用 `php -c api/php.ini` 启动，那会替换整份 ini 导致 openssl 等扩展消失（表现为 `Call to undefined function openssl_cipher_iv_length()`）。要模拟覆盖语义请用 `php -d key=value` 逐项传。

### 2.4 `.vercelignore`

排除 `vendor`（由构建期 composer 安装）、`node_modules`（427MB）、`resources/assets`（24MB Vue 源码，产物已在 `public/js`）、`docker`（11MB）、`docs`、`demo`、`tests`、`deploy`、`.env*`、`public/uploads`、`storage/{logs,framework}`、`bootstrap/cache`。

**注意 `.env` 必须在排除名单里**，凭据一律走 Vercel 环境变量；仓库只留 `.env.example`。

---

## 3. 环境变量

写在 Vercel 项目 Settings → Environment Variables，Production 与 Preview 各配一份。完整清单见 `deploy/vercel/env.example`。

**必填**

| 变量                                                          | 说明                                           |
| ------------------------------------------------------------- | ---------------------------------------------- |
| `APP_KEY`                                                     | `base64:...`，必填，缺了会 500                 |
| `APP_ENV` / `APP_DEBUG` / `APP_URL`                           | `production` / `false` / 你的 Vercel 域名      |
| `APP_STORAGE_PATH`                                            | 建议显式写 `/tmp/dootask-storage`              |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` | 云端 MySQL                                     |
| `DB_PREFIX`                                                   | 与本地一致（本项目为 `pre_`），漏配会"表不存在" |
| `MYSQL_ATTR_SSL_CA`                                           | TiDB 强制 TLS，填 CA 路径（见下）              |
| `REDIS_HOST` `REDIS_PORT` `REDIS_PASSWORD`                    | 云端 Redis（`REDIS_URL` 对 predis 无效）       |
| `REDIS_SCHEME=tls` `REDIS_DB=0` `REDIS_CACHE_DB=0`            | 托管 Redis 必配，见 7.6                        |
| `PLATFORM_CONTRACT_ENABLED`                                   | `true`，启用契约适配层（`code` + `requestId`） |

`MYSQL_ATTR_SSL_CA` 在 vercel-php（Amazon Linux 系）运行时通常填系统根证书包：

```
MYSQL_ATTR_SSL_CA=/etc/pki/tls/certs/ca-bundle.crt
```

**强烈建议**

| 变量                     | 原因                                                      |
| ------------------------ | --------------------------------------------------------- |
| `SESSION_DRIVER=redis`   | 默认 `file` 在只读盘上会丢会话，登录后立刻掉线            |
| `CACHE_DRIVER=redis`     | 同上                                                      |
| `QUEUE_CONNECTION=redis` | 队列若走 database 会与只读盘冲突                          |
| `LOG_CHANNEL=stderr`     | 只读盘写不了 `storage/logs`，写 stderr 才能进 Vercel 日志 |

**不要配**：`WS_*`（WebSocket）、OnlyOffice/drawio 相关、任何指向 `/var/www` 的路径。

命令行写入：

```bash
vercel env add APP_KEY production
vercel env add DB_HOST production
# …其余同理；也可在 Dashboard 批量粘贴
```

---

## 4. Vercel 项目配置要点

| 配置项                | 取值                          | 说明                                                      |
| --------------------- | ----------------------------- | --------------------------------------------------------- |
| Framework Preset      | **Other**                     | 不要选 Next.js 等，否则会强行注入构建步骤                 |
| Root Directory        | 仓库根（`./`）                | `vercel.json` 与 `api/` 必须在根                          |
| Build Command         | 由 `vercel.json` 覆盖为空操作 | Dashboard 里不要再填 `npm run build`                      |
| Output Directory      | 留空                          | 静态文件直接按仓库路径提供，靠 routes 改写                |
| Install Command       | 由 `vercel.json` 覆盖为空操作 | —                                                         |
| Region                | `hkg1`（香港）                | **必须与数据库同区或就近**，跨洋往返会让每次请求多 200ms+ |
| Function Memory       | 1024MB                        | 由 `vercel.json` 指定，够 Laravel + 68MB autoload         |
| Function Max Duration | 60s                           | Hobby 上限 300s，60s 足够绝大多数接口                     |
| Node.js Version       | 任意（20/22 均可）            | 不参与实际构建                                            |
| Deployment Protection | **生产环境务必关闭**          | 开启后所有请求（含 API）会先被 SSO 拦成 401               |
| 自定义域名            | 建议配                        | `*.vercel.app` 在国内访问不稳定                           |

---

## 5. 部署步骤

### 5.0 先把数据库准备好（否则部署成功但接口全 500）

Vercel 不提供数据库，`/api/*` 的第一件事就是查库。两条免费路径的完整开通步骤见
`deploy/vercel/provision-cloud-db.md`（TiDB Cloud Starter + Upstash，含 TLS 要求与额度限制）。

建好库后导数据：

```bash
# 1) 本地导出（结构 + 数据；--schema-only 只导结构）
bash deploy/vercel/dump-for-cloud.sh

# 2) 导入云库
mysql -h <云库host> -P <端口> -u <用户> -p --ssl-mode=REQUIRED <库名> < dootask-cloud.sql
```

脚本会自动做两项兼容性预检：MariaDB 10.5+ 文件头那行 `/*!999999 enable the sandbox mode */` 会被过滤（否则 MySQL 8 / TiDB 直接报语法错）；同时提示是否含外键（PlanetScale 不支持外键，含外键请改用 TiDB Cloud 或 RDS）。

导完在云库执行 `SELECT COUNT(*) FROM pre_users;` 确认非 0，再继续下一步。

> **云库的 TLS 不是可选项。** TiDB 公网端点强制 TLS，`MYSQL_ATTR_SSL_CA` 必须填 CA **文件路径**
> （`/etc/pki/tls/certs/ca-bundle.crt`），填 `true` 会被 PDO 当成路径而报错。
> Upstash 同理，连接串必须是 `rediss://`，且 `REDIS_CLIENT` 必须显式设为 `predis`
> —— `vercel-php` 运行时没有 phpredis 扩展，留默认值会白屏。

**Windows 上导入 TiDB 的两个坑（实测）**

1. MariaDB 自带的 `mysql.exe` 走的是 Windows SChannel，连 TiDB 会报
   `TLS/SSL error: The specified data could not be decrypted (0x80090330)`，
   加 `--ssl` 也没用（10.11 客户端不认 `--ssl-mode`）。临时 CA 要另给：
   下载 <https://curl.se/ca/cacert.pem>，再 `--ssl-ca=cacert.pem`；
   仍失败就用 PHP 的 PDO 导入（`PDO::MYSQL_ATTR_SSL_CA` 走 OpenSSL，不受影响）。
2. TiDB Cloud Starter 只给你一个 `test` 库，先 `CREATE DATABASE dootask`。导入后表名带 `pre_` 前缀，
   所以线上的 `DB_PREFIX` 必须是 `pre_`，否则接口报"表不存在"。

### 5.1 GitHub 集成部署（推荐，本仓库已就绪）

仓库地址 `https://github.com/ft548548/448566847`，**public**，默认分支 `main`，最新提交已包含本文所有部署文件。public 仓库无需给 Vercel 安装 GitHub App 到组织，只需首次授权 Vercel 读取你的 GitHub。

1. 打开 <https://vercel.com/new> → **Import Git Repository** → 选中 `ft548548/448566847`。
2. **Configure Project** 页逐项填写：

| 字段             | 填什么                         | 不这么填会怎样                                                                  |
| ---------------- | ------------------------------ | ------------------------------------------------------------------------------- |
| Project Name     | `dootask`                      | 只影响 `*.vercel.app` 子域，随意                                                |
| Framework Preset | **Other**                      | 选成 Node.js/Next.js 会注入 `npm run build`，触发仓库里的 docker 编排脚本而失败 |
| Root Directory   | `./`（保持默认）               | 选成 `api` 会让函数文件找不到 `vendor/`                                         |
| Build Command    | **清空**（点 Override 后留空） | Dashboard 的优先级高于 `vercel.json`，一旦填了 `npm run build` 就会失败         |
| Output Directory | **清空**                       | 填了会导致静态资源 404                                                          |
| Install Command  | **清空**                       | 会执行 427MB 的 `npm install`，且对 PHP 无用                                    |
| Node.js Version  | 20 或 22 均可                  | 不影响结果，不参与实际构建                                                      |

3. 展开 **Environment Variables**，一次粘贴下面这份（值换成你自己的），Production 与 Preview 都要：

```
APP_NAME=DooTask
APP_ENV=production
APP_KEY=base64:把 php artisan key:generate --show 的输出粘这里
APP_DEBUG=false
APP_URL=https://dootask.vercel.app
APP_STORAGE_PATH=/tmp/dootask-storage
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
REDIS_HOST=
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
SESSION_DRIVER=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
LOG_CHANNEL=stderr
PLATFORM_CONTRACT_ENABLED=true
```

> `SESSION_DRIVER` / `CACHE_DRIVER` 不给 Redis 的话，默认 `file` 写在只读盘上，表现为**登录成功但立刻掉线**，排起来很费时间，建议一开始就配。

4. 点 **Deploy**，首次构建约 2 ～ 4 分钟（主要是 composer 装依赖）。
5. 部署完成后进 **Settings → Deployment Protection**，确认生产环境**没有**开启（开了会让 API 返回 401 和 Vercel 登录页）。
6. 后续行为：push 到 `main` 自动发生产环境，其他分支与 PR 自动发 Preview 环境——**这意味着以后每次 push 都会重新部署**，改动前留意。

7. 拿到地址后跑验收：

```bash
bash deploy/vercel/verify.sh https://dootask.vercel.app
```

### 5.2 CLI 一键部署（含环境变量与验收）

仓库已备好脚本，Token 认证即可全自动：

```bash
cd D:/dt-run/dootask
cp deploy/vercel/env.vercel.local.example deploy/vercel/env.vercel.local
# 填完 DB_*/REDIS_*/APP_KEY 后：
VERCEL_TOKEN=<在 vercel.com/account/tokens 生成> bash deploy/vercel/deploy.sh
```

脚本做五件事：`link` 项目 → 逐条写入 Production 环境变量（已存在先删再加）→
`deploy --prod --archive=tgz` → 自动跑 `verify.sh` → 打印地址与结论。
变量文件里任何一项留空会被前置检查直接拒绝，避免部署成功后才在运行期炸。

可选：`VERCEL_SCOPE=<团队slug>`、`VERCEL_PROJECT=<项目名>`、`SKIP_VERIFY=1`。

> **Windows 上不要用 `vercel deploy --temporary`。** 匿名部署会改为**本地构建**，
> CLI 安装 `vercel-php` 构建器时需要在缓存目录创建符号链接，未开开发者模式会报
> `EPERM: operation not permitted, symlink ...`，且该缓存清理会触发安全策略拦截。
> 走 Token 认证时构建在 Vercel 云端 Linux 上进行，不触发此限制。
> 若确实需要匿名临时部署，只能改用 WSL/Linux 机器或临时开启开发者模式。

---

## 6. 部署后验证清单

已附脚本：`bash deploy/vercel/verify.sh https://<your-domain>`，逐项打印通过/失败。

| #   | 检查项                            | 期望结果                                                    | 失败含义                               |
| --- | --------------------------------- | ----------------------------------------------------------- | -------------------------------------- |
| 1   | `GET /`                           | 200，`text/html`，含 DooTask 前端 HTML                      | 兜底 route 或入口异常                  |
| 2   | `GET /js/app.js`                  | 200，`application/javascript`                               | 静态改写规则没生效                     |
| 3   | `GET /api/system/setting`         | 200，`{"ret":1,...,"code":"OK","requestId":"req_..."}`      | 数据库/Redis 不通，或环境变量缺失      |
| 4   | 未登录 `GET /api/users/info`      | `{"ret":-1,"msg":"请登录后继续...","code":"AUTH_REQUIRED"}` | 契约中间件未挂载                       |
| 5   | 自定义 `X-Request-Id` 回带        | 响应头与响应体 `requestId` 同值                             | 中间件被跳过                           |
| 6   | 非法 `X-App-Code`                 | 400 + `VALIDATION_ERROR` + `APP_CODE_INVALID`               | 同上                                   |
| 7   | 登录 `POST /api/users/login`      | 200 + `data.token`                                          | CSRF/Session 配置问题（见 7.7）        |
| 8   | 带 token `GET /api/project/lists` | `ret:1` 且有项目数据                                        | 数据库导入不完整                       |
| 9   | `GET /api/file/lists`             | `ret:1` 且能返回**历史**文件记录                            | 只读历史数据没问题，**新上传必然失败** |
| 10  | 上传任意附件                      | 预期 413 或 500                                             | 属已知限制，不是配置错误               |
| 11  | 前端打开消息会话                  | 可能长时间 connecting / 报错                                | WebSocket 已知不可用                   |
| 12  | `vercel logs <url>`               | 有访问日志、无 fatal                                        | 有 fatal 先看第 7 节                   |

> 第 3 ～ 6 项我在本地已用同一入口脚本 + 真实 MySQL/Redis 验证通过（`php -S 127.0.0.1:8099 api/index.php`，`/api/system/setting` 返回 `ret:1, code:OK`，未登录返回 `AUTH_REQUIRED`，`X-Request-Id` 原样回带）。所以这几项在 Vercel 上若失败，问题一定在环境变量或外部依赖，不在入口代码。

---

## 7. 常见问题与处理

**7.1 500：`The stream or file "/var/task/storage/logs/laravel.log" could not be opened`**
只读文件系统。确认 `APP_STORAGE_PATH=/tmp/dootask-storage` 已配、`api/index.php` 里的 `useStoragePath` 未被删。同一类报错也可能来自 `bootstrap/cache`，入口已做回落。

**7.2 所有 `/api/*` 返回 200 + HTML（SPA 首页）**
就是 2.2 说的 `PATH_INFO` 污染。若你改过入口，确认 `SCRIPT_NAME`/`PHP_SELF` 归一与 `unset($_PATH_INFO)` 仍在；也可用 `vercel logs` 看是否命中了 `/{method}/{action}` 路由。

**7.3 413 `FUNCTION_PAYLOAD_TOO_LARGE`**
平台硬限制 4.5MB，改 `php.ini` 无效。要支持大文件必须改成"前端直传对象存储 + 后端只存元数据"，属功能改造。

**7.4 构建失败：`composer install` 报错 / 找不到 composer**
两种退路：① 把 `.vercelignore` 里的 `/vendor` 删掉，直接把本地 `vendor` 传上去（部署包会到 ~110MB，仍在 250MB 限制内）；② 在 `composer.json` 的 `scripts.vercel` 里显式跑 `composer install --no-dev`。注意 `--no-dev` 会移除 `laravel-s`，`Task::deliver` 相关接口会变成"类不存在"的 500（本来就不可用，不算新增故障）。

**7.5 数据库连接超时 / `SQLSTATE[HY000] [2002]`**
云库没放通公网：PlanetScale/TiDB 需开 IP allowlist（Vercel 出口 IP 不固定，只能 `0.0.0.0/0` + TLS，或用 Secure Compute 固定出口）。也可能是同区选错：`regions` 与数据库所在区域要一致。

**7.6 Redis 连不上 / 首页 500 但 `/api/*` 正常**
分三层查，按顺序命中率最高：

1. **`REDIS_URL` 设了但不生效**：`predis` 1.x 只在拿到**字符串**时才解析 URL，而 Laravel 的 `PredisConnector` 传的是**数组**，`url` 键会被忽略、静默回落到 `127.0.0.1`。必须拆成 `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD`，再加 `REDIS_SCHEME=tls`（`rediss://` 等价于 `scheme=tls`）。
2. **`ERR Only 0th database is supported`**：Upstash（以及多数托管 Redis）只提供 db 0，而 Laravel 的 `cache` 连接默认 `SELECT 1`。这会让**首页渲染 500、API 却正常**——因为 cache 只在 web 路由里被用到。加 `REDIS_DB=0` 与 `REDIS_CACHE_DB=0`。
3. **TLS 证书校验失败**：运行时没有 CA 包时置 `REDIS_SSL_VERIFY=false`（本项目 `config/database.php` 已把该开关透传给 predis 的 `ssl.verify_peer`）。

**7.7 登录返回 419 / 登录成功后立即掉线**
419 是 CSRF：Vercel 侧 `APP_URL` 若非真实域名会导致 cookie 域不匹配。掉线是 `SESSION_DRIVER=file` 写在只读盘，改成 `redis`。

**7.8 接口返回 401 且带 Vercel 登录页**
开了 Deployment Protection。项目 Settings → Deployment Protection 关掉，或给 API 加 bypass token（不建议）。

**7.9 冷启动明显（1 ～ 2s）**
官方宣称 250ms 是空载 PHP。Laravel 要加载 68MB autoload、连数据库和 Redis，冷启动 1 ～ 2s 属正常。缓解：`memory` 提到 1024MB、开启 Fluid Compute、必要时配 cron keep-warm。

**7.10 函数日志在哪**
`vercel logs <deployment-url>`（或 Dashboard → Logs）。`LOG_CHANNEL=stderr` 才能看到 Laravel 日志。

**7.11 上传接口 500**
`public_path()` 只读，属已知限制。若要支撑，需把 `app/Module/Base.php:2385`、`FileController.php:512/548` 改成对象存储（S3/R2），这会改动业务代码，需单独评估。

**7.12 WebSocket 一直 connecting**
前端连的是 `ws://host:5200`，Vercel 不提供长连接端口。函数实例回收即断线，无法靠配置解决。

**7.13 `vercel logs` 只看到堆栈尾部，看不到异常首行**
Vercel 运行时日志对单条消息有长度上限，Laravel 的堆栈一长，异常类型和消息那几行就被截掉了，只剩 `#50/#51/#52`。排障时用响应头把摘要带出来（临时改 `api/index.php`，用完即删）：

```php
// 临时代码，勿长期保留：会把异常信息暴露给调用方
$app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, static function ($app) {
    return new class ($app) extends \App\Exceptions\Handler {
        public function render($request, \Throwable $e)
        {
            $response = parent::render($request, $e);
            $response->headers->set('X-Diag', base64_encode(
                get_class($e) . ': ' . preg_replace('/\s+/', ' ', $e->getMessage())
            ));
            return $response;
        }
    };
});
```

然后 `curl -sI` 或任意 HTTP 头查询服务看 `X-Diag`，base64 解出来就是真正的报错。**必须用完删除**——留着等于对外泄露异常详情。

**7.14 页面 200 但内容是 PHP 源码**
见 2.1 第 4 条：`/` 与 `*.php` 被静态根 `public/` 当文件直出，响应头为 `application/x-httpd-php`。补上 `/{ "src": "/", ... }` 与 `/[^/]*\.php$` 两条路由，并重新部署（旧的会被 CDN 缓存）。

**7.15 CLI 报 `--create-if-missing requires --project`**
Vercel CLI 65.x 起，已 `link` 过的目录再带 `--create-if-missing` 会被判为非法组合。`deploy.sh` 里已补 `--project "$PROJECT"`；若你手敲命令，去掉 `--create-if-missing` 即可。

---

## 8. 什么时候不该用 Vercel

| 你的诉求                                       | 结论                                                                                          |
| ---------------------------------------------- | --------------------------------------------------------------------------------------------- |
| 完整功能（上传、实时消息、在线文档、定时任务） | 用容器平台：Zeabur / Railway / 阿里云 ECS + 仓库自带 `docker-compose.yml`（9 个服务原样保留） |
| 只是对外展示接口契约与 Demo                    | Vercel 静态托管 `demo/` 与 `docs/`，零风险                                                    |
| 低成本 PoC、验证"能不能跑起来"                 | 本文这套配置可用，但要接受第 0 节的 7 项缺失                                                  |

---

## 9. 回滚

```bash
vercel rollback                 # 回上一个生产部署
# 或
vercel ls && vercel promote <previous-deployment-url>
```

代码层面本套改动是**纯增量**（4 个新文件 + 1 个 `vercel.json`），未触碰 `public/index.php`、路由、控制器，因此 `git revert` 或删除这 4 个文件即可回到原状，不影响容器部署。
