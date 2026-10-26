#!/usr/bin/env bash
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
rm -f /tmp/dns_capture.jsonl
php7.0 -S 127.0.0.1:18995 tests/dns/capture_server.php >/tmp/idcdns_dns_capture.log 2>&1 &
CAP_PID=$!
trap 'kill "$CAP_PID" 2>/dev/null || true' EXIT
sleep 1
php7.0 tests/dns/capture_drive.php || exit $?
if [ -x /tmp/sdkv/bin/python ]; then
    . /tmp/sdkv/bin/activate
    python tests/dns/sdk_crosscheck.py || exit $?
else
    echo "SKIP sdk_crosscheck: /tmp/sdkv is unavailable"
fi
kill "$CAP_PID" 2>/dev/null || true
trap - EXIT
php7.0 -S 127.0.0.1:18990 tests/dns/mock_server.php >/tmp/idcdns_dns_mock.log 2>&1 &
PID=$!
trap 'kill "$PID" 2>/dev/null || true' EXIT
sleep 1
curl -fsS 'http://127.0.0.1:18990/__reset' >/dev/null
python3 tests/dns/sdk_crosscheck.py || exit $?
find src/core/application/common/dns tests/dns -type f -name '*.php' -print0 | xargs -0 -n1 php7.0 -l >/tmp/idcdns_dns_lint.log || { cat /tmp/idcdns_dns_lint.log; exit 1; }
php7.0 tests/dns/run_tests.php
php7.0 tests/dns/cloudflare_run.sh || exit $?
