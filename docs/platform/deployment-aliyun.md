# 阿里云 ECS 部署手册（DooTask / 警务实训平台）

> 目标服务器：`8.134.60.240`（阿里云 ECS，安全组放行 0.0.0.0/0）
> 访问入口：`http://8.134.60.240:8080`
> 部署形态：nginx + PHP-FPM + MySQL/MariaDB + Redis，代码位于 `/www/wwwroot/dootask`

---

## 0. 关键前提（务必先读）

1. **80 端口已被占用。** 该机 `80` 上运行着「警院智慧考试平台」（nginx 1.24.0，根路径 302 → `/yj/login`）。
   本手册采用**独立端口 8080 + 独立数据库**的方式部署，**不修改任何既有站点配置**。
2. **文档根必须是 `public/`**，不是项目根目录。否则 `.env`、`storage/` 会被公网直接读取。
3. **`DB_PREFIX=pre_` 不能漏。** 库里表名全部带此前缀，漏配表现为「表不存在」。
4. **`APP_KEY` 必须沿用本地值。** 换新 key 会导致既有加密字段无法解密。

---

## 1. 技术栈与依赖清单

| 组件 | 版本要求 | 说明 |
|---|---|---|
| PHP | 8.0（8.1 可用） | Laravel 8.48 官方支持 7.3–8.1，**不要上 8.2+** |
| PHP 扩展 | `curl` `gd` `json` `libxml` `simplexml` `mbstring` `openssl` `pdo_mysql` `fileinfo` `zip` `tokenizer` `ctype` `dom` `bcmath` | `zip`/`gd`/`xml` 为 Excel 导出与图片处理所需 |
| nginx | ≥ 1.18 | 复用现有 nginx，仅新增 server 块 |
| MySQL/MariaDB | 5.7+ / 10.3+ | 字符集 `utf8mb4`，引擎 InnoDB |
| Redis | ≥ 5.0 | 缓存与会话（`predis` 纯 PHP 客户端，**不需要 phpredis 扩展**） |
| Composer | 不需要 | `vendor/` 随包上传 |
| Node.js | 不需要 | 前端产物 `public/js`、`public/css` 已编译 |

**无需**：队列 worker、计划任务（项目内无 Job、无 schedule）。

---

## 2. 服务器侧需要准备的

- SSH 访问：`root` 或具备 `sudo` 的账号（本手册命令以 root 为例）
- 磁盘：≥ 5 GB 可用（代码含 vendor 约 130 MB，压缩包 31 MB）
- 安全组：`8080` 入方向放行（用户已确认全放行）
- 若开启了 `firewalld`/`ufw`，需放行 8080：

```bash
firewall-cmd --permanent --add-port=8080/tcp && firewall-cmd --reload   # firewalld
ufw allow 8080/tcp                                                       # ufw
```

---

## 3. 环境探测（只读，不改任何配置）

```bash
bash deploy/aliyun/probe.sh
```

输出用于确认：发行版、是否装了宝塔面板、nginx 配置目录、已装 PHP 版本与 php-fpm socket 路径、MySQL/Redis 状态、可用磁盘。

**这一步的意义**：该机上有生产应用在跑，必须先看清环境，避免装错版本或覆盖既有配置。

---

## 4. 安装运行环境

按发行版择一执行（探测结果决定）。

### 4.1 Debian / Ubuntu

```bash
apt update
# PHP 8.0 需要 ondrej 源（Ubuntu 22.04 默认是 8.1，Debian 11 默认 7.4）
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update
apt install -y php8.0-fpm php8.0-cli php8.0-mysql php8.0-mbstring php8.0-xml \
               php8.0-curl php8.0-gd php8.0-zip php8.0-bcmath php8.0-intl
apt install -y nginx mariadb-server redis-server
```

### 4.2 RHEL / CentOS / Alibaba Cloud Linux 3

> **重要**：Alibaba Cloud Linux 3 的 `anolis-release` 不提供 `system-release(releasever)`，
> 直接安装 `remi-release-8.rpm` 会报 "nothing provides system-release(releasever) = 8"。
> 正确做法是手写 remi-safe 仓库（指向阿里云镜像），使用 **SCL 形式的 php80-php-\* 包**
> （装于 `/opt/remi/php80`，配置在 `/etc/opt/remi/php80`，不影响系统自带 PHP 7.4）：

