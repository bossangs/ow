#!/bin/bash
# 模拟宝塔默认的 open_basedir（只允许站点目录 + /tmp，站点上级目录不可访问），
# 安装后访问前台 / 后台 / cron，确认没有 open_basedir 报错。
# 用法：SRCDIR=解压后的包目录 bash tests/e2e/open_basedir_test.sh [php二进制] [端口]
set -u
PHP=${1:-php7.0}
PORT=${2:-8472}
ROOT=/workspace/idcdns
SRCDIR=${SRCDIR:-$ROOT/src}
BASE=/home/ubuntu/obtest_$PORT
SITE=$BASE/site
DB=idcdns_e2e_$PORT
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "  PASS $1"; }
bad(){ FAIL=$((FAIL+1)); echo "  FAIL $1"; }

pkill -f "127.0.0.1:$PORT" 2>/dev/null || true
rm -rf "$BASE"; mkdir -p "$BASE"; cp -r "$SRCDIR" "$SITE"
rm -f "$SITE/data/config.php" "$SITE/data/install.lock.php"; rm -rf "$SITE/data/runtime"
sudo mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'idce2e'@'localhost' IDENTIFIED BY 'e2e_pass_123'; CREATE USER IF NOT EXISTS 'idce2e'@'127.0.0.1' IDENTIFIED BY 'e2e_pass_123'; GRANT ALL ON \`idcdns_e2e_%\`.* TO 'idce2e'@'localhost'; GRANT ALL ON \`idcdns_e2e_%\`.* TO 'idce2e'@'127.0.0.1'; FLUSH PRIVILEGES;"
OB="$SITE/:/tmp/"
cd "$SITE"
nohup $PHP -d open_basedir="$OB" -d display_errors=1 -d error_reporting=-1 -S 127.0.0.1:$PORT -t "$SITE" > "$BASE/server.log" 2>&1 &
sleep 1
J=$BASE/cookies; U=http://127.0.0.1:$PORT
TOKEN=$(curl -s -c $J -b $J "$U/install.php" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
[ -n "$TOKEN" ] && ok "安装器可打开" || bad "安装器 token"
OUT=$(curl -s -c $J -b $J "$U/install.php" \
  --data-urlencode "_token=$TOKEN" -d db_host=127.0.0.1 -d db_port=3306 -d db_prefix=idc_ -d db_name=$DB -d db_user=idce2e -d db_pass=e2e_pass_123 \
  --data-urlencode "site_name=o2o云" -d site_url=$U -d admin_user=admin -d admin_email=admin@example.com -d admin_pass=Admin@12345 -d admin_pass2=Admin@12345)
echo "$OUT" | grep -q "安装完成\|安装成功" && ok "open_basedir 下安装" || bad "安装：$(echo "$OUT" | sed 's/<[^>]*>/ /g' | tr -s ' ' | head -c 300)"

for path in "index.php" "index.php?s=/admin/login/index" "index.php?s=/index/user/login"; do
  code=$(curl -s -o "$BASE/body" -w "%{http_code}" -b $J "$U/$path")
  if grep -qi "open_basedir\|Fatal error\|系统发生错误" "$BASE/body"; then
    bad "$path 页面含错误（$(grep -oi 'open_basedir[^<]\{0,80\}\|Fatal error[^<]\{0,80\}\|系统发生错误' "$BASE/body" | head -1)）"
  elif [ "$code" = "200" ] || [ "$code" = "302" ]; then ok "$path -> $code"
  else bad "$path -> HTTP $code"; fi
done
code=$(curl -s -o /dev/null -w "%{http_code}" "$U/cron.php")
[ "$code" = "403" ] && ok "Web cron 无密钥 -> 403" || bad "Web cron 无密钥 -> $code"
# CLI 计划任务同样受 open_basedir 限制
CLI=$($PHP -d open_basedir="$OB" "$SITE/cron.php" 2>&1 | head -c 400)
echo "$CLI" | grep -qi "open_basedir\|Fatal" && bad "CLI cron：$CLI" || ok "CLI cron 无 open_basedir 报错"
grep -qi "open_basedir" "$BASE/server.log" && bad "服务器日志含 open_basedir 警告" || ok "服务器日志干净"

pkill -f "127.0.0.1:$PORT" 2>/dev/null || true
sudo mysql -e "DROP DATABASE IF EXISTS $DB;"
echo "open_basedir: $PASS passed, $FAIL failed"
[ "$FAIL" = "0" ]
