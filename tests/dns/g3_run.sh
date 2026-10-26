#!/bin/sh
set -u
DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
PORT=18983
php7.0 -S 127.0.0.1:$PORT "$DIR/g3_mock.php" >/tmp/g3_mock.log 2>&1 &
PID=$!
trap 'kill "$PID" 2>/dev/null || true' EXIT
sleep 1
curl -fsS "http://127.0.0.1:$PORT/__reset" >/dev/null
php7.0 "$DIR/g3_vectors.php" && php7.0 "$DIR/g3_tests.php"
