#!/usr/bin/env bash
# 大文件上传：ssh stdin 流式传输（不把内容塞进命令行，规避 ARG_MAX 限制）
# 用法：DOOTASK_SSH_PASS='密码' bash deploy/aliyun/upload.sh 本地文件 /远程/路径
set -euo pipefail

HOST="${HOST:-8.134.60.240}"
SSH_USER="${SSH_USER:-root}"
PORT="${SSH_PORT:-22}"

: "${DOOTASK_SSH_PASS:?请通过环境变量 DOOTASK_SSH_PASS 传入密码}"
local_file="$1"
remote_path="$2"

ASK="$(mktemp)"
trap 'rm -f "$ASK"' EXIT
printf '#!/bin/sh\nprintf "%%s\\n" "$DOOTASK_SSH_PASS"\n' > "$ASK"
chmod +x "$ASK"
export DOOTASK_SSH_PASS SSH_ASKPASS="$ASK" SSH_ASKPASS_REQUIRE=force DISPLAY=dummy:0

echo "==> 上传 $(basename "$local_file") （$(du -h "$local_file" | cut -f1)）"
ssh -p "$PORT" \
  -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  -o PreferredAuthentications=password -o PubkeyAuthentication=no \
  -o NumberOfPasswordPrompts=1 -o ConnectTimeout=15 \
  "$SSH_USER@$HOST" "mkdir -p \$(dirname '$remote_path') && cat > '$remote_path' && ls -lh '$remote_path'" \
  < "$local_file"
