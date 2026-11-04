#!/usr/bin/env bash
# 把本地 DooTask 库导出成可导入公网 MySQL 的 SQL 文件（供 Vercel 连接使用）
#
# 用法：
#   bash deploy/vercel/dump-for-cloud.sh                    # 结构 + 数据 → dootask-cloud.sql
#   bash deploy/vercel/dump-for-cloud.sh --schema-only      # 只要结构 → dootask-schema.sql
#   bash deploy/vercel/dump-for-cloud.sh /tmp/out.sql       # 指定输出路径
#
# 端口/库名可用环境变量覆盖：PORT=3307 DB=dootask bash deploy/vercel/dump-for-cloud.sh
#
# 为什么不用 mysqldump 的默认参数：
#   1. MariaDB 10.5+ 会在文件头写 `/*!999999 enable the sandbox mode */`，MySQL 8 / TiDB
#      读到会直接报语法错，这里统一过滤掉；
#   2. PlanetScale 不支持 tablespaces，必须带 --no-tablespaces；
#   3. --single-transaction 保证 InnoDB 表导出期间不锁表。

set -euo pipefail

MYSQL_BIN="${MYSQL_BIN:-D:/dt-run/mariadb/bin}"
PORT="${PORT:-3307}"
DB="${DB:-dootask}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

SCHEMA_ONLY=0
OUT=""
for arg in "$@"; do
  case "$arg" in
    --schema-only) SCHEMA_ONLY=1 ;;
    -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
    *) OUT="$arg" ;;
  esac
done

if [ -z "$OUT" ]; then
  if [ "$SCHEMA_ONLY" -eq 1 ]; then OUT="dootask-schema.sql"; else OUT="dootask-cloud.sql"; fi
fi

DUMP="$MYSQL_BIN/mysqldump.exe"
[ -x "$DUMP" ] || DUMP="$MYSQL_BIN/mysqldump"
if [ ! -x "$DUMP" ]; then
  echo "找不到 mysqldump：$DUMP" >&2
  echo "请用 MYSQL_BIN=/path/to/mariadb/bin 指定目录" >&2
  exit 2
fi

ARGS=(
  -h 127.0.0.1 -P "$PORT" -u "$DB_USER"
  --no-tablespaces
  --single-transaction
  --default-character-set=utf8mb4
  --routines --triggers
  --skip-add-locks
)
[ -n "$DB_PASS" ] && ARGS+=("-p$DB_PASS")
[ "$SCHEMA_ONLY" -eq 1 ] && ARGS+=(--no-data)

echo "导出 $DB（端口 $PORT）→ $OUT"
# 过滤 MariaDB 10.5+ 的沙箱标记（实际写法形如 /*M!999999\- enable the sandbox mode */），
# 部分导入器会把它当错误。grep -v 在“全部被过滤”时退出码为 1，用 || true 兜住。
"$DUMP" "${ARGS[@]}" "$DB" 2>/dev/null | grep -v 'sandbox mode' > "$OUT" || true

if [ ! -s "$OUT" ]; then
  echo "导出失败：输出文件为空。检查数据库是否在 $PORT 端口运行。" >&2
  exit 1
fi

TABLES=$(grep -c '^CREATE TABLE' "$OUT" || true)
echo "完成：$TABLES 张表，$(du -h "$OUT" | cut -f1)"

# 兼容性预检
if grep -qi 'FOREIGN KEY' "$OUT"; then
  echo "⚠ 检测到外键：PlanetScale 不支持外键，导入前需去掉或换用 TiDB/RDS"
else
  echo "✓ 无外键，PlanetScale / TiDB / RDS 均可导入"
fi
grep -qi 'CHARSET=utf8mb4' "$OUT" && echo "✓ 字符集 utf8mb4"
grep -qi 'sandbox mode' "$OUT" && echo "⚠ 沙箱标记未过滤干净，导入 MySQL 前手工删掉文件首行"

echo
echo "下一步："
echo "  mysql -h <云库host> -P <port> -u <user> -p <db> < $OUT"
echo "  或 TiDB Cloud / PlanetScale 控制台 → Import → 选择该文件"
