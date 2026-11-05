#!/usr/bin/env bash
# 站点落地（在服务器上执行）：解包 -> 建库导数据 -> 写 .env -> 装 nginx 站点 -> 权限 -> 验收
# 占位符 __DB_PASS__ / __APP_KEY__ 由本地 deploy 流程替换后投递，仓库内不保存真实口令
set -euo pipefail

ROOT=/www/wwwroot/dootask
PHP=/opt/remi/php80/root/usr/bin/php
PHP_SOCK=unix:/var/opt/remi/php80/run/php-fpm/www.sock
DB_NAME=dootask
DB_USER=dootask
DB_PASS='__DB_PASS__'
APP_KEY_VAL='__APP_KEY__'
SITE_URL=http://8.134.60.240:8080

echo "===== [1/7] 解包 ====="
mkdir -p "$ROOT"
tar xzf /tmp/dootask-site.tar.gz -C "$ROOT"
echo "文件数: $(find "$ROOT" -type f | wc -l)"

echo "===== [2/7] 建库建号 ====="
systemctl is-active --quiet mariadb || systemctl start mariadb
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "库与账号就绪"

echo "===== [3/7] 导入数据 ====="
mysql -uroot "${DB_NAME}" < /tmp/dootask-data.sql
echo "表数量: $(mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")"
echo "用户数: $(mysql -uroot -N -e "SELECT COUNT(*) FROM ${DB_NAME}.pre_users" 2>/dev/null || echo 'n/a')"

echo "===== [4/7] 写入 .env ====="
cat > "$ROOT/.env" <<ENVEOF
APP_NAME=警务实训平台
APP_ENV=production
APP_DEBUG=false
APP_KEY=${APP_KEY_VAL}
APP_SCHEME=auto
APP_URL=${SITE_URL}

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
DB_PREFIX=pre_

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_SCHEME=tcp

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=null
MAIL_FROM_NAME="\${APP_NAME}"
ENVEOF
chmod 640 "$ROOT/.env"
echo ".env 写入完成（$(wc -l < "$ROOT/.env") 行）"

echo "===== [5/7] 目录权限 ====="
mkdir -p "$ROOT"/storage/{app,framework/{cache,sessions,views},logs} "$ROOT"/bootstrap/cache "$ROOT"/public/uploads
chown -R nginx:nginx "$ROOT"
find "$ROOT"/storage "$ROOT"/bootstrap/cache "$ROOT"/public/uploads -type d -exec chmod 775 {} \;
find "$ROOT"/storage "$ROOT"/bootstrap/cache "$ROOT"/public/uploads -type f -exec chmod 664 {} \;
echo "属主: $(stat -c '%U:%G' "$ROOT/storage")"

echo "===== [6/7] 安装 nginx 站点（8080）====="
if [ -f /tmp/nginx-dootask.conf ]; then
  sed -e "s#__PHP_SOCK__#${PHP_SOCK}#" -e "s#root   /www/wwwroot/dootask/public;#root   ${ROOT}/public;#" \
      /tmp/nginx-dootask.conf > /etc/nginx/conf.d/dootask-8080.conf
  nginx -t
  systemctl reload nginx
  echo "nginx 站点已启用：/etc/nginx/conf.d/dootask-8080.conf"
else
  echo "!! 未找到 /tmp/nginx-dootask.conf，跳过"
  exit 1
fi

echo "===== [7/7] 清理缓存并验收 ====="
cd "$ROOT"
sudo -u nginx "$PHP" artisan config:clear >/dev/null 2>&1 || true
sudo -u nginx "$PHP" artisan view:clear >/dev/null 2>&1 || true
sudo -u nginx "$PHP" artisan route:clear >/dev/null 2>&1 || true
sudo -u nginx "$PHP" artisan storage:link >/dev/null 2>&1 || true
echo "--- HTTP 探测 ---"
for u in / /images/logo.svg /css/app.css; do
  printf "%-18s -> %s\n" "$u" "$(curl -s -o /dev/null -m 15 -w '%{http_code}' "${SITE_URL}${u}")"
done
echo "===== 部署结束 ====="
