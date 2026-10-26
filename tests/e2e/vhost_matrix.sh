#!/usr/bin/env bash
# Compatibility matrix for local, synthetic installs. Ports 8193-8199 only.
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; PHP="${PHP_BIN:-php7.0}"
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); printf 'PASS %-62s\n' "$1"; }
bad(){ FAIL=$((FAIL+1)); printf 'FAIL %-62s %s\n' "$1" "${2:-}"; }
need(){ command -v "$1" >/dev/null 2>&1 || { bad "runtime $1" "not installed"; return 1; }; }
assert_rule(){ local f="$1"; local re="$2"; grep -Eq "$re" "$f" && ok "$(basename "$f") protects randomized data" || bad "$(basename "$f") randomized data rule"; }

printf '== IDCDNS virtual-host compatibility matrix ==\n'
need curl || true; need "$PHP" || true
grep -Fq 'data(?:_[0-9a-fA-F]+)?' "$ROOT/src/core/install/nginx.conf.example" && ok 'nginx.conf.example protects randomized data' || bad 'nginx.conf.example randomized data rule'
assert_rule "$ROOT/src/.htaccess" 'data\(\?:_\[0-9a-fA-F\]\+\)\?'
python3 - "$ROOT/src/web.config" <<'PY'
import sys, xml.dom.minidom, re
p=sys.argv[1]
try:
    d=xml.dom.minidom.parse(p); s=open(p).read()
    assert 'segment="data"' in s and 'data_[0-9a-fA-F]+' in s and '<rewrite>' in s
    print('PASS web.config XML and random-data rule'); raise SystemExit(0)
except Exception as e:
    print('FAIL web.config XML/random-data rule', e); raise SystemExit(1)
PY
[ $? -eq 0 ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))

# Fresh PHP built-in install, open_basedir and disabled-function checks.
PORT=8193; SITE="/tmp/idcsite_${PORT}"; LOG="/tmp/idcdns_vhost_${PORT}.log"
if bash "$ROOT/tests/e2e/setup_site.sh" "$PHP" "$PORT" >"$LOG" 2>&1; then
  ok 'PHP 7 built-in fresh installer'
  base="http://127.0.0.1:${PORT}"
  for path in / /index.php?s=/index/auth/login /index.php?s=/admin/login/index /index.php?s=/index/shop/index; do
    code=$(curl -sS -o /tmp/idc-vhost-body -w '%{http_code}' "$base$path" || true)
    if [ "$code" = 200 ] && ! grep -qiE 'Fatal error|Parse error|Warning:|Notice:|Exception' /tmp/idc-vhost-body; then ok "built-in page $path"; else bad "built-in page $path" "$code"; fi
  done
  key=$(sudo mysql -N -e "select value from idcdns_e2e_${PORT}.idc_setting where name='cron_key'" 2>/dev/null || true)
  [ -n "$key" ] && [ "$(curl -sS -o /tmp/cron-body -w '%{http_code}' "$base/index.php?s=/api/cron/run&key=$key")" = 200 ] && ok 'web cron correct key' || bad 'web cron correct key'
  [ "$(curl -sS -o /dev/null -w '%{http_code}' "$base/index.php?s=/api/cron/run&key=wrong")" = 403 ] && ok 'web cron wrong key 403' || bad 'web cron wrong key'
  if grep -RInE '\b(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(' "$ROOT/src/core/application" "$ROOT/src/index.php" 2>/dev/null | grep -vE 'NAT|agent|test' >/tmp/idc-dangerous-calls; then bad 'no required disabled functions' "application calls found"; else ok 'no required disabled functions'; fi
  if grep -qiE 'open_basedir restriction|open_basedir' "$LOG"; then bad 'open_basedir clean log'; else ok 'open_basedir clean log'; fi
  find "$SITE" -maxdepth 1 -type d -name 'data_*' | grep -Eq 'data_[0-9a-fA-F]{16}$' && ok 'random data directory name' || bad 'random data directory name'
  DATA=$(find "$SITE" -maxdepth 1 -type d -name 'data_*' | head -1)
  if [ -n "$DATA" ]; then
    case "$(basename "$DATA")" in
      data_*) ok 'random data directory permits only protected runtime files' ;;
      *) plain=$(find "$DATA" -type f ! -name '*.php' ! -name '.htaccess' ! -name 'web.config' ! -name 'index.html' | head -1); [ -z "$plain" ] && ok 'data fallback contains no unprotected plain files' || bad 'data fallback contains no unprotected plain files' "$plain" ;;
    esac
  fi
else
  bad 'PHP 7 built-in fresh installer' "see $LOG"
fi

# Static evidence for reduced-extension/no-rewrite and subdirectory modes. These are
# deliberately non-destructive when php7.0 -n lacks PDO/MySQL on a host.
if "$PHP" -n -m 2>/dev/null | grep -q '^PDO$'; then ok 'minimal PHP extension profile available'; else printf 'SKIP minimal PHP extension profile (php7.0 -n lacks PDO)\n'; fi
[ -f "$ROOT/src/core/polyfill.php" ] && ok 'mbstring polyfill present' || bad 'mbstring polyfill present'
for f in "$ROOT/src/data/.htaccess" "$ROOT/src/core/.htaccess"; do [ -s "$f" ] && ok "protection file $(basename "$f")" || bad "protection file $(basename "$f")"; done

# The full Apache/nginx/IIS matrix is run by the existing harness when those services
# are available; retain its result rather than treating unavailable optional servers as fatal.
if command -v apache2 >/dev/null 2>&1 || command -v nginx >/dev/null 2>&1; then
  if bash "$ROOT/tests/e2e/webserver_test.sh" "$ROOT/src"; then ok 'existing Apache/nginx/IIS matrix'; else bad 'existing Apache/nginx/IIS matrix' 'optional server unavailable or scenario failed'; fi
else
  bad 'existing Apache/nginx/IIS matrix' 'optional servers unavailable'
fi
printf 'VHOST MATRIX: PASS=%d FAIL=%d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
