#!/usr/bin/env python3
"""把 flow_test2.py 保存的页面快照（SNAP_DIR/*.html）渲染成 PNG 截图。
每个快照是测试执行到该步骤时服务器真实返回的页面；静态资源通过 <base href> 从测试站点加载。
用法：python3 tests/e2e/shots_snap.py <快照目录> <输出目录> [宽度=1366] [文件名后缀]
"""
import glob
import os
import sys

from playwright.sync_api import sync_playwright

SRC = sys.argv[1]
OUT = sys.argv[2]
WIDTH = int(sys.argv[3]) if len(sys.argv) > 3 else 1366
SUFFIX = sys.argv[4] if len(sys.argv) > 4 else ""
os.makedirs(OUT, exist_ok=True)

files = sorted(glob.glob(os.path.join(SRC, "*.html")))
with sync_playwright() as p:
    b = p.chromium.launch()
    mobile = WIDTH < 600
    ctx = b.new_context(viewport={"width": WIDTH, "height": 844 if mobile else 900},
                        device_scale_factor=2 if mobile else 1, is_mobile=mobile, has_touch=mobile)
    page = ctx.new_page()
    for f in files:
        name = os.path.splitext(os.path.basename(f))[0]
        with open(f, encoding="utf-8") as fh:
            html = fh.read()
        page.set_content(html, wait_until="networkidle")
        page.wait_for_timeout(200)
        out = os.path.join(OUT, name + SUFFIX + ".png")
        page.screenshot(path=out, full_page=True)
        print("saved", out)
    b.close()
