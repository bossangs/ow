#!/usr/bin/env bash
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
PORT="${CF_MOCK_PORT:-18991}"
php7.0 -S 127.0.0.1:"$PORT" tests/dns/cloudflare_mock.php >/tmp/idcdns_cloudflare_mock.log 2>&1 &
PID=$!
trap 'kill "$PID" 2>/dev/null || true' EXIT
sleep 1
curl -fsS "http://127.0.0.1:$PORT/__reset" >/dev/null 2>&1 || true
CF_MOCK_BASE="http://127.0.0.1:$PORT" php7.0 tests/dns/cloudflare_test.php
status=$?
kill "$PID" 2>/dev/null || true
trap - EXIT
exit "$status"
