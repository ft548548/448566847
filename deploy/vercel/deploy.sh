#!/usr/bin/env bash
# 一键部署 DooTask 到 Vercel（生产环境），并在部署完成后自动跑验收。
#
# 用法：
#   VERCEL_TOKEN=xxx bash deploy/vercel/deploy.sh
#
# 可选环境变量：
#   VERCEL_SCOPE    团队 slug；个人账号留空
#   VERCEL_PROJECT  项目名，默认 dootask
#   ENV_FILE        环境变量文件，默认 deploy/vercel/env.vercel.local
#   SKIP_VERIFY=1   部署完不跑验收
#
# 为什么不让 CLI 本地构建：Windows 上 CLI 安装构建器时需创建符号链接，
# 未开开发者模式会 EPERM。走 Token 认证时构建在 Vercel 云端 Linux 上进行，
# 不触发本机这个限制。
set -uo pipefail

TOKEN="${VERCEL_TOKEN:-}"
SCOPE="${VERCEL_SCOPE:-}"
PROJECT="${VERCEL_PROJECT:-dootask}"
ENV_FILE="${ENV_FILE:-deploy/vercel/env.vercel.local}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1

red() { printf '\033[31m%s\033[0m\n' "$1"; }
green() { printf '\033[32m%s\033[0m\n' "$1"; }
step() { printf '\n\033[36m==> %s\033[0m\n' "$1"; }

fail() {
  red "失败：$1"
  exit 1
}

# ---------- 0. 前置检查 ----------
step "0/5 前置检查"

[ -n "$TOKEN" ] || fail "缺少 VERCEL_TOKEN。到 https://vercel.com/account/tokens 新建后：VERCEL_TOKEN=xxx bash deploy/vercel/deploy.sh"
[ -f vercel.json ] || fail "未找到 vercel.json，请在仓库根目录执行"
[ -f api/index.php ] || fail "未找到 api/index.php（Vercel 入口）"
command -v node >/dev/null 2>&1 || fail "未找到 node"
[ -f "$ENV_FILE" ] || fail "缺少环境变量文件 $ENV_FILE（可从 deploy/vercel/env.vercel.local.example 复制）"

# 值不允许留空，留空会在部署成功后变成运行期 500，排查成本高
missing=""
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | \#*) continue ;; esac
  key="${line%%=*}"
  val="${line#*=}"
  if [ -z "$val" ]; then missing="$missing $key"; fi
done <"$ENV_FILE"
[ -z "$missing" ] || fail "环境变量为空：$missing"

green "✓ node $(node -v)，项目 $PROJECT，变量文件 $ENV_FILE"

VERCEL=(npx --yes vercel)
[ -n "$SCOPE" ] && VERCEL+=(--scope "$SCOPE")

# ---------- 1. 关联项目 ----------
step "1/5 关联 Vercel 项目"
"${VERCEL[@]}" link --yes --project "$PROJECT" --token "$TOKEN" || fail "关联项目失败（检查 Token 权限与项目名）"
green "✓ 已关联 $PROJECT"

# ---------- 2. 写入环境变量 ----------
step "2/5 同步环境变量到 Production"
count=0
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | \#*) continue ;; esac
  key="${line%%=*}"
  val="${line#*=}"
  # 已存在则先删再加，保证值是最新的（CLI 的 env add 不会覆盖同名变量）
  "${VERCEL[@]}" env rm "$key" production --yes --token "$TOKEN" >/dev/null 2>&1
  if printf '%s' "$val" | "${VERCEL[@]}" env add "$key" production --token "$TOKEN" >/dev/null 2>&1; then
    count=$((count + 1))
    printf '    %s\n' "$key"
  else
    fail "写入 $key 失败"
  fi
done <"$ENV_FILE"
green "✓ 写入 $count 个变量"

# ---------- 3. 部署 ----------
step "3/5 触发生产部署（云端构建，约 2~4 分钟）"
# 不用临时文件承接日志：部分受管终端会拦截 mktemp / rm
OUT="$("${VERCEL[@]}" deploy --prod --yes --project "$PROJECT" --create-if-missing --archive=tgz --token "$TOKEN" 2>&1)"
RC=$?
printf '%s\n' "$OUT"
[ "$RC" -eq 0 ] || fail "部署命令失败（见上方日志；常见原因：composer 缺扩展、环境变量缺失）"

URL="$(printf '%s' "$OUT" | grep -Eo 'https://[a-zA-Z0-9._-]+\.vercel\.app' | tail -1)"
[ -n "$URL" ] || fail "部署命令未返回地址，检查上面的构建日志"
green "✓ 部署完成：$URL"

# ---------- 4. 验收 ----------
step "4/5 部署后验收"
if [ "${SKIP_VERIFY:-0}" = "1" ]; then
  echo "  已按 SKIP_VERIFY=1 跳过"
  VERIFY_RC=0
else
  bash deploy/vercel/verify.sh "$URL"
  VERIFY_RC=$?
fi

# ---------- 5. 汇总 ----------
step "5/5 结果"
echo "  访问地址：$URL"
if [ "$VERIFY_RC" -eq 0 ]; then
  green "  验收通过"
else
  red "  验收未全通过（见上面逐项输出）"
  echo "  排查顺序：APP_KEY → DB_* → REDIS_* / REDIS_CLIENT → MYSQL_ATTR_SSL_CA"
fi
exit "$VERIFY_RC"
