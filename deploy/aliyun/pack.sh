#!/usr/bin/env bash
# 本地打包：生成待上传的站点压缩包（含 vendor，服务器无需 composer）
# 产物：deploy/aliyun/dootask-site.tar.gz（约 31 MB）
set -euo pipefail

cd "$(dirname "$0")/../.."
OUT="deploy/aliyun/dootask-site.tar.gz"

echo "==> 打包项目 $(date '+%F %T')"
tar czf "$OUT" \
  --exclude='./.git' \
  --exclude='./node_modules' \
  --exclude='./.env' \
  --exclude='./.vercel' \
  --exclude='./deploy/aliyun/*.tar.gz' \
  --exclude='./storage/logs/*.log' \
  --exclude='./bootstrap/cache/*.php' \
  --exclude='./dootask-cloud.sql' \
  --exclude='./.DS_Store' \
  . 2>/dev/null || true

ls -lh "$OUT" | awk '{print "   产物大小: "$5}'
echo "   排除项: .git / node_modules / .env / .vercel / 日志 / 缓存"
