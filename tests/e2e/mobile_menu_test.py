#!/usr/bin/env python3
"""移动端后台菜单：打开侧栏后菜单在遮罩上方、能点进页面；点遮罩能关闭。
用法：python3 tests/e2e/mobile_menu_test.py http://127.0.0.1:8250"""
import sys
from playwright.sync_api import sync_playwright
BASE = sys.argv[1].rstrip("/")
ok = True
with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    pg = ctx.new_page()
    pg.goto(BASE + "/index.php?s=/admin/login/index")
    pg.fill("input[name=username]", "admin"); pg.fill("input[name=password]", "Admin@12345")
    pg.click("form button"); pg.wait_for_load_state("networkidle")
    pg.click("#sideToggle"); pg.wait_for_timeout(400)
    link = pg.locator("#adminSide nav a", has_text="订单管理").first
    box = link.bounding_box()
    top = pg.evaluate("([x,y]) => { const e = document.elementFromPoint(x,y); return e && e.closest('#adminSide') ? 'side' : (e ? e.id || e.className : '') }",
                      [box["x"] + 20, box["y"] + box["height"] / 2])
    print(("PASS" if top == "side" else "FAIL") + " 打开菜单后菜单项在遮罩上方 (%s)" % top); ok &= top == "side"
    try:
        with pg.expect_navigation(timeout=8000):
            link.tap()
    except Exception as e:
        print("navigation wait:", str(e)[:120])
    pg.wait_for_load_state("networkidle")
    r = "admin/order" in pg.url
    print(("PASS" if r else "FAIL") + " 点菜单项进入订单管理 (%s)" % pg.url); ok &= r
    pg.click("#sideToggle"); pg.wait_for_timeout(300)
    pg.mouse.click(370, 400); pg.wait_for_timeout(300)
    closed = not pg.evaluate("document.getElementById('adminSide').classList.contains('open')")
    print(("PASS" if closed else "FAIL") + " 点遮罩关闭菜单"); ok &= closed
    b.close()
sys.exit(0 if ok else 1)
