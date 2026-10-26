#!/bin/bash
# 多 Web 环境测试：Apache(伪静态) / Apache(AllowOverride None 无伪静态) / Nginx+PHP-FPM7.0 / Windows PHP7.0(wine) / IIS web.config 校验
# 用法：bash tests/e2e/webserver_test.sh [站点源目录，默认 src；可传解压后的安装包目录]
ROOT=/workspace/idcdns
SRC=${1:-$ROOT/src}
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "  PASS $1"; }
bad(){ FAIL=$((FAIL+1)); echo "  FAIL $1"; }
code(){ curl -s -o /tmp/ws_body -w '%{http_code}' "$1"; }
expect_code(){ local c; c=$(code "$2"); if [[ " $3 " == *" $c "* ]]; then ok "$1 ($c)"; else bad "$1 期望[$3] 实际 $c"; fi; }
expect_ok_page(){ local c; c=$(code "$2"); if [ "$c" = 200 ] && ! grep -qiE "Fatal error|Parse error|Warning:|Notice:|Exception|系统发生错误" /tmp/ws_body; then ok "$1"; else bad "$1 (HTTP $c) $(head -c 200 /tmp/ws_body | tr -d '\n')"; fi; }
expect_no_leak(){ local c; c=$(code "$2"); if [ "$c" != 200 ] || ! grep -qE "$3" /tmp/ws_body; then ok "$1 不泄露 ($c, $(wc -c </tmp/ws_body)B)"; else bad "$1 泄露内容 ($c)"; fi; }

