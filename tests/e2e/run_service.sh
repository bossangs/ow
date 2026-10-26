#!/usr/bin/env bash
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${E2E_PORT:-8240}"
PHP="${PHP_BIN:-php7.0}"
SITE="/tmp/idcsite_${PORT}"
CF_PORT="${CF_MOCK_PORT:-18991}"
status=0
log="/tmp/idcdns_e2e_${PORT}.log"
rm -f "$log"
if ! bash "$ROOT/tests/e2e/setup_site.sh" "$PHP" "$PORT" >>"$log" 2>&1; then cat "$log"; exit 1; fi
CF_MOCK_BASE="http://127.0.0.1:${CF_PORT}"
"$PHP" -S "127.0.0.1:${CF_PORT}" "$ROOT/tests/dns/cloudflare_mock.php" >>"$log" 2>&1 &
CF_PID=$!
trap 'kill "$CF_PID" 2>/dev/null || true' EXIT
sleep 1
curl -fsS "$CF_MOCK_BASE/user/tokens/verify" -H 'Authorization: Bearer TOKENEXAMPLE' >/dev/null || true
IDC_SITE="$SITE/" CF_MOCK_BASE="$CF_MOCK_BASE" "$PHP" "$ROOT/tests/e2e/service_test.php" || status=$?
# Static security assertions: these are portable to php -S and reverse proxies.
for f in "$ROOT/src/.htaccess" "$ROOT/src/web.config" "$ROOT/src/core/.htaccess" "$ROOT/src/core/install/nginx.conf.example" "$ROOT/src/data/.htaccess"; do
  if [ ! -s "$f" ]; then echo "FAIL security rule missing: $f"; status=1; else echo "PASS security rule: ${f#$ROOT/}"; fi
done
grep -qE 'core|data' "$ROOT/src/.htaccess" && echo 'PASS root protection covers core/data' || { echo 'FAIL root protection covers core/data'; status=1; }
# Lock must reject reinstall.
if curl -fsS "http://127.0.0.1:${PORT}/install.php" | grep -q '系统已经安装'; then echo 'PASS install lock rejects reinstall'; else echo 'FAIL install lock rejects reinstall'; status=1; fi
# CLI cron is authenticated by CLI marker and must be idempotent/no fatal.
if (cd "$SITE" && "$PHP" cron.php >/tmp/idcdns_cron_${PORT}.out 2>&1) && (cd "$SITE" && "$PHP" cron.php >>/tmp/idcdns_cron_${PORT}.out 2>&1); then echo 'PASS cron CLI repeat/idempotency'; else echo 'FAIL cron CLI repeat/idempotency'; cat /tmp/idcdns_cron_${PORT}.out; status=1; fi
# All changed service/common/API/install files must remain PHP 7.0 parseable.
while IFS= read -r f; do "$PHP" -l "$f" >/dev/null || { echo "FAIL lint $f"; status=1; }; done < <(find "$ROOT/src/core/application/common" "$ROOT/src/core/application/api" -type f -name '*.php' -o -path "$ROOT/src/install.php" -o -path "$ROOT/src/cron.php")
[ "$status" -eq 0 ] && echo 'SERVICE E2E PASS' || echo 'SERVICE E2E FAIL'
exit "$status"
