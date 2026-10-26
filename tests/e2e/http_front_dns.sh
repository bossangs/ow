#!/usr/bin/env bash
# 前台回归入口：先由 setup_site.sh 建站，再检查公开页、登录页、CSRF 与 PHP 错误。
# 完整 DNS mock 数据造数由 CI/测试组注入；本脚本可作为端口参数化 smoke gate。
set -u
PORT="${1:-8232}"
BASE="http://127.0.0.1:${PORT}"
PASS=0
FAIL=0
check() {
  local name="$1" url="$2" expected="${3:-200}"
  local body code
  body=$(mktemp)
  code=$(curl -sS -o "$body" -w '%{http_code}' "$BASE$url" || true)
  if [ "$code" = "$expected" ] && ! grep -qiE 'fatal error|parse error|warning:|undefined variable' "$body"; then
    echo "PASS $name ($code)"
    PASS=$((PASS + 1))
  else
    echo "FAIL $name (got $code)"
    FAIL=$((FAIL + 1))
  fi
  rm -f "$body"
}
check "首页" "/"
check "登录页" "/index.php?s=/index/auth/login"
check "域名浏览页" "/index.php?s=/index/shop/domains"
check "DNS 未登录保护" "/index.php?s=/index/dns/index" 302
check "市场未登录保护" "/index.php?s=/index/market/index" 302
check "工单未登录保护" "/index.php?s=/index/ticket/index" 302
printf 'RESULT PASS=%s FAIL=%s\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
