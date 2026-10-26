#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${IDC_PORT:-8230}"
UPPORT="${UPSTREAM_PORT:-18990}"
CFPORT="${CF_PORT:-18991}"
SITE="/tmp/idcsite_${PORT}"
DB="idcdns_e2e_${PORT}"
UPPID= CFPID=  
cleanup() {
  [ -n "${UPPID}" ] && kill "${UPPID}" 2>/dev/null || true
  [ -n "${CFPID}" ] && kill "${CFPID}" 2>/dev/null || true
}
trap cleanup EXIT

bash "$ROOT/tests/e2e/setup_site.sh" php7.0 "$PORT"
# setup_site performs an initial request, so update the actual prefixed table and
# clear the external datapath cache before any login request.
sudo mysql -e "INSERT INTO ${DB}.idc_setting(name,value) VALUES('captcha_enable','0') ON DUPLICATE KEY UPDATE value='0';"
DP="$(php7.0 -r '$x=include $argv[1]; echo $x;' "$SITE/data/datapath.php")"
if [ -d "$DP/runtime" ]; then find "$DP/runtime" -mindepth 1 -exec rm -rf {} +; fi
php7.0 -S "127.0.0.1:${UPPORT}" -t "$ROOT/tests/upstream" >"/tmp/idcdns-upstream-${UPPORT}.log" 2>&1 & UPPID=$!
php7.0 -S "127.0.0.1:${CFPORT}" -t "$ROOT/tests/dns" >"/tmp/idcdns-cf-${CFPORT}.log" 2>&1 & CFPID=$!
sleep 1
export IDC_BASE="http://127.0.0.1:${PORT}"
export IDC_DB="$DB"
export UPSTREAM_MOCK="http://127.0.0.1:${UPPORT}"
export CF_MOCK="http://127.0.0.1:${CFPORT}"
python3 "$ROOT/tests/e2e/flow_test.py"
python3 "$ROOT/tests/e2e/admin_crawl.py" "$IDC_BASE" admin Admin@12345
bash "$ROOT/tests/run_all.sh"
