#!/usr/bin/env python3
"""两步验证 / 找回密码页面截图（桌面 + 移动端）。会在测试站点上真实走一遍绑定流程，结束后用命令行工具关闭。
用法：IDC_DB=idcdns_e2e_8250 python3 tests/e2e/shots_2fa.py http://127.0.0.1:8250 shots/security
"""
import os
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
os.environ.setdefault("IDC_BASE", sys.argv[1])
import flow_test2 as F  # noqa: E402
import twofa_test as T  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

BASE = sys.argv[1].rstrip("/")
OUT = sys.argv[2]
os.makedirs(OUT, exist_ok=True)
UNAME, UPASS = "safeuser", "Safe@User2026"


def u(path):
    return BASE + "/index.php?s=/" + path


def shot(page, name, full=True):
    page.wait_for_load_state("networkidle")
    txt = page.inner_text("body")
    assert "Fatal error" not in txt and "系统发生错误" not in txt, name
    page.screenshot(path=os.path.join(OUT, name + ".png"), full_page=full)
    print("shot", name)


def secret_of(page):
    return page.input_value("input.font-monospace").replace(" ", "")


def main():
    T.sql("delete from %s" % F.t("auth_guard"))
    T.cli("reset-2fa", "admin")
    T.sql("delete from %s where username='%s'" % (F.t("user"), UNAME))
    with sync_playwright() as p:
        b = p.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox"])
        # ---------- 后台 ----------
        ctx = b.new_context(viewport={"width": 1366, "height": 860}, locale="zh-CN")
        pg = ctx.new_page()
        pg.goto(u("admin/login/index"))
        shot(pg, "01-admin-login-forgot-link", full=False)
        pg.fill("input[name=username]", "admin")
        pg.fill("input[name=password]", F.ADMIN_PW)
        pg.click("form button[type=submit], form button")
        pg.wait_for_load_state("networkidle")
        pg.goto(u("admin/profile/twofa"))
        shot(pg, "02-admin-2fa-bind-qrcode")
        sec = secret_of(pg)
        pg.fill("form[action*='profile/enable'] input[name=code]", T.fresh_code(sec, "admin", 1))
        pg.fill("form[action*='profile/enable'] input[name=password]", F.ADMIN_PW)
        pg.click("form[action*='profile/enable'] button")
        shot(pg, "03-admin-2fa-recovery-codes")
        pg.goto(u("admin/settings/index"))
        pg.wait_for_load_state("networkidle")
        tab = pg.locator("text=登录安全").first
        if tab.count():
            tab.click()
            time.sleep(0.3)
        pg.evaluate("() => document.querySelector('input[name=admin_2fa_required]') && document.querySelector('input[name=admin_2fa_required]').scrollIntoView({block: 'center'})")
        shot(pg, "04-admin-settings-login-security", full=False)
        pg.goto(u("admin/login/sudo"))
        shot(pg, "05-admin-sudo-confirm", full=False)
        pg.goto(u("admin/admin/index"))
        shot(pg, "06-admin-list-reset-2fa", full=False)
        ctx.close()

        ctx = b.new_context(viewport={"width": 1366, "height": 860}, locale="zh-CN")
        pg = ctx.new_page()
        pg.goto(u("admin/login/index"))
        pg.fill("input[name=username]", "admin")
        pg.fill("input[name=password]", F.ADMIN_PW)
        pg.click("form button")
        shot(pg, "07-admin-login-step2-code", full=False)
        pg.goto(u("admin/login/forgot"))
        shot(pg, "08-admin-forgot-password", full=False)
        ctx.close()

        # ---------- 前台 ----------
        ctx = b.new_context(viewport={"width": 1366, "height": 860}, locale="zh-CN")
        pg = ctx.new_page()
        pg.goto(u("index/auth/register"))
        pg.fill("input[name=username]", UNAME)
        pg.fill("input[name=email]", UNAME + "@example.test")
        pg.fill("input[name=password]", UPASS)
        pg.fill("input[name=password2]", UPASS)
        pg.click("form button:not([type=button])")
        pg.wait_for_load_state("networkidle")
        uid = int(F.one("select id from %s where username='%s'" % (F.t("user"), UNAME)) or 0)
        assert uid, "register failed: " + pg.url
        pg.goto(u("index/user/security"))
        shot(pg, "09-user-security-bind")
        sec = secret_of(pg)
        pg.fill("form[action*='twofaenable' i] input[name=code]", T.fresh_code(sec, "user", uid))
        pg.fill("form[action*='twofaenable' i] input[name=password]", UPASS)
        pg.click("form[action*='twofaenable' i] button")
        shot(pg, "10-user-security-recovery-codes")
        pg.goto(u("index/auth/logout"))
        pg.goto(u("index/auth/login"))
        pg.fill("input[name=account]", UNAME)
        pg.fill("input[name=password]", UPASS)
        pg.click("form button:not([type=button])")
        shot(pg, "11-user-login-step2-code", full=False)
        ctx.close()

        ctx = b.new_context(viewport={"width": 1366, "height": 860}, locale="zh-CN")
        pg = ctx.new_page()
        pg.goto(u("index/auth/forgot"))
        shot(pg, "12-user-forgot-password", full=False)
        ctx.close()

        # ---------- 移动端 ----------
        m = p.devices["iPhone 13"]
        ctx = b.new_context(**{k: v for k, v in m.items() if k != "default_browser_type"}, locale="zh-CN")
        pg = ctx.new_page()
        pg.goto(u("index/auth/login"))
        pg.fill("input[name=account]", UNAME)
        pg.fill("input[name=password]", UPASS)
        pg.click("form button:not([type=button])")
        pg.wait_for_load_state("networkidle")
        shot(pg, "13-mobile-login-step2", full=False)
        pg.fill("input[name=code]", T.fresh_code(sec, "user", uid))
        pg.click("form button:not([type=button])")
        pg.wait_for_load_state("networkidle")
        pg.goto(u("index/user/security"))
        shot(pg, "14-mobile-user-security")
        ctx.close()
        b.close()
    T.cli("reset-2fa", "admin")
    T.sql("delete from %s" % F.t("auth_guard"))


if __name__ == "__main__":
    main()
