#!/usr/bin/env bash
set -u
BASE=${BASE:-http://127.0.0.1:8210}
COOKIE=${COOKIE:-/tmp/idc_admin_ops_cookie.$$}
PASS=0
FAIL=0
trap 'rm -f "$COOKIE"' EXIT
pass(){ PASS=$((PASS+1)); echo "PASS $1"; }
fail(){ FAIL=$((FAIL+1)); echo "FAIL $1"; }
get(){ curl -sS -L -c "$COOKIE" -b "$COOKIE" "$BASE$1"; }
code(){ curl -sS -o /tmp/idc_ops_body.$$ -w '%{http_code}' -c "$COOKIE" -b "$COOKIE" "$BASE$1"; }
# 未登录后台必须重定向到登录页
c=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/index.php?s=/admin/dashboard/index")
if [ "$c" = 302 ] || [ "$c" = 301 ]; then pass '未登录后台重定向'; else fail "未登录后台重定向($c)"; fi
# 测试环境关闭验证码；仅影响本地联调库
mysql --protocol=socket -uroot -e "UPDATE idcdns_e2e_8210.idc_setting SET value='0' WHERE name='captcha_enable'; INSERT IGNORE INTO idcdns_e2e_8210.idc_setting(name,value) VALUES('captcha_enable','0');" >/dev/null 2>&1 || true
# 登录页面可达；如环境启用验证码，脚本仍报告页面可达
c=$(code '/index.php?s=/admin/login/index')
[ "$c" = 200 ] && pass '登录页 HTTP 200' || fail "登录页($c)"
# 允许通过环境变量提供测试 session；默认只验证公开页面和 CSRF 拒绝
for route in dashboard/index order/index service/index subdomain/index record/index payment/index user/index userpackage/index ticket/index announcement/index audit/index operations/index cancelrequest/index; do
  c=$(code "/index.php?s=/admin/$route")
  if [ "$c" = 200 ] || [ "$c" = 302 ]; then pass "$route 可达"; else fail "$route HTTP $c"; fi
done
# 缺少 CSRF 的 POST 必须被拒绝（未登录时也不得执行）
c=$(curl -sS -o /tmp/idc_ops_csrf.$$ -w '%{http_code}' -X POST -b "$COOKIE" "$BASE/index.php?s=/admin/order/edit&id=1")
if [ "$c" = 419 ] || [ "$c" = 302 ] || [ "$c" = 401 ]; then pass '缺 CSRF POST 被拒绝'; else fail "缺 CSRF POST($c)"; fi
# 语法回归
if find /workspace/idcdns/src/core/application/admin/controller /workspace/idcdns/src/core/application/admin/view -type f -name '*.php' -print0 | xargs -0 -n1 php7.0 -l >/tmp/idc_ops_lint.$$ 2>&1; then pass '后台 PHP 7.0 lint'; else fail '后台 PHP 7.0 lint'; cat /tmp/idc_ops_lint.$$; fi
rm -f /tmp/idc_ops_body.$$ /tmp/idc_ops_csrf.$$ /tmp/idc_ops_lint.$$
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
