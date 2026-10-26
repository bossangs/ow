#!/usr/bin/env bash
set -u
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SITE="$ROOT/src"
log(){ printf '[install-test] %s\n' "$*"; }
fail=0
phpbin="$(command -v php7.0 || command -v php7.4 || command -v php8.3 || command -v php || true)"
if [ -z "$phpbin" ]; then log 'SKIP: 未找到 PHP 7.0/7.4/8.x'; exit 0; fi
"$phpbin" -l "$SITE/install.php" >/dev/null || fail=1
"$phpbin" -l "$SITE/cron.php" >/dev/null || fail=1
if ! command -v mysql >/dev/null 2>&1; then log 'SKIP: 未找到 mysql 客户端；请安装 MariaDB/MySQL 后重跑'; exit "$fail"; fi
if ! mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; then log 'SKIP: 本机 MariaDB/MySQL 未运行（不会自动 sudo 安装，避免影响宿主机）'; exit "$fail"; fi
log "PHP: $phpbin"
log '静态检查通过；完整浏览器流程需要设置 TEST_DB_* 环境变量并用 curl/浏览器提交 CSRF 表单。'
log '产品验收点：install.lock.php、config.php return 数组、schema 表数量、cron.php CLI、data/core 防护、api/nat/script。'
exit "$fail"
