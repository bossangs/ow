#!/usr/bin/env python3
"""截图脚本：登录后台和前台用户，按清单截取桌面端与移动端页面。
用法：python3 tests/e2e/shots.py <base_url> <输出目录> <清单名> [用户名 密码]
清单名：admin / front / mobile / all
"""
import os
import re
import sys

from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip("/")
OUT = sys.argv[2]
WHICH = sys.argv[3] if len(sys.argv) > 3 else "all"
USER = sys.argv[4] if len(sys.argv) > 4 else "demo"
UPASS = sys.argv[5] if len(sys.argv) > 5 else "Demo@12345"
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
    ("admin-01-dashboard", "admin/dashboard/index"),
    ("admin-02-orders", "admin/order/index"),
    ("admin-03-services", "admin/service/index"),
    ("admin-04-users", "admin/user/index"),
    ("admin-05-products", "admin/product/index"),
    ("admin-06-product-edit", "admin/product/edit&id=1"),
    ("admin-07-upstream", "admin/upstream/index"),
    ("admin-08-upstream-edit", "admin/upstream/edit&id=1"),
    ("admin-09-dns-providers", "admin/dnsprovider/index"),
    ("admin-10-dnsprovider-add", "admin/dnsprovider/edit"),
    ("admin-11-domains", "admin/domain/index"),
    ("admin-12-subdomains", "admin/subdomain/index"),
    ("admin-13-tiers", "admin/tier/index"),
    ("admin-14-packages", "admin/package/index"),
    ("admin-15-gateways", "admin/gateway/index"),
    ("admin-16-gateway-epay", "admin/gateway/edit&id=epay"),
    ("admin-17-coupons", "admin/coupon/index"),
    ("admin-18-cards", "admin/card/index"),
    ("admin-19-market", "admin/market/index"),
    ("admin-20-market-sales", "admin/market_sale/index"),
    ("admin-21-withdraw", "admin/withdraw/index"),
    ("admin-22-risk", "admin/risk/index"),
    ("admin-23-audit", "admin/audit/index"),
    ("admin-24-settings", "admin/settings/index"),
    ("admin-25-nat-nodes", "admin/nat_node/index"),
    ("admin-26-tickets", "admin/ticket/index"),
    ("admin-27-ticket-detail", "admin/ticket/detail&id=1"),
    ("admin-28-ticket-canned", "admin/ticket/canned"),
    ("admin-29-satisfaction", "admin/satisfaction/index"),
    ("admin-30-order-detail", "admin/order/detail&id=2"),
    ("admin-31-market-review", "admin/market/review&id=2"),
]
FRONT_PUBLIC = [
    ("front-01-home", ""),
    ("front-02-shop", "index/shop/index"),
    ("front-03-domains", "index/shop/domains"),
    ("front-04-packages", "index/shop/packages"),
    ("front-05-register", "index/auth/register"),
    ("front-06-login", "index/auth/login"),
]
FRONT_USER = [
    ("front-10-user-home", "index/user/index"),
    ("front-11-hosts", "index/host/index"),
    ("front-12-host-detail", "index/host/detail&id=1"),
    ("front-13-orders", "index/order/index"),
    ("front-14-subdomains", "index/dns/index"),
    ("front-15-dns-register", "index/dns/register"),
    ("front-16-records", "index/dns/records&id=1"),
    ("front-17-market", "index/market/index"),
    ("front-18-balance", "index/user/balance"),
    ("front-19-card", "index/user/card"),
    ("front-20-recharge", "index/user/recharge"),
    ("front-21-tiers", "index/user/tiers"),
    ("front-22-tickets", "index/ticket/index"),
    ("front-23-ticket-create", "index/ticket/create"),
    ("front-24-ticket-detail", "index/ticket/detail&id=1"),
    ("front-25-product-order", "index/shop/product&id=1"),
]


def login_admin(page):
    page.goto(u("admin/login/index"))
    page.fill("input[name=username]", "admin")
    page.fill("input[name=password]", "Admin@12345")
    page.click("form button")
    page.wait_for_load_state("networkidle")


def login_user(page):
    page.goto(u("index/auth/login"))
    page.fill("input[name=account], input[name=username], input[name=email]", USER)
    page.fill("input[name=password]", UPASS)
    page.click("form button")
    page.wait_for_load_state("networkidle")


def shoot(page, items, prefix=""):
    bad = []
    for name, path in items:
        page.goto(u(path) if path else BASE + "/")
        page.wait_for_load_state("networkidle")
        page.evaluate(MASK_JS)
        txt = page.inner_text("body")
        if re.search(r"Fatal error|Warning:|Notice:|页面不存在|系统发生错误", txt):
            bad.append(name)
        page.screenshot(path=os.path.join(OUT, prefix + name + ".png"), full_page=True)
    return bad


with sync_playwright() as p:
    b = p.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox"])
    bad = []
    if WHICH in ("admin", "all"):
        ctx = b.new_context(viewport={"width": 1440, "height": 900}, locale="zh-CN")
        pg = ctx.new_page()
        login_admin(pg)
        bad += shoot(pg, ADMIN)
        ctx.close()
    if WHICH in ("front", "all"):
        ctx = b.new_context(viewport={"width": 1366, "height": 860}, locale="zh-CN")
        pg = ctx.new_page()
        bad += shoot(pg, FRONT_PUBLIC)
        login_user(pg)
        bad += shoot(pg, FRONT_USER)
        ctx.close()
    if WHICH in ("mobile", "all"):
        m = dict(p.devices["iPhone 13"])
        m.pop("default_browser_type", None)
        ctx = b.new_context(**m, locale="zh-CN")
        pg = ctx.new_page()
        bad += shoot(pg, [FRONT_PUBLIC[0], FRONT_PUBLIC[1], FRONT_PUBLIC[4]], "mobile-")
        login_user(pg)
        bad += shoot(pg, [FRONT_USER[0], FRONT_USER[2], FRONT_USER[4], FRONT_USER[6], FRONT_USER[7]], "mobile-")
        ctx.close()
        ctx = b.new_context(**m, locale="zh-CN")
        pg = ctx.new_page()
        login_admin(pg)
        bad += shoot(pg, [ADMIN[0], ADMIN[1], ADMIN[4]], "mobile-")
        pg.click("#sideToggle")
        pg.wait_for_timeout(400)
        pg.screenshot(path=os.path.join(OUT, "mobile-admin-menu.png"))
        ctx.close()
    b.close()
    print("screenshots:", len(os.listdir(OUT)), "problems:", bad)
