#!/usr/bin/env bash
set -u
PORT="${1:-8220}"
ROOT="/tmp/idcsite_${PORT}"
BASE="http://127.0.0.1:${PORT}/index.php?s="
PASS=0; FAIL=0
ok(){ echo "PASS $1"; PASS=$((PASS+1)); }
bad(){ echo "FAIL $1"; FAIL=$((FAIL+1)); }
if [ ! -d "$ROOT" ]; then bash "$(dirname "$0")/setup_site.sh" php7.0 "$PORT" >/tmp/idcdns_setup_${PORT}.log 2>&1 || { cat /tmp/idcdns_setup_${PORT}.log; exit 1; }; fi
if command -v mysql >/dev/null 2>&1; then mysql -uroot -e "UPDATE idcdns_e2e_${PORT}.setting SET value='0' WHERE name='captcha_enable'" >/dev/null 2>&1 || true; fi
php7.0 -S 127.0.0.1:"$PORT" -t "$ROOT" >/tmp/idcdns_http_${PORT}.log 2>&1 & PID=$!
trap 'kill "$PID" 2>/dev/null || true' EXIT
sleep 2
COOKIE="/tmp/idcdns_cookie_${PORT}"
rm -f "$COOKIE"
login=$(curl -s -c "$COOKIE" "${BASE}admin/login/index")
token=$(printf '%s' "$login" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
[ -n "$token" ] || token=$(printf '%s' "$login" | sed -n "s/.*name='_token' value='\([^']*\)'.*/\1/p" | head -1)
res=$(curl -s -L -b "$COOKIE" -c "$COOKIE" -d "_token=${token}&username=admin&password=Admin%4012345" "${BASE}admin/login/index")
echo "$res" | grep -qi '验证码\|captcha' && echo 'INFO login may require captcha' || true
for p in settings/index gateway/index upstream/index dnsprovider/index domain/index product/index product_group/index package/index tier/index market/index market_sale/index withdraw/index nat_node/index protected_record/index risk/index; do
  out=$(curl -s -L -b "$COOKIE" -w '\n%{http_code}' "${BASE}admin/${p}"); code=$(printf '%s' "$out" | tail -1); body=$(printf '%s' "$out" | sed '$d')
  if [ "$code" = 200 ] && ! printf '%s' "$body" | grep -Eqi 'Fatal error|Parse error|Warning:.*PHP|Call to undefined'; then ok "admin/${p}"; else bad "admin/${p} HTTP ${code}"; fi
done
# Static lint is intentionally part of this script so CI catches PHP 7 incompatibilities.
while IFS= read -r f; do php7.0 -l "$f" >/dev/null 2>&1 && ok "lint $f" || bad "lint $f"; done < <(find /workspace/idcdns/src/core/application/admin /workspace/idcdns/src/core/application/common/controller -name '*.php' -type f | sort)
echo "RESULT PASS=${PASS} FAIL=${FAIL}"
[ "$FAIL" -eq 0 ]
