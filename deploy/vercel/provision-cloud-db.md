# 开通云数据库（免费额度方案）

Vercel 不提供任何数据库。DooTask 的每个接口第一件事就是查库，所以**没有云库的部署等于一堆 500**。
本文给出两条免费路径，都够跑实训演示与小规模试用。

| 组件  | 方案               | 免费额度                                                 | 关键限制                         |
| ----- | ------------------ | -------------------------------------------------------- | -------------------------------- |
| MySQL | TiDB Cloud Starter | 5 GiB 行存 + 5 GiB 列存 + 5000 万 RU/月，单组织 5 个实例 | 公网端点**强制 TLS**，端口 4000  |
| Redis | Upstash Redis      | 256 MB、50 万命令/月、1 个库                             | **强制 TLS**，只支持 `rediss://` |

> 额度会变，开通前看一眼官方定价页。本文按 2026-11 的实际控制台流程写。

---

## 1. TiDB Cloud Starter（MySQL 兼容）

### 1.1 建实例

1. 打开 <https://tidbcloud.com> 注册（邮箱即可，不需要信用卡）。
2. 左侧 **Clusters → Create Cluster**，选 **Starter**（免费档）。
3. **Region 选靠近香港的**：`ap-southeast-1`（新加坡）或 `ap-northeast-1`（东京）。
   本项目 `vercel.json` 里 `regions` 是 `hkg1`，同区或邻近能把每次查询的往返压到 30ms 内；选美国西部会多 200ms 以上，页面体感明显变慢。
4. 创建完成后点集群名进详情，右上角 **Connect**：
   - Connection Type 选 **Public**
   - Connect With 选 **General**
   - 点 **Generate Password** 生成密码（只显示一次，先复制走）
   - 记下 **Host**、**Port**（4000）、**User**（形如 `3xxxxxx.root`）

### 1.2 放行来源 IP

Vercel 的函数出口 IP 不固定，必须在 **Connect → Configure IP Access List** 里加 `0.0.0.0/0`。
安全边界改由数据库账号密码 + TLS 承担，所以密码要长且只存在 Vercel 环境变量里。

### 1.3 建库

在集群页打开 **SQL Editor** 执行：

```sql
CREATE DATABASE dootask CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
```

`utf8mb4_bin` 是刻意的：DooTask 的用户名、项目名比较区分大小写，用 `_ci` 会出现「张三」和「张三 」被当成同一个键的问题。

### 1.4 导入本地数据

```bash
cd D:/dt-run/dootask

# 1) 导出（脚本已过滤 MariaDB 的 sandbox 文件头，TiDB 直接吃）
bash deploy/vercel/dump-for-cloud.sh

# 2) 导入（需要本机有 mysql 客户端；没有就用 TiDB 控制台的 Import 上传 sql）
mysql -h <TiDB Host> -P 4000 -u <User> -p --ssl-mode=REQUIRED dootask < dootask-cloud.sql

# 3) 回验
mysql -h <TiDB Host> -P 4000 -u <User> -p --ssl-mode=REQUIRED dootask \
  -e "SELECT COUNT(*) AS users FROM pre_users; SELECT COUNT(*) AS projects FROM pre_projects;"
```

两个数都非 0 才继续。为 0 说明导入目标库选错了。

**导出后建议顺手检查**：`dootask-cloud.sql` 里若出现 `DEFINER=`，TiDB 会拒绝（需要 SUPER 权限）。遇到就在导出命令里加 `--skip-definer`（脚本未默认加，因为本地 MariaDB 生成的视图通常不带）。

---

## 2. Upstash Redis

### 2.1 建库

1. 打开 <https://console.upstash.com> 注册（同样无需信用卡）。
2. **Create Database**：
   - Name：`dootask`
   - Type：**Regional**（免费档不支持 Global）
   - Region：选 `ap-southeast-1` 或 `us-east-1`，与 TiDB 尽量同区
   - TLS：默认开启且不可关闭
3. 建好后在详情页 **Connect** 标签里找 **Redis URL**，形如：

```
rediss://default:AXXXXXXXXXXXXXXXXXXX@apn1-xxxxx.upstash.io:6379
```

注意是 **`rediss://`**（两个 s），不是 `redis://`。Upstash 不接受明文连接。

### 2.2 本项目必须用 predis 客户端

`vercel-php` 运行时**没有 phpredis 扩展**，而 `config/database.php` 默认 `REDIS_CLIENT=phpredis`。
不显式设成 `predis` 会直接抛 `Class "Redis" not found`，表现为首页白屏。

我们用 `REDIS_URL` 传递完整连接串，`config/database.php` 已支持读取该变量；
Predis 1.1.10 能解析 `rediss://` 并自动走 TLS，不需要改代码。

### 2.3 免费额度够不够

50 万命令/月 ≈ 1.6 万/天。DooTask 一次页面加载会用到会话读写 + 缓存读写，按每请求 5~10 条命令估算，
够每天 2000 次左右的访问。超了会返回错误而不是扣费，演示场景基本碰不到。

**如果连 Upstash 这一步都想省**，可以完全不接 Redis，改用：

```
SESSION_DRIVER=cookie
CACHE_DRIVER=array
QUEUE_CONNECTION=sync
```

代价：多实例之间会话不共享（Vercel 会自动扩实例，用户可能偶发掉登录），队列任务同步执行会拖慢接口。
演示够用，长期不合适。

---

## 3. 装完回填环境变量

拿到四个值后，填进 `deploy/vercel/env.vercel.local`：

```
DB_HOST=gateway01.ap-southeast-1.prod.aws.tidbcloud.com
DB_PORT=4000
DB_DATABASE=dootask
DB_USERNAME=3xxxxxx.root
DB_PASSWORD=<TiDB 密码>
MYSQL_ATTR_SSL_CA=/etc/pki/tls/certs/ca-bundle.crt

REDIS_CLIENT=predis
REDIS_URL=rediss://default:<Upstash 密码>@apn1-xxxxx.upstash.io:6379
```

`MYSQL_ATTR_SSL_CA` 必须填**文件路径**而不是 `true`。TiDB 强制 TLS，PHP 的 PDO 只有拿到 CA 文件才会启用加密；
Amazon Linux 2 镜像自带公开根证书包，路径就是上面那个。

若连接报 `SSL certificate problem` 或 `CA certificate is not trusted`，说明 TiDB 用的是私有 CA：
到 Connect 对话框点 **CA cert** 下载，放到仓库内（例如 `storage/certs/tidb-ca.pem`），
把该行改成部署后的绝对路径 `/var/task/storage/certs/tidb-ca.pem`。
注意 `storage/certs` 需要在 `.vercelignore` 里放行（当前规则只排除了 `storage/logs`、`storage/framework/*`，默认会随包上传）。

---

## 4. 连通性自检（部署前就能做）

在本地用云库连接串跑一次，能在 5 秒内暴露 90% 的配置错误：

```bash
# MySQL：能连上并读到表即通过
mysql -h <Host> -P 4000 -u <User> -p --ssl-mode=REQUIRED dootask -e "SHOW TABLES LIMIT 5;"

# Redis：PING 应回 PONG（需本机有 redis-cli，或用 Upstash 控制台的 CLI）
redis-cli -u "rediss://default:<password>@<host>:6379" PING
```

两条都通过再去跑 `deploy/vercel/deploy.sh`，否则部署完只是在云上复现同一个错误。
