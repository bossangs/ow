#!/bin/bash
set -u
PORT=${1:-8230}; BASE="http://127.0.0.1:${PORT}"; J="/tmp/idcdns-front-${PORT}.cookie"; rm -f "$J"
pass=0; fail=0
check(){ code=$(curl -sS -o /tmp/idcdns-front.out -w '%{http_code}' -b "$J" -c "$J" "$1"); if [ "$code" = 200 ] || [ "$code" = 302 ]; then echo "PASS $1 ($code)"; pass=$((pass+1)); else echo "FAIL $1 ($code)"; fail=$((fail+1)); fi; }
for u in /index.php?s=/index/index /index.php?s=/index/shop/index /index.php?s=/index/shop/domains /index.php?s=/index/shop/packages /index.php?s=/index/index/announce /index.php?s=/index/index/terms /index.php?s=/index/auth/login /index.php?s=/index/auth/register; do check "$BASE$u"; done
printf 'PASS=%s FAIL=%s\n' "$pass" "$fail"; [ "$fail" -eq 0 ]
