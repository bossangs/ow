#!/usr/bin/env python3
"""品牌（Logo / favicon / 品牌邮件）端到端测试。

全部通过后台真实表单操作：默认图标与 Logo 输出、上传合法图片、拒绝伪装文件（PHP、SVG、拼接脚本、
扩展名不符、超大）、替换时删除旧文件、恢复默认，以及测试邮件走本地 SMTP 收信桩，检查 HTML 品牌模板。
用法：IDC_BASE=http://127.0.0.1:8250 IDC_DB=idcdns_e2e_8250 python3 tests/e2e/brand_test.py
"""
import email
import glob
import io
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flow_test2 as F  # noqa: E402
from PIL import Image  # noqa: E402

BASE = F.BASE
PORT = re.search(r":(\d+)$", BASE).group(1)
SITE = os.environ.get("IDC_SITE_DIR", "/tmp/idcsite_" + PORT)
SINK_PORT = int(os.environ.get("SMTP_SINK_PORT", "2526"))
SINK_DIR = "/tmp/smtp_sink_%s" % PORT
D2 = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "src", "static", "brand", "favicon.ico")
check, one, t = F.check, F.one, F.t


def png_bytes(w=240, h=64, color=(220, 30, 60, 255)):
    im = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    for x in range(10, w - 10):
        for y in range(10, h - 10):
            im.putpixel((x, y), color)
    b = io.BytesIO()
    im.save(b, "PNG")
    return b.getvalue()


def setting(name):
    return one("select value from %s where name='%s'" % (t("setting"), name))


def upload(admin, field, filename, content, mime="image/png", extra=None):
    values = dict(extra or {})
    return admin.submit("admin/settings/index", values, files={field: (filename, content, mime)})


