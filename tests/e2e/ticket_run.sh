#!/usr/bin/env bash
set -euo pipefail
PORT="${1:-8270}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
bash "$ROOT/tests/e2e/setup_site.sh" php7.0 "$PORT"
D=$(php7.0 -r 'echo include "/tmp/idcsite_'"$PORT"'/data/datapath.php";')
sudo mysql -e "INSERT INTO idcdns_e2e_${PORT}.idc_setting(name,value) VALUES('captcha_enable','0') ON DUPLICATE KEY UPDATE value='0';"
find "$D/runtime" -path '*cache*' -mindepth 1 -delete || true
bash "$ROOT/tests/e2e/sync_site.sh" "$PORT"
IDC_BASE="http://127.0.0.1:${PORT}" IDC_DB="idcdns_e2e_${PORT}" python3 "$ROOT/tests/e2e/ticket_test.py"
