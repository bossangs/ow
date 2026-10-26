#!/bin/bash
# 搭建本地联调站点：复制 src → /tmp/idcsite，PHP 7.0 内置服务器 :8090，自动跑安装器
# 用法：bash tests/e2e/setup_site.sh [php二进制] [端口]
set -e
PHP=${1:-php7.0}
PORT=${2:-8190}
ROOT=/workspace/idcdns
SRCDIR=${SRCDIR:-$ROOT/src}
SITE=/tmp/idcsite_$PORT
DB=idcdns_e2e_$PORT
pkill -f "127.0.0.1:$PORT" 2>/dev/null || true
rm -rf "$SITE"; cp -r "$SRCDIR" "$SITE"
rm -f "$SITE/data/config.php" "$SITE/data/install.lock.php"; rm -rf "$SITE/data/runtime"
sudo mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'idce2e'@'localhost' IDENTIFIED BY 'e2e_pass_123'; CREATE USER IF NOT EXISTS 'idce2e'@'127.0.0.1' IDENTIFIED BY 'e2e_pass_123'; GRANT ALL ON \`idcdns_e2e_%\`.* TO 'idce2e'@'localhost'; GRANT ALL ON \`idcdns_e2e_%\`.* TO 'idce2e'@'127.0.0.1'; FLUSH PRIVILEGES;"
cd "$SITE"
nohup $PHP -S 127.0.0.1:$PORT -t "$SITE" > /tmp/idcsite_$PORT.log 2>&1 &
sleep 1
J=/tmp/idcsite_$PORT.cookies; rm -f $J
TOKEN=$(curl -s -c $J -b $J "http://127.0.0.1:$PORT/install.php" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
[ -n "$TOKEN" ] || { echo "无法获取安装器 token"; exit 1; }
OUT=$(curl -s -c $J -b $J "http://127.0.0.1:$PORT/install.php" \
  --data-urlencode "_token=$TOKEN" -d db_host=127.0.0.1 -d db_port=3306 -d db_prefix=idc_ -d db_name=$DB -d db_user=idce2e -d db_pass=e2e_pass_123 \
  --data-urlencode "site_name=o2o云" -d site_url=http://127.0.0.1:$PORT -d admin_user=admin -d admin_email=admin@example.com -d admin_pass=Admin@12345 -d admin_pass2=Admin@12345)
echo "$OUT" | grep -q "安装完成\|安装成功" && echo "INSTALL OK" || { echo "$OUT" | sed 's/<[^>]*>/ /g' | tr -s ' ' | head -c 1500; exit 1; }
echo "tables: $(sudo mysql -N -e "select count(*) from information_schema.tables where table_schema='$DB'")"
echo "site: http://127.0.0.1:$PORT  db: $DB  admin/Admin@12345 (仅本地测试)"
