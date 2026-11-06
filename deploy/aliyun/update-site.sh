#!/usr/bin/env bash
# 增量更新（在服务器上执行）：保留 .env 与 storage，替换代码，备份数据库，跑迁移，清缓存
# 与 setup-site.sh 的区别：不建库、不导数据、不覆盖 .env 既有值，只合并网关相关变量
set -euo pipefail

ROOT=/www/wwwroot/dootask
PHP=/opt/remi/php80/root/usr/bin/php
TS=$(date +%Y%m%d-%H%M%S)
NEW="${ROOT}.new.${TS}"
BAK="${ROOT}.bak.${TS}"
PKG=/tmp/dootask-gw.tar.gz

echo "===== [1/7] 备份数据库 ====="
mysqldump --single-transaction --quick dootask > "/tmp/dootask-db-${TS}.sql"
echo "备份: /tmp/dootask-db-${TS}.sql （$(du -h "/tmp/dootask-db-${TS}.sql" | cut -f1)）"

echo "===== [2/7] 解包到新目录 ====="
rm -rf "$NEW"
mkdir -p "$NEW"
tar xzf "$PKG" -C "$NEW"
echo "文件数: $(find "$NEW" -type f | wc -l)"

echo "===== [3/7] 继承 .env 与 storage ====="
cp -a "$ROOT/.env" "$NEW/.env"
rm -rf "$NEW/storage"
cp -a "$ROOT/storage" "$NEW/storage"

if [ -f /tmp/gw_env_addon.txt ]; then
  grep -vE '^(GATEWAY_|FORUM_|EXAM_|SHOWCASE_|PLATFORM_)' "$NEW/.env" > /tmp/env.merged || true
  cat /tmp/gw_env_addon.txt >> /tmp/env.merged
  mv /tmp/env.merged "$NEW/.env"
  chmod 640 "$NEW/.env"
  echo "已合并网关变量: $(grep -cE '^(GATEWAY_|FORUM_|EXAM_|SHOWCASE_|PLATFORM_)' "$NEW/.env") 项"
fi

echo "===== [4/7] 备份旧代码并切换 ====="
mv "$ROOT" "$BAK"
mv "$NEW" "$ROOT"

echo "===== [5/7] 权限 ====="
chown -R nginx:nginx "$ROOT"
mkdir -p "$ROOT"/storage/{app,framework/{cache,sessions,views},logs} "$ROOT"/bootstrap/cache "$ROOT"/public/uploads
find "$ROOT"/storage "$ROOT"/bootstrap/cache "$ROOT"/public/uploads -type d -exec chmod 775 {} \; 2>/dev/null || true
find "$ROOT"/storage "$ROOT"/bootstrap/cache "$ROOT"/public/uploads -type f -exec chmod 664 {} \; 2>/dev/null || true

echo "===== [6/7] 数据库迁移 ====="
cd "$ROOT"
sudo -u nginx "$PHP" artisan migrate --force 2>&1 | tail -25

echo "===== [7/7] 清缓存 ====="
sudo -u nginx "$PHP" artisan config:clear >/dev/null 2>&1 || true
sudo -u nginx "$PHP" artisan route:clear >/dev/null 2>&1 || true
sudo -u nginx "$PHP" artisan view:clear >/dev/null 2>&1 || true
sudo -u nginx "$PHP" artisan storage:link >/dev/null 2>&1 || true

echo "===== 本机验收 ====="
for u in / /js/app.js /api/v1/workshop/health; do
  printf "%-28s -> %s\n" "$u" "$(curl -s -o /dev/null -m 20 -w '%{http_code}' "http://127.0.0.1:8080${u}")"
done
echo "DONE"
echo "  旧代码: $BAK"
echo "  数据库: /tmp/dootask-db-${TS}.sql"
