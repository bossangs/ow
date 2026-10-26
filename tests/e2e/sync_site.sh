#!/bin/bash
# 把 src 的代码同步到已安装的测试站点（不覆盖 data/ 和安装锁）
PORT=${1:-8194}
rsync -a --delete src/core/application/ /tmp/idcsite_$PORT/core/application/
rsync -a src/static/ /tmp/idcsite_$PORT/static/
rsync -a src/core/install/ /tmp/idcsite_$PORT/core/install/
cp src/core/boot.php src/core/polyfill.php /tmp/idcsite_$PORT/core/ 2>/dev/null
cp src/install.php src/cron.php src/index.php /tmp/idcsite_$PORT/ 2>/dev/null
mkdir -p /tmp/idcsite_$PORT/uploads/brand
rsync -a src/uploads/ /tmp/idcsite_$PORT/uploads/
cp src/favicon.ico /tmp/idcsite_$PORT/ 2>/dev/null
mkdir -p /tmp/idcsite_$PORT/core/tools && rsync -a src/core/tools/ /tmp/idcsite_$PORT/core/tools/
