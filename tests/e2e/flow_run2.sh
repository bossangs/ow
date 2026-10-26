#!/usr/bin/env bash
# 全新安装一个测试站点，启动本地 mock（WHMCS + 有状态 Cloudflare），运行 flow_test2.py
set -Eeuo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${PHP:-php7.0}"
PORT="${IDC_PORT:-8250}"
UPPORT="${UPSTREAM_PORT:-18990}"
CFPORT="${CF_PORT:-18992}"
SITE="/tmp/idcsite_${PORT}"
DB="idcdns_e2e_${PORT}"
export CF_STATE="/tmp/idcdns_cf_state_${PORT}.json"
rm -f "$CF_STATE" "$CF_STATE.lock" /tmp/idcdns_upstream_state*.json 2>/dev/null || true
PIDS=()
cleanup() { for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null || true; done; }
trap cleanup EXIT
fuser -k "${UPPORT}/tcp" "${CFPORT}/tcp" >/dev/null 2>&1 || true
bash "$ROOT/tests/e2e/setup_site.sh" "$PHP" "$PORT" >/tmp/flow2_setup.log 2>&1 || { tail -20 /tmp/flow2_setup.log; exit 1; }
sudo mysql -e "INSERT INTO ${DB}.idc_setting(name,value) VALUES('captcha_enable','0') ON DUPLICATE KEY UPDATE value='0';"
DP="$($PHP -r '$x=include $argv[1]; echo $x;' "$SITE/data/datapath.php")"
[ -d "$DP/runtime" ] && find "$DP/runtime" -mindepth 1 -path "*cache*" -exec rm -rf {} + 2>/dev/null || true
$PHP -S "127.0.0.1:${UPPORT}" "$ROOT/tests/upstream/mock_server.php" >"/tmp/flow2-upstream.log" 2>&1 & PIDS+=($!)
CF_STATE="$CF_STATE" $PHP -S "127.0.0.1:${CFPORT}" "$ROOT/tests/e2e/mock_cf_state.php" >"/tmp/flow2-cf.log" 2>&1 & PIDS+=($!)
sleep 1
IDC_BASE="http://127.0.0.1:${PORT}" IDC_DB="$DB" UPSTREAM_MOCK="http://127.0.0.1:${UPPORT}" CF_MOCK="http://127.0.0.1:${CFPORT}" \
  python3 "$ROOT/tests/e2e/flow_test2.py"
echo "== 品牌（Logo / favicon / 上传校验 / 品牌邮件） =="
IDC_BASE="http://127.0.0.1:${PORT}" IDC_DB="$DB" IDC_SITE_DIR="$SITE" \
  python3 "$ROOT/tests/e2e/brand_test.py"
echo "== 移动端后台菜单 =="
python3 "$ROOT/tests/e2e/mobile_menu_test.py" "http://127.0.0.1:${PORT}"
echo "== 两步验证 / 找回密码 / 应急工具 =="
IDC_BASE="http://127.0.0.1:${PORT}" IDC_DB="$DB" IDC_SITE_DIR="$SITE" IDC_PHP="$PHP" \
  python3 "$ROOT/tests/e2e/twofa_test.py"
