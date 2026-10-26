#!/usr/bin/env bash
# 生成安装包：ZIP 根目录就是网站文件（直接解压到网站根目录，再访问 /install.php）。
# 用法：bash tools/build_package.sh [版本号]   输出：dist/o2o-idc-dns-<版本>.zip 和 .sha256
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VER="${1:-$(date +%Y%m%d)}"
NAME="o2o-idc-dns-$VER"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$ROOT/dist"
rsync -a \
  --exclude 'data/config.php' --exclude 'data/install.lock*' --exclude 'data/datapath.php' --exclude 'data/runtime/' \
  --exclude 'core/datapath.php' --exclude 'uploads/brand/*' --include 'uploads/brand/index.html' \
  --exclude '*.log' --exclude '.DS_Store' --exclude '*.bak' --exclude '*~' --exclude '.git*' \
  "$ROOT/src/" "$WORK/site/"
# 上传目录保留占位文件
mkdir -p "$WORK/site/uploads/brand"; cp "$ROOT/src/uploads/brand/index.html" "$WORK/site/uploads/brand/" 2>/dev/null || true
cp "$ROOT/docs/INSTALL.md" "$WORK/site/INSTALL.md" 2>/dev/null || true
# 统一权限：目录 755、文件 644（不使用 777）
find "$WORK/site" -type d -exec chmod 755 {} +
find "$WORK/site" -type f -exec chmod 644 {} +
rm -f "$ROOT/dist/$NAME.zip"
(cd "$WORK/site" && zip -qr -X "$ROOT/dist/$NAME.zip" .)
(cd "$ROOT/dist" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")
echo "$ROOT/dist/$NAME.zip"
unzip -l "$ROOT/dist/$NAME.zip" | tail -1
cat "$ROOT/dist/$NAME.zip.sha256"
