#!/usr/bin/env bash
# 只读环境探测：不安装、不修改任何文件，仅输出报告
# 用途：确认服务器已有组件，避免破坏 80 端口上的既有站点
export LC_ALL=C

echo "===== 1. 系统 ====="
[ -f /etc/os-release ] && grep -E "^(PRETTY_NAME|VERSION_ID|ID)=" /etc/os-release
uname -r
echo "内存: $(free -m 2>/dev/null | awk '/Mem:/{print $2" MB total, "$7" MB available"}')"
echo "根分区: $(df -h / 2>/dev/null | tail -1 | awk '{print $2" total, "$4" free"}')"

echo
echo "===== 2. 控制面板 ====="
[ -d /www/server/panel ] && echo "宝塔面板: 已安装" || echo "宝塔面板: 未检测到"
[ -f /www/server/panel/data/port.pl ] && echo "面板端口: $(cat /www/server/panel/data/port.pl)" || true

echo
echo "===== 3. nginx ====="
(nginx -v 2>&1 || /www/server/nginx/sbin/nginx -v 2>&1) | head -1
command -v nginx || ls -l /www/server/nginx/sbin/nginx 2>/dev/null
echo "-- 主配置与站点目录 --"
for d in /etc/nginx /etc/nginx/conf.d /etc/nginx/sites-enabled /www/server/panel/vhost/nginx /www/server/nginx/conf; do
  [ -d "$d" ] && echo "  $d ($(ls -1 "$d" 2>/dev/null | wc -l) 项)"
done
echo "-- 现有站点 server_name / listen / root --"
grep -rhE "^\s*(server_name|listen|root)\s" /etc/nginx/conf.d/*.conf /etc/nginx/sites-enabled/* /www/server/panel/vhost/nginx/*.conf 2>/dev/null | sed 's/^\s*/  /' | head -40

echo
echo "===== 4. PHP ====="
command -v php >/dev/null && php -v 2>&1 | head -2 || echo "  php: 未在 PATH 中"
echo "-- 已安装 PHP 版本目录 --"
ls -d /www/server/php/*/ 2>/dev/null | head -10
ls -d /etc/php/*/ 2>/dev/null | head -10
echo "-- php-fpm 进程与 socket --"
ps -eo pid,comm 2>/dev/null | grep -i "php-fpm" | head -5
ss -lx 2>/dev/null | grep -iE "php.*\.sock" | head -10
ls -1 /run/php/*.sock /tmp/php-cgi-*.sock /www/server/php/*/var/run/*.sock 2>/dev/null | head -10

echo
echo "===== 5. MySQL / MariaDB ====="
(mysql --version 2>/dev/null || /www/server/mysql/bin/mysql --version 2>/dev/null) || echo "  mysql 客户端: 未找到"
for s in mysqld mariadb mysql; do systemctl is-active "$s" >/dev/null 2>&1 && echo "  $s: $(systemctl is-active $s)"; done
ss -lnt 2>/dev/null | grep -E ":3306\b" | head -3

echo
echo "===== 6. Redis ====="
(redis-server --version 2>/dev/null | head -1) || echo "  redis-server: 未找到"
for s in redis redis-server; do systemctl is-active "$s" >/dev/null 2>&1 && echo "  $s: $(systemctl is-active $s)"; done
(redis-cli -h 127.0.0.1 ping 2>/dev/null | head -1) || echo "  redis ping: 无响应"
ss -lnt 2>/dev/null | grep -E ":6379\b" | head -3

echo
echo "===== 7. 端口监听总览 ====="
ss -lntp 2>/dev/null | awk 'NR==1 || /LISTEN/' | head -25

echo
echo "===== 8. 其他工具 ====="
for c in composer unzip rsync git tar curl; do
  p=$(command -v "$c" 2>/dev/null) && echo "  $c: $p" || echo "  $c: 缺失"
done

echo
echo "===== 9. 现有 /www/wwwroot ====="
ls -1 /www/wwwroot 2>/dev/null | head -10

echo
echo "===== 10. 防火墙 / SELinux ====="
systemctl is-active firewalld >/dev/null 2>&1 && echo "  firewalld: active" || echo "  firewalld: inactive/未安装"
command -v getenforce >/dev/null && echo "  SELinux: $(getenforce)" || echo "  SELinux: 未启用"
echo "===== 探测结束 ====="