def main():
    admin = F.UI("brand-admin")
    r, al = admin.submit("admin/login/index", {"username": "admin", "password": F.ADMIN_PW})
    check("[品牌] 后台登录", F.clean(r) and "login" not in r.url, (r.url, al))
    # 确保从默认状态开始
    for k in ("site_logo", "site_logo_light", "site_favicon"):
        if setting(k):
            admin.submit("admin/settings/index", {k + "_reset": "1"})

    # ---------- 默认品牌输出 ----------
    home = F.requests.get(BASE + "/", timeout=20).text
    check("[品牌] 前台 head 输出默认 favicon/PNG 图标/apple-touch-icon",
          "static/brand/favicon.ico" in home and "static/brand/icon-32.png" in home and "static/brand/apple-touch-icon.png" in home)
    check("[品牌] 前台页头使用内置 Logo 图片（不再是首字母方块）",
          re.search(r'<img class="site-logo" src="[^"]*static/brand/logo\.png', home) is not None and 'class="logo"' not in home)
    for path, kind in (("/static/brand/favicon.ico", "image/"), ("/static/brand/logo.png", "image/png"),
                       ("/static/brand/logo-light.png", "image/png"), ("/static/brand/apple-touch-icon.png", "image/png"),
                       ("/favicon.ico", "image/")):
        rr = F.requests.get(BASE + path, timeout=20)
        check("[品牌] 静态资源可访问 %s" % path, rr.status_code == 200 and rr.headers.get("Content-Type", "").startswith(kind) and len(rr.content) > 100,
              (rr.status_code, rr.headers.get("Content-Type")))
    adm = admin.get("admin/dashboard/index").text
    check("[品牌] 后台侧栏使用反白 Logo，页面有 favicon", "static/brand/logo-light.png" in adm and "static/brand/favicon.ico" in adm)
    lg = F.requests.get(BASE + "/index.php?s=/admin/login/index", timeout=20).text
    check("[品牌] 后台登录页显示 Logo 和 favicon", "static/brand/logo@2x.png" in lg and "static/brand/favicon.ico" in lg)
    for route in ("index/auth/login", "index/auth/register"):
        h = F.requests.get(BASE + "/index.php?s=/" + route, timeout=20).text
        check("[品牌] %s 卡片顶部显示 Logo" % route, 'class="auth-logo"' in h and "static/brand/logo@2x.png" in h)
    inst = open(os.path.join(SITE, "install.php"), encoding="utf-8").read()
    check("[品牌] 安装向导带 Logo 与 favicon", "static/brand/logo@2x.png" in inst and "static/brand/favicon.ico" in inst)
    page = admin.get("admin/settings/index").text
    check("[品牌] 设置页是 multipart 表单并有 3 个图片上传框",
          'enctype="multipart/form-data"' in page and len(re.findall(r'type="file" name="site_(logo|logo_light|favicon)"', page)) == 3)
    check("[品牌] 重新生成 cron 密钥按钮不再嵌套在设置表单里",
          'form="cronKeyForm"' in page and page.count("<form") == page.count("</form>"))

    # ---------- 拒绝各种伪装文件 ----------
    bad_cases = [
        ("PHP 代码改名为 .png", "site_logo", "logo.png", b"<?php echo 'pwned'; ?>", "image/png"),
        ("PNG 头 + 拼接 PHP", "site_logo", "logo.png", png_bytes() + b"<?php system($_GET['c']); ?>", "image/png"),
        ("SVG（可内嵌脚本）", "site_logo", "logo.svg", b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "image/svg+xml"),
        (".php 扩展名", "site_logo", "shell.php", png_bytes(), "image/png"),
        ("扩展名与真实格式不符（PNG 改 .jpg）", "site_logo", "logo.jpg", png_bytes(), "image/jpeg"),
        ("网站图标超过 256KB", "site_favicon", "fav.png", png_bytes() + b"\x00" * 300000, "image/png"),
        ("网站图标不接受 GIF", "site_favicon", "fav.gif", b"GIF89a\x01\x00\x01\x00\x00\x00\x00;", "image/gif"),
    ]
    before = set(os.listdir(os.path.join(SITE, "uploads", "brand")))
    for label, field, fn, data, mime in bad_cases:
        r, al = upload(admin, field, fn, data, mime)
        check("[品牌] 拒绝上传：%s" % label, F.clean(r) and F.danger(al) and setting(field) == "", (r.status_code, al, setting(field)))
    after = set(os.listdir(os.path.join(SITE, "uploads", "brand")))
    check("[品牌] 被拒绝的文件没有落盘", before == after, after - before)

    # ---------- 上传合法 Logo ----------
    r, al = upload(admin, "site_logo", "my-logo.png", png_bytes())
    path1 = setting("site_logo")
    check("[品牌] 上传合法 PNG Logo 成功", F.ok_alert(al) and re.match(r"^uploads/brand/site-logo-[a-f0-9]{12}\.png$", path1 or ""), (al, path1))
    check("[品牌] Logo 文件以随机名保存在 uploads/brand", path1 and os.path.isfile(os.path.join(SITE, path1)))
    home = F.requests.get(BASE + "/", timeout=20).text
    check("[品牌] 前台页头改用上传的 Logo", path1 and path1 in home, path1)
    rr = F.requests.get(BASE + "/" + path1, timeout=20)
    check("[品牌] 上传的 Logo 可通过网址访问", rr.status_code == 200 and rr.content[:8] == b"\x89PNG\r\n\x1a\n", rr.status_code)
    adm = admin.get("admin/dashboard/index").text
    check("[品牌] 未上传深色版时后台侧栏回退到上传的 Logo", path1 in adm)
    check("[品牌] 上传操作写入审计日志", one("select count(*) from %s where action='admin.settings.brand'" % t("audit_log")) not in ("", "0"))

    # 替换：旧文件被删除
    r, al = upload(admin, "site_logo", "my-logo-2.png", png_bytes(color=(20, 120, 220, 255)))
    path2 = setting("site_logo")
    check("[品牌] 替换 Logo 后旧文件被删除", F.ok_alert(al) and path2 != path1 and os.path.isfile(os.path.join(SITE, path2))
          and not os.path.exists(os.path.join(SITE, path1)), (path1, path2))

    # favicon（ICO）
    r, al = upload(admin, "site_favicon", "favicon.ico", open(D2, "rb").read(), "image/x-icon")
    fav = setting("site_favicon")
    home = F.requests.get(BASE + "/", timeout=20).text
    check("[品牌] 上传 ICO 网站图标成功并输出到 head", F.ok_alert(al) and fav.endswith(".ico") and fav in home
          and "static/brand/icon-32.png" not in home, (al, fav))

    # ---------- 品牌 HTML 邮件 ----------
    subprocess.run("fuser -k %d/tcp >/dev/null 2>&1; rm -rf %s" % (SINK_PORT, SINK_DIR), shell=True)
    env = dict(os.environ, SMTP_SINK_DIR=SINK_DIR)
    sink = subprocess.Popen([sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)), "smtp_sink.py"), str(SINK_PORT)], env=env)
    try:
        time.sleep(0.8)
        r, al = admin.submit("admin/settings/index", {"smtp_enable": "1", "smtp_host": "127.0.0.1", "smtp_port": str(SINK_PORT),
                                                      "smtp_secure": "none", "smtp_user": "", "smtp_from": "noreply@example.test",
                                                      "mail_html_enable": "1"})
        F.step("[品牌] 保存 SMTP（本地收信桩）", r, al)
        r, al = admin.post("admin/settings/testMail", {"to": "owner@example.test"}, referer=admin.url("admin/settings/index"))
        check("[品牌] 发送测试邮件成功", F.ok_alert(al), al)
        files = sorted(glob.glob(SINK_DIR + "/*.eml"))
        check("[品牌] 收信桩收到邮件", bool(files), files)
        if files:
            msg = email.message_from_bytes(open(files[-1], "rb").read())
            parts = {p.get_content_type(): p.get_payload(decode=True).decode("utf-8", "replace") for p in msg.walk() if not p.is_multipart()}
            html = parts.get("text/html", "")
            check("[品牌] 邮件是 HTML + 纯文本双版本", msg.get_content_type() == "multipart/alternative" and "text/plain" in parts and html, list(parts))
            check("[品牌] HTML 邮件顶部是上传 Logo 的绝对地址", ('src="%s/%s"' % (BASE, path2)) in html, re.findall(r'<img[^>]+>', html)[:1])
            check("[品牌] 纯文本正文保留原文", "后台 SMTP 配置测试成功" in parts.get("text/plain", ""))
        # 关闭 HTML 模板 → 纯文本
        admin.submit("admin/settings/index", {"mail_html_enable": "0"})
        admin.post("admin/settings/testMail", {"to": "owner@example.test"}, referer=admin.url("admin/settings/index"))
        files = sorted(glob.glob(SINK_DIR + "/*.eml"))
        msg = email.message_from_bytes(open(files[-1], "rb").read()) if files else None
        check("[品牌] 关闭品牌模板后发送纯文本邮件", msg is not None and msg.get_content_type() == "text/plain" and len(files) >= 2)
    finally:
        sink.terminate()
        admin.submit("admin/settings/index", {"smtp_enable": "0", "mail_html_enable": "1"})

    # ---------- 恢复默认 ----------
    r, al = admin.submit("admin/settings/index", {"site_logo_reset": "1", "site_favicon_reset": "1"})
    home = F.requests.get(BASE + "/", timeout=20).text
    check("[品牌] 恢复默认：设置清空、文件删除、前台回到内置 Logo 与图标",
          F.ok_alert(al) and setting("site_logo") == "" and setting("site_favicon") == ""
          and not os.path.exists(os.path.join(SITE, path2)) and not os.path.exists(os.path.join(SITE, fav))
          and "static/brand/logo.png" in home and "static/brand/favicon.ico" in home, (al, setting("site_logo")))

    passed = sum(1 for _, ok, _ in F.RESULTS if ok)
    print("brand_test: %d/%d passed" % (passed, len(F.RESULTS)))
    return 0 if passed == len(F.RESULTS) else 1


if __name__ == "__main__":
    sys.exit(main())
