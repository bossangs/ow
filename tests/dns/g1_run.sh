#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
php7.0 -S 127.0.0.1:18981 tests/dns/g1_mock.php >/tmp/g1_mock.log 2>&1 &
pid=$!
trap 'kill "$pid" 2>/dev/null || true' EXIT
sleep 1
curl -s http://127.0.0.1:18981/__reset >/dev/null
php7.0 tests/dns/g1_tests.php
