#!/bin/bash
set -u
PORT=${1:-8231}
BASE="http://127.0.0.1:${PORT}"
COOKIE="/tmp/idcdns-front-shop-${PORT}.cookie"
OUT="/tmp/idcdns-front-shop-${PORT}.out"
rm -f "$COOKIE" "$OUT"
pass=0
fail=0
check() {
    local path="$1"
    local code
    code=$(curl -sS -o "$OUT" -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" "$BASE$path")
    if { [ "$code" = 200 ] || [ "$code" = 302 ]; } && ! grep -Eiq 'fatal error|parse error|uncaught exception|warning:' "$OUT"; then
        echo "PASS $path ($code)"
        pass=$((pass + 1))
    else
        echo "FAIL $path ($code)"
        fail=$((fail + 1))
    fi
}

for path in \
    /index.php?s=/index/index \
    /index.php?s=/index/shop/index \
    /index.php?s=/index/shop/domains \
    /index.php?s=/index/shop/packages \
    /index.php?s=/index/index/announce \
    /index.php?s=/index/index/help \
    /index.php?s=/index/index/terms \
    /index.php?s=/index/index/abuse \
    /index.php?s=/index/auth/login \
    /index.php?s=/index/auth/register; do
    check "$path"
done

csrf=$(curl -sS -b "$COOKIE" -c "$COOKIE" "$BASE/index.php?s=/index/auth/login" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
if [ -n "$csrf" ]; then
    code=$(curl -sS -o "$OUT" -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" -X POST "$BASE/index.php?s=/index/index/abuse" -d 'target=test')
    if [ "$code" = 419 ]; then echo "PASS missing CSRF rejected (419)"; pass=$((pass + 1)); else echo "FAIL missing CSRF accepted ($code)"; fail=$((fail + 1)); fi
else
    echo "FAIL could not read CSRF token"
    fail=$((fail + 1))
fi

printf 'PASS=%s FAIL=%s\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
