#!/bin/sh
set -eu
php7.0 -S 127.0.0.1:18991 /workspace/idcdns/tests/upstream/mock_server.php >/tmp/idcdns-upstream-mock.log 2>&1 & pid=$!
trap 'kill $pid 2>/dev/null || true' EXIT
sleep 1
php7.0 /workspace/idcdns/tests/upstream/run_tests.php
