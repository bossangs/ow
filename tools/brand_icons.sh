#!/usr/bin/env bash
# 用一套图标文件替换系统内置的默认网站图标（favicon / 应用图标）。
# 用法：bash tools/brand_icons.sh <图标目录>
# 目录里需要：favicon.ico icon-16.png icon-32.png icon-48.png icon-192.png icon-512.png apple-touch-icon.png
# 方形图标 icon.png 与深色背景版 icon-light.png（256px）由 icon-512.png 自动生成（若目录里已有同名文件则直接使用）。
set -euo pipefail
SRC="${1:?用法：bash tools/brand_icons.sh <图标目录>}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DST="$ROOT/src/static/brand"
for f in favicon.ico icon-16.png icon-32.png icon-48.png icon-192.png icon-512.png apple-touch-icon.png; do
  [ -f "$SRC/$f" ] || { echo "缺少 $SRC/$f"; exit 1; }
done
for f in favicon.ico icon-16.png icon-32.png icon-48.png icon-192.png icon-512.png apple-touch-icon.png; do
  cp "$SRC/$f" "$DST/$f"
done
cp "$SRC/favicon.ico" "$ROOT/src/favicon.ico"
python3 - "$SRC" "$DST" <<'PY'
import os, sys
from PIL import Image
src, dst = sys.argv[1], sys.argv[2]
def save_small(im, path):
    im.save(path, optimize=True)
    q = im.quantize(colors=128, method=Image.FASTOCTREE, dither=Image.NONE)
    q.save("/tmp/_bi.png", optimize=True)
    if os.path.getsize("/tmp/_bi.png") < os.path.getsize(path) * 0.8:
        os.replace("/tmp/_bi.png", path)
for name in ("icon.png", "icon-light.png"):
    if os.path.isfile(os.path.join(src, name)):
        save_small(Image.open(os.path.join(src, name)).convert("RGBA"), os.path.join(dst, name))
        continue
    # 方形图标 256px；深色背景版默认与之相同（蓝底圆角方块在深浅背景上都清楚）
    im = Image.open(os.path.join(src, "icon-512.png")).convert("RGBA").resize((256, 256), Image.LANCZOS)
    save_small(im, os.path.join(dst, name))
for name in ("icon-192.png", "icon-512.png", "apple-touch-icon.png"):
    p = os.path.join(dst, name)
    save_small(Image.open(p).convert("RGBA"), p)
print("ok")
PY
ls -la "$DST" | awk '{print $5, $9}'
echo "已替换。测试站点同步：bash tests/e2e/sync_site.sh <端口>；浏览器可能缓存旧图标，强制刷新即可。"
