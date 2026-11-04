#!/usr/bin/env bash
# Vercel 部署验收脚本（规范 §13-5：部署后必须有可复现的验收手段）
#
# 用法：bash deploy/vercel/verify.sh https://your-project.vercel.app
# 说明：只读探测，不写任何数据；全部通过时退出码 0。
#
# 不落临时文件（部分受管终端会拦截 mktemp），全部用变量承接响应。

set -uo pipefail

BASE="${1:-}"
if [ -z "$BASE" ]; then
  echo "用法：bash deploy/vercel/verify.sh https://your-project.vercel.app" >&2
  exit 2
fi
BASE="${BASE%/}"

PASS=0
FAIL=0

red() { printf '\033[31m%s\033[0m' "$1"; }
green() { printf '\033[32m%s\033[0m' "$1"; }

ok() {
  PASS=$((PASS + 1))
  printf '  %s %s\n' "$(green '✓')" "$1"
}

bad() {
  FAIL=$((FAIL + 1))
  printf '  %s %s\n' "$(red '✗')" "$1"
  [ -n "${2:-}" ] && printf '      %s\n' "$2"
}

# 断言：状态码等于期望值
expect_code() {
  local name="$1" url="$2" want="$3" got
  got=$(curl -sS -o /dev/null -w '%{http_code}' -m 25 "$url" 2>/dev/null || echo 000)
  if [ "$got" = "$want" ]; then ok "$name（HTTP $got）"; else bad "$name" "期望 $want，实际 $got → $url"; fi
}

# 断言：响应体包含指定字符串
expect_contains() {
  local name="$1" url="$2" needle="$3" body
  body=$(curl -sS -m 25 "$url" 2>/dev/null || true)
  if printf '%s' "$body" | grep -q -- "$needle"; then
    ok "$name"
  else
    bad "$name" "响应中未找到「$needle」；实际前 120 字符：$(printf '%s' "$body" | head -c 120)"
  fi
}

echo "验收目标：$BASE"
echo

echo "[1/8] 静态与页面"
expect_code "首页可访问" "$BASE/" 200
expect_code "前端脚本可加载" "$BASE/js/app.js" 200
expect_contains "首页返回 HTML" "$BASE/" "<html"

echo
echo "[2/8] 契约信封（未认证接口）"
expect_contains "未登录返回 AUTH_REQUIRED" "$BASE/api/users/info" "AUTH_REQUIRED"
expect_contains "信封含 requestId 字段" "$BASE/api/users/info" "requestId"

echo
echo "[3/8] 数据库连通（无需认证的配置接口）"
expect_contains "system/setting 返回 ret:1" "$BASE/api/system/setting" '"ret":1'
expect_contains "system/setting 带 code:OK" "$BASE/api/system/setting" '"code":"OK"'

echo
echo "[4/8] X-Request-Id 透传"
RID="verify-$(date +%s)"
HEADERS=$(curl -sS -D - -o /dev/null -m 25 -H "X-Request-Id: $RID" "$BASE/api/system/setting" 2>/dev/null || true)
if printf '%s' "$HEADERS" | grep -qi "x-request-id: *$RID"; then
  ok "响应头回带自定义 X-Request-Id"
else
  bad "响应头未回带 X-Request-Id" "契约中间件可能未挂载（检查 PLATFORM_CONTRACT_ENABLED）"
fi

echo
echo "[5/8] X-App-Code 白名单"
CODE=$(curl -sS -o /dev/null -w '%{http_code}' -m 25 -H "X-App-Code: hacker" "$BASE/api/system/setting" 2>/dev/null || echo 000)
if [ "$CODE" = "400" ]; then ok "非法 X-App-Code 被拒绝（HTTP 400）"; else bad "非法 X-App-Code 未拦截" "期望 400，实际 $CODE"; fi

echo
echo "[6/8] 已知限制项（这些失败属预期，不计入失败数）"
UP_CODE=$(curl -sS -o /dev/null -w '%{http_code}' -m 25 -X POST "$BASE/api/file/upload" 2>/dev/null || echo 000)
echo "  · 上传探测：HTTP $UP_CODE（413/500 均为预期，Vercel 只读文件系统）"

echo
echo "[7/8] 冷启动观测"
T1=$(curl -sS -o /dev/null -w '%{time_total}' -m 60 "$BASE/api/system/setting" 2>/dev/null || echo 0)
T2=$(curl -sS -o /dev/null -w '%{time_total}' -m 60 "$BASE/api/system/setting" 2>/dev/null || echo 0)
echo "  · 首次 ${T1}s / 再次 ${T2}s（冷启动 1~2s 属正常）"

echo
echo "[8/8] 结果"
echo "  通过 $PASS 项，失败 $FAIL 项"
if [ "$FAIL" -gt 0 ]; then
  echo "  排查顺序：环境变量(APP_KEY/DB_*/REDIS_*) → 外部依赖可达性 → 入口 storage 重定向"
  exit 1
fi

echo "  部署验收通过。"
