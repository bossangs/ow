#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
TD=$(mktemp -d)
PID=''
cleanup() {
    test -z "$PID" || kill "$PID" 2>/dev/null || true
    rm -rf "$TD"
}
trap cleanup EXIT
export IDCNAT_TEST_TOKEN='local-test-token'
export IDCNAT_TEST_STATE="$TD/state.json"
export IDCNAT_TEST_PORT=19184
python3 "$ROOT/tests/whmcs_nat/mock_agent.py" >"$TD/mock.log" 2>&1 &
PID=$!
sleep 0.3
if ! kill -0 "$PID" 2>/dev/null; then
    cat "$TD/mock.log" >&2
    exit 1
fi
php7.0 -l "$ROOT/whmcs/modules/servers/idcnat/idcnat.php"
php7.0 "$ROOT/tests/whmcs_nat/module_test.php"
php -l "$ROOT/whmcs/modules/servers/idcnat/idcnat.php"
php "$ROOT/tests/whmcs_nat/module_test.php"
