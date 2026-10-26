#!/bin/bash
# NAT 节点后台端到端测试：搭建全新站点 → 运行 nat_node_test.py（Debian 12 容器里跑真实安装命令，DRY_RUN）
# 用法：bash tests/e2e/nat_node_run.sh [php二进制] [端口]   环境变量 SRCDIR 可指定解压后的安装包目录
PHP=${1:-php7.0}
PORT=${2:-8480}
ROOT=/workspace/idcdns
# 准备 Debian 12 测试镜像（已存在则跳过；无 docker 时测试会退回本机运行）
if command -v docker >/dev/null 2>&1 && ! sudo docker image inspect o2o-nat-debian12:test >/dev/null 2>&1; then
  D=$(mktemp -d)
  printf 'FROM debian:12\nRUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends curl ca-certificates python3 openssl iproute2 >/dev/null && apt-get clean\n' > "$D/Dockerfile"
  sudo docker build -q -t o2o-nat-debian12:test "$D" >/dev/null 2>&1 || echo "WARN 无法构建 Debian 镜像，退回本机运行"
fi
bash "$ROOT/tests/e2e/setup_site.sh" "$PHP" "$PORT" | tail -1 || exit 1
IDC_BASE=http://127.0.0.1:$PORT IDC_DB=idcdns_e2e_$PORT IDC_PHP=$PHP python3 "$ROOT/tests/e2e/nat_node_test.py"
R=$?
pkill -f "127.0.0.1:$PORT" 2>/dev/null || true
exit $R
