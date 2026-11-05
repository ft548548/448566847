#!/usr/bin/env bash
# 本地非交互 SSH 执行器（OpenSSH 8.4+ 的 SSH_ASKPASS_REQUIRE）
# 用法：
#   DOOTASK_SSH_PASS='密码' bash deploy/aliyun/remote.sh 'uptime'
#   DOOTASK_SSH_PASS='密码' bash deploy/aliyun/remote.sh -f deploy/aliyun/probe.sh
#   DOOTASK_SSH_PASS='密码' bash deploy/aliyun/remote.sh -put 本地文件 /远程/路径
#
# 密码只经环境变量传递，不落盘；临时 askpass 脚本用完即删。
set -euo pipefail

HOST="${HOST:-8.134.60.240}"
SSH_USER="${SSH_USER:-root}"
PORT="${SSH_PORT:-22}"

: "${DOOTASK_SSH_PASS:?请通过环境变量 DOOTASK_SSH_PASS 传入密码}"

ASK="$(mktemp)"
cleanup() { rm -f "$ASK"; }
trap cleanup EXIT
printf '#!/bin/sh\nprintf "%%s\\n" "$DOOTASK_SSH_PASS"\n' > "$ASK"
chmod +x "$ASK"

SSH_OPTS=(
  -p "$PORT"
  -o StrictHostKeyChecking=no
  -o UserKnownHostsFile=/dev/null
  -o PreferredAuthentications=password
  -o PubkeyAuthentication=no
  -o NumberOfPasswordPrompts=1
  -o ConnectTimeout=15
)
export DOOTASK_SSH_PASS SSH_ASKPASS="$ASK" SSH_ASKPASS_REQUIRE=force DISPLAY=dummy:0

mode="${1:-}"

if [ "$mode" = "-f" ]; then
  # 把本地脚本送到远端 bash 执行（不落盘）
  shift
  ssh "${SSH_OPTS[@]}" "$SSH_USER@$HOST" 'bash -s' < "$1"
elif [ "$mode" = "-put" ]; then
  # 上传：base64 通过 stdin 传输，避免额外依赖 scp 的 askpass 行为
  shift
  local_file="$1"; remote_path="$2"
  b64="$(base64 < "$local_file" | tr -d '\n')"
  ssh "${SSH_OPTS[@]}" "$SSH_USER@$HOST" \
    "mkdir -p \"\$(dirname '$remote_path')\" && printf '%s' '$b64' | base64 -d > '$remote_path' && ls -l '$remote_path'"
else
  # 执行命令串
  ssh "${SSH_OPTS[@]}" "$SSH_USER@$HOST" "$*"
fi