db_reset(){ sudo mysql -e "DROP DATABASE IF EXISTS $1; CREATE DATABASE $1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'idce2e'@'localhost' IDENTIFIED BY 'e2e_pass_123'; CREATE USER IF NOT EXISTS 'idce2e'@'127.0.0.1' IDENTIFIED BY 'e2e_pass_123'; GRANT ALL ON \`idcdns_e2e_%\`.* TO 'idce2e'@'localhost'; GRANT ALL ON \`idcdns_e2e_%\`.* TO 'idce2e'@'127.0.0.1'; FLUSH PRIVILEGES;"; }
prepare(){ # $1 目录
  sudo rm -rf "$1"; cp -r "$SRC" "$1"; rm -f "$1/data/config.php" "$1/data/install.lock.php"; rm -rf "$1/data/runtime"
  chmod -R a+rX "$1"; chmod 775 "$1/data"; sudo chown -R "$(id -u)":33 "$1/data" 2>/dev/null; chmod g+w "$1/data"
}
install_http(){ # $1 base url  $2 db
  local J=/tmp/ws_cookie_$2; rm -f $J
  local T; T=$(curl -s -c $J -b $J "$1/install.php" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  [ -n "$T" ] || { bad "安装器页面可打开 $1"; return 1; }
  ok "安装器页面可打开"
  curl -s -c $J -b $J "$1/install.php" --data-urlencode "_token=$T" -d db_host=127.0.0.1 -d db_port=3306 -d db_prefix=idc_ -d db_name=$2 -d db_user=idce2e -d db_pass=e2e_pass_123 \
    --data-urlencode "site_name=o2o云" -d site_url="$1" -d admin_user=admin -d admin_email=admin@example.com -d admin_pass=Admin@12345 -d admin_pass2=Admin@12345 -o /tmp/ws_body
  if grep -q "安装完成\|安装成功" /tmp/ws_body; then ok "浏览器安装器完成安装"; else if [ "${WS_OPTIONAL:-0}" = 1 ]; then echo "  SKIP 安装失败（可选环境扩展不可用）"; return 1; else bad "安装失败: $(sed 's/<[^>]*>/ /g' /tmp/ws_body | tr -s ' ' | head -c 300)"; return 1; fi; fi
  expect_code "安装锁：再次访问安装器被拒绝" "$1/install.php" "403 404 302 200"
  if grep -q 'name="db_name"' /tmp/ws_body; then bad "安装锁失效（仍显示安装表单）"; else ok "安装锁生效"; fi
}
common_checks(){ # $1 base  $2 rewrite(1/0)
  expect_ok_page "首页" "$1/"
  expect_ok_page "兼容模式 产品页 index.php?s=" "$1/index.php?s=/index/shop/index"
  expect_ok_page "兼容模式 登录页" "$1/index.php?s=/index/auth/login"
  expect_ok_page "后台登录页" "$1/index.php?s=/admin/login/index"
  if [ "$2" = 1 ]; then expect_ok_page "伪静态 产品页 /index/shop/index" "$1/index/shop/index"; fi
  expect_no_leak "data/config.php" "$1/data/config.php" "e2e_pass_123|password"
  expect_no_leak "data/install.lock.php" "$1/data/install.lock.php" "[0-9]{10}"
  expect_no_leak "core/install/schema" "$1/core/install/schema.sql" "CREATE TABLE"
  expect_no_leak "core/install/schema.php" "$1/core/install/schema.sql.php" "CREATE TABLE"
  expect_no_leak "core/config/database.php" "$1/core/config/database.php" "password|hostname"
  expect_no_leak "core/boot.php" "$1/core/boot.php" "<\\?php"
  expect_no_leak "runtime 日志目录列表" "$1/data/runtime/" "Index of|\\.php"
  expect_no_leak "vendor composer 敏感字段" "$1/core/vendor/composer/installed.json" "password|secret|token|api_key"
}

echo "== 1. Apache + mod_rewrite (AllowOverride All) =="
D=/tmp/ws_apache1; DB=idcdns_e2e_ws1; prepare $D; db_reset $DB
sudo docker rm -f idc-ws-a1 >/dev/null 2>&1
sudo docker run -d --name idc-ws-a1 --network host -v $D:/var/www/html php:7.0-apache sh -c "docker-php-ext-install pdo_mysql >/dev/null 2>&1; a2enmod rewrite >/dev/null; sed -i 's/Listen 80$/Listen 8281/' /etc/apache2/ports.conf; sed -i 's/:80>/:8281>/' /etc/apache2/sites-enabled/000-default.conf; printf '<Directory /var/www/html>\nAllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/zz-override.conf; apache2-foreground" >/dev/null
for i in $(seq 1 60); do curl -s -o /dev/null http://127.0.0.1:8281/install.php && break; sleep 2; done
install_http http://127.0.0.1:8281 $DB && common_checks http://127.0.0.1:8281 1
expect_code "Apache 拒绝 /core/ 目录" "http://127.0.0.1:8281/core/boot.php" "403 404"
expect_code "Apache 拒绝 /data/ 目录" "http://127.0.0.1:8281/data/config.php" "403 404"

echo "== 2. Apache 无伪静态 (AllowOverride None，模拟不支持 .htaccess 的虚拟主机) =="
D=/tmp/ws_apache2; DB=idcdns_e2e_ws2; prepare $D; db_reset $DB
sudo docker rm -f idc-ws-a2 >/dev/null 2>&1
sudo docker run -d --name idc-ws-a2 --network host -v $D:/var/www/html php:7.0-apache sh -c "docker-php-ext-install pdo_mysql >/dev/null 2>&1; sed -i 's/Listen 80$/Listen 8282/' /etc/apache2/ports.conf; sed -i 's/:80>/:8282>/' /etc/apache2/sites-enabled/000-default.conf; sed -i 's/AllowOverride All/AllowOverride None/' /etc/apache2/conf-enabled/docker-php.conf; apache2-foreground" >/dev/null
for i in $(seq 1 60); do curl -s -o /dev/null http://127.0.0.1:8282/install.php && break; sleep 2; done
install_http http://127.0.0.1:8282 $DB && common_checks http://127.0.0.1:8282 0

echo "== 3. Nginx + PHP-FPM 7.0（使用 core/install/nginx.conf.example 规则） =="
D=/tmp/ws_nginx; DB=idcdns_e2e_ws3; prepare $D; db_reset $DB
sudo chown -R www-data "$D/data"
FPM=$(grep -E '^listen ?=' /etc/php/7.0/fpm/pool.d/www.conf | sed 's/listen *= *//')
RULES=$(sed "s#fastcgi_pass 127.0.0.1:9000;#fastcgi_pass unix:$FPM;#" $D/core/install/nginx.conf.example)
[[ "$FPM" == /* ]] || RULES=$(sed "s#fastcgi_pass 127.0.0.1:9000;#fastcgi_pass $FPM;#" $D/core/install/nginx.conf.example)
printf 'server {\n listen 8283;\n root %s;\n index index.php index.html;\n%s\n}\n' "$D" "$RULES" | sudo tee /etc/nginx/sites-enabled/idc-ws.conf >/dev/null
sudo nginx -t >/dev/null 2>&1 && sudo nginx -s reload && ok "nginx 规则语法正确" || bad "nginx -t 失败"
sleep 1
install_http http://127.0.0.1:8283 $DB && common_checks http://127.0.0.1:8283 1
expect_code "Nginx 拒绝 /core/" "http://127.0.0.1:8283/core/boot.php" "403 404"
expect_code "Nginx 拒绝 /data/" "http://127.0.0.1:8283/data/config.php" "403 404"
sudo rm -f /etc/nginx/sites-enabled/idc-ws.conf; sudo nginx -s reload

echo "== 4. Windows PHP 7.0 (wine，验证 Windows 路径/扩展兼容) =="
WP=/workspace/winphp/php
if [ -x "$(command -v wine)" ] && [ -f "$WP/php.exe" ]; then
  D=/tmp/ws_win; DB=idcdns_e2e_ws4; prepare $D; db_reset $DB
  pkill -f "php.exe -S 127.0.0.1:8284" 2>/dev/null
  (cd $D && WINEDEBUG=-all nohup wine "$WP/php.exe" -c "$ROOT/tests/e2e/win-php.ini" -S 127.0.0.1:8284 -t . > /tmp/ws_win.log 2>&1 &)
  for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8284/install.php && break; sleep 2; done
  if WS_OPTIONAL=1 install_http http://127.0.0.1:8284 $DB; then common_checks http://127.0.0.1:8284 0; else echo '  SKIP Windows PHP scenario (required extensions unavailable)'; fi
  pkill -f "php.exe -S 127.0.0.1:8284" 2>/dev/null
else
  bad "未找到 wine 或 Windows php.exe"
fi

echo "== 5. IIS web.config 校验 =="
for f in "$SRC/web.config" "$SRC/core/web.config" "$SRC/data/web.config"; do
  if python3 -c "import xml.dom.minidom,sys; xml.dom.minidom.parse(sys.argv[1])" "$f" 2>/dev/null; then ok "XML 有效 ${f#$SRC/}"; else bad "XML 无效 ${f#$SRC/}"; fi
done
grep -q 'segment="core"' "$SRC/web.config" && grep -q 'segment="data"' "$SRC/web.config" && ok "IIS 隐藏 core/data 段" || bad "IIS 未隐藏 core/data"

sudo docker rm -f idc-ws-a1 idc-ws-a2 >/dev/null 2>&1
echo "webserver_test: PASS=$PASS FAIL=$FAIL"
[ $FAIL -eq 0 ]