```bash
# 1) 手写 Remi Safe 源（阿里云镜像）
cat > /etc/yum.repos.d/remi-safe.repo <<'EOF'
[remi-safe]
name=Remi Safe RPM repository for Enterprise Linux 8 - x86_64
baseurl=https://mirrors.aliyun.com/remi/enterprise/8/safe/x86_64/
enabled=1
gpgcheck=0
EOF

# 2) 安装 PHP 8.0（SCL）与周边服务
dnf install -y \
  php80-php-cli php80-php-fpm php80-php-common php80-php-mysqlnd \
  php80-php-mbstring php80-php-xml php80-php-gd php80-php-pecl-zip \
  php80-php-bcmath php80-php-intl php80-php-process php80-php-opcache \
  php80-php-pdo
dnf install -y nginx mariadb-server redis

# 3) php.ini 在 /etc/opt/remi/php80/php.ini，调整时区/上传/内存后改 fpm 池：
#    /etc/opt/remi/php80/php-fpm.d/www.conf
#      user = nginx / group = nginx
#      listen = /var/opt/remi/php80/run/php-fpm/www.sock
#      listen.owner = nginx / listen.group = nginx / listen.mode = 0660
#      listen.acl_users = nginx     ← Remi 默认是 apache,nginx，必须改为仅 nginx，
#                                      否则 nginx 连 socket 报 Permission denied（502）
```

> PHP 二进制：`/opt/remi/php80/root/usr/bin/php`；服务名：`php80-php-fpm`。

### 4.3 已有宝塔面板

宝塔通常已装 PHP，直接在面板「软件商店」安装 **PHP 8.0** 并勾选上述扩展；
nginx 配置写入 `/www/server/panel/vhost/nginx/dootask.conf`，
php-fpm 监听一般是 `unix:/tmp/php-cgi-80.sock`（以 `ss -lx | grep php` 为准）。

### 4.4 启动并设为开机自启

```bash
systemctl enable --now nginx php8.0-fpm mariadb redis     # Debian 系
systemctl enable --now nginx php80-php-fpm mariadb redis  # RHEL 系（Remi SCL 服务名）
```

---

## 5. 上传代码

本地执行：

```bash
bash deploy/aliyun/pack.sh          # 生成 deploy/aliyun/dootask-site.tar.gz（约 31 MB）
```

上传并解包（服务器执行）：

```bash
mkdir -p /www/wwwroot/dootask
tar xzf /tmp/dootask-site.tar.gz -C /www/wwwroot/dootask
```

上传方式三选一：
- `scp deploy/aliyun/dootask-site.tar.gz root@8.134.60.240:/tmp/`
- 面板文件管理器上传后解压
- `rsync -avz --exclude=node_modules ./ root@8.134.60.240:/www/wwwroot/dootask/`

---

## 6. 数据库初始化

```bash
DBPASS="$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)"

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS dootask DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'dootask'@'127.0.0.1' IDENTIFIED BY '${DBPASS}';
GRANT ALL PRIVILEGES ON dootask.* TO 'dootask'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

mysql -uroot dootask < /tmp/dootask-cloud.sql      # 34 张表 / 1069 行，含既有账号与内容
echo "数据库密码: ${DBPASS}"                        # 记下来，下一步写进 .env
```

校验：

```bash
mysql -udootask -p"${DBPASS}" -h127.0.0.1 dootask -e "SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema='dootask';"
# 期望 34
```

---

## 7. 配置 .env

```bash
cd /www/wwwroot/dootask
cp deploy/aliyun/env.aliyun.example .env
vi .env
```

必填四项：

```
APP_KEY=base64:...           # 沿用本地 .env 的值（保持加密数据可解）
APP_URL=http://8.134.60.240:8080
DB_PASSWORD=<上一步生成的密码>
DB_PREFIX=pre_
```

生成缓存与权限：

```bash
php artisan config:clear && php artisan config:cache
php artisan route:cache

chown -R www-data:www-data storage bootstrap/cache public/uploads   # Debian 系
# chown -R nginx:nginx storage bootstrap/cache public/uploads        # RHEL 系
chmod -R 775 storage bootstrap/cache public/uploads
```

