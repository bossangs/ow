#!/usr/bin/env bash
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
pass=0; fail=0
run() { local f="$1"; if [ -x "$f" ] || [ -f "$f" ]; then echo "=== ${f#$ROOT/} ==="; if bash "$f"; then pass=$((pass+1)); else fail=$((fail+1)); fi; fi; }
# 已安装的 HTTP 测试站点先同步最新代码，避免旧代码造成误报
for p in 8230; do [ -d "/tmp/idcsite_$p" ] && (cd "$ROOT" && bash tests/e2e/sync_site.sh "$p"); done
for f in "$ROOT"/tests/upstream/run.sh "$ROOT"/tests/dns/run.sh "$ROOT"/tests/dns/*_run.sh "$ROOT"/tests/nat/run.sh "$ROOT"/tests/install/run.sh "$ROOT"/tests/twofa/run.sh "$ROOT"/tests/e2e/http_*.sh; do [ -f "$f" ] && run "$f"; done
run "$ROOT/tests/e2e/run_service.sh"
run "$ROOT/tests/e2e/ticket_run.sh"
run "$ROOT/tests/e2e/flow_run2.sh"
run "$ROOT/tests/e2e/open_basedir_test.sh"
run "$ROOT/tests/e2e/captcha_test.sh"
run "$ROOT/tests/e2e/nat_node_run.sh"
echo "TEST SUITES: $pass passed, $fail failed"
exit "$fail"
