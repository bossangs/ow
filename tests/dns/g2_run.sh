#!/bin/sh
set -u
cd "$(dirname "$0")/../.."
/usr/bin/php7.0 -S 127.0.0.1:18982 tests/dns/g2_mock.php >/tmp/g2_mock.log 2>&1 &
pid=$!
trap 'kill $pid 2>/dev/null || true' EXIT
sleep 1
curl -fsS 'http://127.0.0.1:18982/__reset' >/dev/null
/usr/bin/php7.0 tests/dns/g2_vectors.php || exit $?
/usr/bin/php7.0 tests/dns/g2_tests.php
