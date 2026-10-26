#!/usr/bin/env python3
"""快速预览截图：后台 + 前台 + 移动端各几张，自动遮挡密钥类输入框。
用法：python3 tests/e2e/preview_shots.py http://127.0.0.1:8250 /workspace/idcdns/preview
"""
import os
import sys

from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip("/")
OUT = sys.argv[2]
os.makedirs(OUT, exist_ok=True)

MASK_JS = """
() => {
  const re = /(token|secret|key|pass|pwd|api_|sign|private|credential)/i;
  document.querySelectorAll('input, textarea').forEach(el => {
    const n = (el.name || '') + ' ' + (el.id || '');
    if ((re.test(n) || el.type === 'password') && el.type !== 'hidden' && el.value) {
      el.type = 'text'; el.value = '•••••• 已遮挡';
    }
  });
  document.querySelectorAll('code, .mono, td, dd').forEach(el => {
    if (el.children.length === 0) {
      el.textContent = el.textContent.replace(/\\b[A-Za-z0-9_\\-]{32,}\\b/g, '•••••• 已遮挡');
    }
  });
}
"""


def u(path):
    return BASE + "/index.php?s=/" + path


ADMIN = [
    ("01-admin-dashboard", "admin/dashboard/index"),
    ("02-admin-product-edit", "admin/product/edit&id=1"),
    ("03-admin-order-list", "admin/order/index"),
    ("04-admin-order-detail-actions", "admin/order/detail&id=2"),
    ("05-admin-dns-provider-settings", "admin/dnsprovider/edit&id=1"),
    ("06-admin-market-review", "admin/market/review&id=2"),
]
USER = [
    ("07-user-center", "index/user/index"),
    ("08-storefront-product-order", "index/shop/product&id=1", "pwgen"),
    ("09-user-ticket-detail", "index/ticket/detail&id=1"),
    ("11-storefront-list", "index/shop/index"),
    ("12-storefront-soldout", "index/shop/product&id=2"),
    ("13-user-orders", "index/order/index"),
    ("14-user-hosts", "index/host/index"),
]


def shoot(page, items):
    for item in items:
        name, path = item[0], item[1]
        mode = item[2] if len(item) > 2 else ""
        page.goto(u(path), wait_until="networkidle")
        if mode == "pwgen":
            # 演示随机生成的 root 密码与强度条（演示值，不是真实密码）
            page.click("[data-pw-generate]")
            page.wait_for_timeout(900)  # 等强度条动画结束
            page.evaluate("window.scrollTo(0, 0)")
        else:
            page.evaluate(MASK_JS)
        out = os.path.join(OUT, name + ".png")
        page.screenshot(path=out, full_page=True)
        print(out)


with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1366, "height": 860})
    page = ctx.new_page()
    # 品牌：未登录页面
    shoot(page, [("16-brand-admin-login", "admin/login/index"), ("17-brand-front-home", "index/index/index"),
                 ("18-brand-front-login", "index/auth/login")])
    page.goto(u("admin/login/index"))
    page.fill("input[name=username]", "admin")
    page.fill("input[name=password]", "Admin@12345")
    page.click("button[type=submit], form button")
    page.wait_for_load_state("networkidle")
    shoot(page, ADMIN)
    shoot(page, [("20-brand-settings-basic", "admin/settings/index")])
    # 品牌邮件：取本地收信桩最后一封 HTML 邮件渲染（Logo 换成内置图，避免引用测试中已删除的上传文件）
    import email as _email, glob as _glob, re as _re
    emls = sorted(_glob.glob("/tmp/smtp_sink_*/*.eml"))
    for f in reversed(emls):
        m = _email.message_from_bytes(open(f, "rb").read())
        html = [x.get_payload(decode=True).decode("utf-8") for x in m.walk() if x.get_content_type() == "text/html"]
        if html:
            h = _re.sub(r'src="[^"]*uploads/brand/[^"]+"', 'src="%s/static/brand/logo@2x.png"' % BASE, html[0])
            page.set_viewport_size({"width": 760, "height": 600})
            page.set_content(h, wait_until="networkidle")
            page.screenshot(path=os.path.join(OUT, "21-brand-email.png"), full_page=True)
            print(os.path.join(OUT, "21-brand-email.png"))
            page.set_viewport_size({"width": 1366, "height": 860})
            break

    uctx = b.new_context(viewport={"width": 1366, "height": 860})
    upage = uctx.new_page()
    upage.goto(u("index/auth/login"))
    upage.fill("input[name=account]", "buyer1")
    upage.fill("input[name=password]", "Buyer@12345")
    upage.click("form button")
    upage.wait_for_load_state("networkidle")
    shoot(upage, USER)

    mctx = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True)
    mctx.add_cookies(uctx.cookies())
    mpage = mctx.new_page()
    shoot(mpage, [("10-mobile-user-dns", "index/dns/index"), ("15-mobile-product", "index/shop/product&id=1", "pwgen"),
                  ("19-brand-mobile-home", "index/index/index")])
    b.close()
