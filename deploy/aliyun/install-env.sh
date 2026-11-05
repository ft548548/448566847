#!/usr/bin/env bash
# 服务器环境安装：PHP 8.0（Remi Safe SCL，装于 /opt/remi/php80）+ MariaDB + Redis
# 目标系统：Alibaba Cloud Linux 3（RHEL8 兼容，anolis-release 不提供 system-release(releasever)，
#          故不使用 remi-release rpm，改为手写 remi-safe 仓库指向阿里云镜像）
# 注：SCL 包配置目录在 /etc/opt/remi/php80（不是 /opt/remi/php80/root/etc）
set -euo pipefail
export LC_ALL=C

PHP_BIN=/opt/remi/php80/root/usr/bin/php
CONF_DIR=/etc/opt/remi/php80

echo "===== [1/6] 配置 Remi Safe 源 ====="
cat > /etc/yum.repos.d/remi-safe.repo <<'EOF'
[remi-safe]
name=Remi Safe RPM repository for Enterprise Linux 8 - x86_64
baseurl=https://mirrors.aliyun.com/remi/enterprise/8/safe/x86_64/
enabled=1
gpgcheck=0
EOF
dnf -q makecache --repo remi-safe
echo "remi-safe 源就绪"

echo "===== [2/6] 安装 PHP 8.0（SCL 独立目录，不影响系统 PHP 7.4）====="
dnf install -y \
  php80-php-cli php80-php-fpm php80-php-common php80-php-mysqlnd \
  php80-php-mbstring php80-php-xml php80-php-gd php80-php-pecl-zip \
  php80-php-bcmath php80-php-intl php80-php-process php80-php-opcache \
  php80-php-pdo

echo "===== [3/6] 安装 MariaDB 与 Redis ====="
dnf install -y mariadb-server redis

echo "===== [4/6] 调整 PHP 配置（时区/上传/内存/池用户）====="
INI="$CONF_DIR/php.ini"
set_ini() {
  local k="$1" v="$2" f="$INI"
  if grep -qE "^[; ]*${k}\s*=" "$f"; then
    sed -i -E "s|^[; ]*${k}\s*=.*|${k} = ${v}|" "$f"
  else
    echo "${k} = ${v}" >> "$f"
  fi
}
set_ini date.timezone Asia/Shanghai
set_ini memory_limit 512M
set_ini upload_max_filesize 512M
set_ini post_max_size 512M
set_ini max_execution_time 600
set_ini expose_php Off

WWW="$CONF_DIR/php-fpm.d/www.conf"
sed -i -E 's/^user\s*=.*/user = nginx/; s/^group\s*=.*/group = nginx/' "$WWW"
sed -i -E 's#^listen\s*=.*#listen = /var/opt/remi/php80/run/php-fpm/www.sock#' "$WWW"
sed -i -E 's#^;?listen.owner\s*=.*#listen.owner = nginx#; s#^;?listen.group\s*=.*#listen.group = nginx#; s#^;?listen.mode\s*=.*#listen.mode = 0660#' "$WWW"
echo "fpm 池：nginx 用户 + unix socket /var/opt/remi/php80/run/php-fpm/www.sock"

echo "===== [5/6] 启动并设置开机自启 ====="
mkdir -p /var/opt/remi/php80/run/php-fpm /var/opt/remi/php80/log/php-fpm
chown -R nginx:nginx /var/opt/remi/php80/run/php-fpm /var/opt/remi/php80/log 2>/dev/null || true
systemctl enable --now mariadb
systemctl enable --now redis
systemctl enable --now php80-php-fpm

echo "===== [6/6] 版本确认 ====="
"$PHP_BIN" -v | head -2
echo "--- 扩展 ---"
"$PHP_BIN" -m | grep -iE "^(curl|gd|mbstring|pdo_mysql|zip|xml|bcmath|intl|json|openssl|fileinfo|tokenizer|ctype|dom|simplexml)$" | tr '\n' ' '
echo
echo "--- 服务状态 ---"
systemctl is-active mariadb redis php80-php-fpm nginx | tr '\n' ' '
echo
echo "===== 安装结束 ====="