---

## 8. nginx 站点配置

```bash
# 1) 确认 php-fpm 监听方式
ss -lx | grep -i php        # 例如 /run/php/php8.0-fpm.sock

# 2) 复制配置并替换 socket 占位符
cp deploy/aliyun/nginx-dootask.conf /etc/nginx/conf.d/dootask.conf
sed -i 's#__PHP_SOCK__#unix:/run/php/php8.0-fpm.sock#' /etc/nginx/conf.d/dootask.conf

# 3) 校验并重载（不会中断 80 上的既有站点）
nginx -t && systemctl reload nginx
```

宝塔环境把配置放到 `/www/server/panel/vhost/nginx/dootask.conf`，日志前缀改为 `/www/wwwlogs/`。

---

## 9. 验收

```bash
curl -sI http://127.0.0.1:8080/ | head -3                       # 200 text/html
curl -s  http://127.0.0.1:8080/api/system/setting | head -c 200 # ret:1 + code:OK
curl -sI http://127.0.0.1:8080/css/app.css | head -3            # 200 text/css
```

浏览器打开 `http://8.134.60.240:8080`，用原有账号登录。

---

## 10. 开机自启

`nginx`、`php-fpm`、`mariadb`、`redis` 均为 `systemctl enable` 托管，随系统启动。
项目本身不需要常驻进程；如需确认：

```bash
systemctl list-unit-files --state=enabled | grep -E "nginx|php|mysql|maria|redis"
```

---

## 11. 日志位置

| 用途 | 路径 |
|---|---|
| 应用日志 | `/www/wwwroot/dootask/storage/logs/laravel-YYYY-MM-DD.log` |
| nginx 访问 | `/var/log/nginx/dootask.access.log` |
| nginx 错误 | `/var/log/nginx/dootask.error.log` |
| php-fpm 错误 | `/var/log/php8.0-fpm.log`（RHEL：`/var/log/php-fpm/www-error.log`） |
| 系统日志 | `journalctl -u nginx -u php8.0-fpm --since "10 min ago"` |

常用：

```bash
tail -f storage/logs/laravel-$(date +%F).log
tail -f /var/log/nginx/dootask.error.log
journalctl -u php8.0-fpm -n 100 --no-pager
```

---

## 12. 常见问题排查

| 现象 | 原因 | 处理 |
|---|---|---|
| 502 Bad Gateway | php-fpm 未启动 / socket 路径不对 | `systemctl status php8.0-fpm`；`ss -lx \| grep php` 核对 `fastcgi_pass` |
| 500 且日志报「表不存在」 | 漏配 `DB_PREFIX=pre_` | `.env` 补上后 `php artisan config:cache` |
| 500 且日志报 Permission denied | `storage` / `bootstrap/cache` 权限 | 按 §7 重置属主与 775 |
| 页面白屏、静态资源 404 | 文档根写成了项目根而非 `public` | 检查 `root /www/wwwroot/dootask/public;` |
| 打开就跳登录页并报 CSRF 419 | `APP_URL` 与实际访问地址不一致 | `APP_URL` 改成 `http://8.134.60.240:8080` 并清缓存 |
| 登录成功但立刻掉线 | Redis 未启动 | `redis-cli ping` 应回 `PONG`；`systemctl start redis` |
| 上传失败 | `client_max_body_size` 过小或 `public/uploads` 无写权限 | nginx 调大 + §7 权限 |
| 文件预览链接指向 10.66.66.3 | 原代码写死 Docker 内网地址 | 已改为 `url()`（本次随代码提交），无需再配 `APP_IPPR` |
| 8080 本机通、外网不通 | 安全组或系统防火墙 | 放行 8080；`ss -lntp \| grep 8080` 确认监听 `0.0.0.0` |

---

## 13. 回滚

```bash
# 停用站点（保留文件）
rm -f /etc/nginx/conf.d/dootask.conf && nginx -t && systemctl reload nginx
# 数据回滚
mysql -uroot -e "DROP DATABASE dootask;"
```

既有考试平台不受影响：本方案未改动 80 端口的任何配置。
