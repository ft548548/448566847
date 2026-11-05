#!/usr/bin/env bash
# 服务器环境安装：PHP 8.0 (remi) + MariaDB + Redis
# 目标系统：Alibaba Cloud Linux 3（RHEL8 兼容）
set -euo pipefail
export LC_ALL=C

echo "===== [1/4] 引入 Remi 源 ====="
if [ ! -f /etc/yum.repos.d/remi.repo ]; then
  dnf install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm
else
  echo "remi.repo 已存在，跳过"
fi

echo "===== [2/4] 启用 PHP 8.0 模块 ====="
dnf module reset php -y || true
dnf module enable php:remi-8.0 -y

echo "===== [3/4] 安装 PHP 8.0 + 扩展 ====="
dnf install -y php-fpm php-cli php-mysqlnd php-mbstring php-xml php-curl \
               php-gd php-zip php-bcmath php-intl php-json php-process

echo "===== [4/4] 安装 MariaDB 与 Redis ====="
dnf install -y mariadb-server redis

echo "===== 版本确认 ====="
php -v | head -2
php -m | grep -iE "^(curl|gd|mbstring|pdo_mysql|zip|xml|bcmath|intl|json|openssl|fileinfo|tokenizer|ctype|dom|simplexml)$" | tr '\n' ' '
echo
mariadbd --version 2>/dev/null || mysqld --version 2>/dev/null || true
redis-server --version | head -1
echo "===== 安装结束 ====="
