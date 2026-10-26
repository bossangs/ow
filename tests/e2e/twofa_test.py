#!/usr/bin/env python3
"""两步验证（谷歌验证器 TOTP）与找回密码 端到端测试。
全部通过真实页面表单操作：后台/前台绑定（二维码可被解码且与密钥一致）、恢复码只显示一次、
登录第二步、防重放、恢复码一次性、错误锁定与命令行解锁、敏感操作二次验证、强制绑定、
超管重置他人、找回密码（验证码/链接，2FA 不可绕过，改密后旧会话失效）、密码强度、用户登录限流。
邮件走本地 SMTP 收信桩。
用法：IDC_BASE=http://127.0.0.1:8250 IDC_DB=idcdns_e2e_8250 python3 tests/e2e/twofa_test.py
"""
import base64
import email
import glob
import hashlib
import hmac
import os
import re
import struct
import subprocess
import sys
import time
from email import policy

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flow_test2 as F  # noqa: E402
from bs4 import BeautifulSoup  # noqa: E402

BASE = F.BASE
PORT = re.search(r":(\d+)$", BASE).group(1)
SITE = os.environ.get("IDC_SITE_DIR", "/tmp/idcsite_" + PORT)
PHP = os.environ.get("IDC_PHP", "php7.0")
SINK_PORT = int(os.environ.get("SMTP_SINK_PORT", "2527"))
SINK_DIR = "/tmp/smtp_sink_2fa_%s" % PORT
check, one, t = F.check, F.one, F.t
ADMIN_EMAIL = "admin@example.com"


# ---------------- TOTP（独立实现，用于核对服务端） ----------------
def totp(secret, step=None):
    key = base64.b32decode(secret.replace(" ", "").upper() + "=" * ((8 - len(secret.replace(" ", "")) % 8) % 8))
    if step is None:
        step = int(time.time()) // 30
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    o = h[-1] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def fresh_code(secret, table, uid):
    """返回一个服务端还没用过、且在 ±1 窗口内的验证码（必要时等到下一个 30 秒）"""
    while True:
        last = int(one("select totp_last_step from %s where id=%d" % (t(table), uid)) or 0)
        cur = int(time.time()) // 30
        for s in (cur - 1, cur, cur + 1):
            if s > last and (s >= cur or time.time() % 30 < 25):
                return totp(secret, s)
        time.sleep(31 - time.time() % 30)


def sql(q):
    subprocess.run(["sudo", "mysql", F.DB, "-e", q], check=True)


def clear_cache():
    dp = subprocess.run([PHP, "-r", '$x = include "data/datapath.php"; echo is_string($x) ? $x : "";'], cwd=SITE, capture_output=True, text=True).stdout.strip()
    if dp:
        subprocess.run("sudo rm -rf %s/runtime/cache/*" % dp, shell=True)


def cli(*args):
    p = subprocess.run([PHP, "core/tools/admin_reset.php"] + list(args), cwd=SITE, capture_output=True, text=True)
    return p.returncode, p.stdout + p.stderr


def sink_files():
    return sorted(glob.glob(SINK_DIR + "/*.eml"))


def last_mail(after_count, to=None, timeout=10):
    end = time.time() + timeout
    while time.time() < end:
        files = sink_files()
        for f in reversed(files[after_count:]):
            m = email.message_from_bytes(open(f, "rb").read(), policy=policy.default)
            if to and to.lower() not in str(m.get("To", "")).lower():
                continue
            part = m.get_body(preferencelist=("plain", "html"))
            return m, part.get_content() if part else ""
        time.sleep(0.3)
    return None, ""


def page_secret(html):
    soup = BeautifulSoup(html, "html.parser")
    el = soup.select_one("input.font-monospace")
    return el.get("value", "").replace(" ", "") if el else ""


def recovery_codes(html):
    soup = BeautifulSoup(html, "html.parser")
    return [c.get_text(strip=True) for c in soup.select(".recovery-codes code")]


def qr_payload(html):
    """把页面里的 SVG 二维码栅格化后用 zxing 解码，确认扫码内容正确"""
    try:
        import zxingcpp
        from PIL import Image
    except Exception:
        return None
    m = re.search(r'viewBox="0 0 (\d+) \1".*?<path[^>]*d="([^"]+)"', html, re.S)
    if not m:
        return ""
    n = int(m.group(1))
    sc = 8
    im = Image.new("L", (n * sc, n * sc), 255)
    for x, y in re.findall(r"M(\d+) (\d+)h1v1h-1z", m.group(2)):
        x, y = int(x), int(y)
        for dx in range(sc):
            for dy in range(sc):
                im.putpixel((x * sc + dx, y * sc + dy), 0)
    res = zxingcpp.read_barcodes(im)
    return res[0].text if res else ""


def login_admin(label, pw):
    ui = F.UI(label)
    r, al = ui.submit("admin/login/index", {"username": "admin", "password": pw})
    return ui, r, al


def main():
    sql("delete from %s" % t("auth_guard"))
    # 每个邮箱每小时最多 5 封找回邮件：反复跑测试时先清掉之前的发送记录（仅测试库）
    sql("delete from %s where action like 'reset\\_send\\_%%' or action='password.reset_request'" % t("audit_log"))
    rc, out = cli("reset-2fa", "admin")
    check("[2FA] 命令行工具可运行", rc == 0, out)
    sql("update %s set login_fail=0, lock_until=0 where username='admin'" % t("admin"))
    subprocess.run("fuser -k %d/tcp >/dev/null 2>&1; rm -rf %s" % (SINK_PORT, SINK_DIR), shell=True)
    sink = subprocess.Popen([sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)), "smtp_sink.py"), str(SINK_PORT)],
                            env=dict(os.environ, SMTP_SINK_DIR=SINK_DIR))
    time.sleep(0.6)
    admin_pw = F.ADMIN_PW
    try:
        admin, r, al = login_admin("2fa-admin", admin_pw)
        check("[2FA] 后台密码登录（未开启 2FA 直接进入）", F.clean(r) and "login" not in r.url, (r.url, al))
        r, al = admin.submit("admin/settings/index", {"smtp_enable": "1", "smtp_host": "127.0.0.1", "smtp_port": str(SINK_PORT), "smtp_secure": "none",
                                                      "smtp_user": "", "smtp_from": "noreply@example.test", "smtp_from_name": "o2o云",
                                                      "admin_reset_method": "code", "reset_method": "code", "admin_sudo_minutes": "1", "admin_2fa_required": "0"})
        check("[2FA] 设置页「登录安全」可保存", F.clean(r) and one("select value from %s where name='admin_sudo_minutes'" % t("setting")) == "1", al)

        # ---------- 后台绑定 ----------
        r = admin.get("admin/profile/twofa")
        secret = page_secret(r.text)
        check("[2FA] 绑定页显示本地二维码 SVG 与 Base32 密钥", "<svg" in r.text and re.fullmatch(r"[A-Z2-7]{32}", secret or "") is not None, secret)
        check("[2FA] 页面不引用外部二维码服务", not re.search(r"chart\.googleapis|api\.qrserver|quickchart|qr\.[a-z]+\.com", r.text))
        payload = qr_payload(r.text)
        if payload is not None:
            check("[2FA] 二维码可被 zxing 解码且内容为 otpauth URI（含密钥/发行方/SHA1/6 位/30 秒）",
                  payload.startswith("otpauth://totp/") and ("secret=" + secret) in payload and "issuer=" in payload and "digits=6" in payload and "period=30" in payload, payload)
        r2 = admin.get("admin/profile/twofa")
        check("[2FA] 刷新页面密钥不变（确认前不入库）", page_secret(r2.text) == secret and int(one("select totp_enabled from %s where id=1" % t("admin"))) == 0)
        r, al = admin.submit("admin/profile/twofa", {"code": "000000" if totp(secret) != "000000" else "111111", "password": admin_pw}, match="admin/profile/enable")
        check("[2FA] 错误验证码不能开启", int(one("select totp_enabled from %s where id=1" % t("admin"))) == 0 and any(k == "danger" for k, _ in al), al)
        r, al = admin.submit("admin/profile/twofa", {"code": totp(secret), "password": "wrong-Pass1"}, match="admin/profile/enable")
        check("[2FA] 登录密码错误不能开启", int(one("select totp_enabled from %s where id=1" % t("admin"))) == 0, al)
        r, al = admin.submit("admin/profile/twofa", {"code": fresh_code(secret, "admin", 1), "password": admin_pw}, match="admin/profile/enable")
        codes = recovery_codes(r.text)
        check("[2FA] 正确验证码开启成功并显示 10 个恢复码", int(one("select totp_enabled from %s where id=1" % t("admin"))) == 1 and len(codes) == 10, (al, codes))
        stored = one("select totp_secret from %s where id=1" % t("admin"))
        check("[2FA] 数据库里的密钥已加密（不是明文）", stored and secret not in stored)
        check("[2FA] 恢复码只存哈希", int(one("select count(*) from %s where owner_type='admin' and owner_id=1 and used_time=0" % t("twofa_recovery"))) == 10
              and int(one("select count(*) from %s where code_hash in ('%s')" % (t("twofa_recovery"), "','".join(codes)))) == 0)
        dl = admin.get("admin/profile/codes")
        check("[2FA] 恢复码可下载为 txt", "attachment" in dl.headers.get("Content-Disposition", "") and all(c in dl.text for c in codes), dl.headers)
        dl2 = admin.get("admin/profile/codes")
        check("[2FA] 恢复码下载后不再显示", not all(c in dl2.text for c in codes) and not recovery_codes(admin.get("admin/profile/twofa").text))
        m, body = last_mail(0, ADMIN_EMAIL)
        check("[2FA] 开启后给管理员发提醒邮件", m is not None and "两步验证" in str(m["Subject"]) + body, m and m["Subject"])

        # ---------- 登录第二步 ----------
        a2, r, al = login_admin("2fa-admin-b", admin_pw)
        check("[2FA] 密码正确后进入两步验证页（未登录）", "admin/login/twofa" in r.url and 'name="code"' in r.text, r.url)
        check("[2FA] 未完成第二步不能访问后台", "admin/login" in a2.get("admin/dashboard/index").url)
        enable_code = totp(secret, int(one("select totp_last_step from %s where id=1" % t("admin"))))
        r, al = a2.submit("admin/login/twofa", {"code": enable_code})
        check("[2FA] 已用过的验证码不能重放", "admin/login/twofa" in r.url and any(k == "danger" for k, _ in al), (r.url, al))
        r, al = a2.submit("admin/login/twofa", {"code": fresh_code(secret, "admin", 1)})
        check("[2FA] 正确验证码完成登录", F.clean(r) and "dashboard" in r.url, (r.url, al))

        # 恢复码登录（一次性）
        a3, r, al = login_admin("2fa-admin-c", admin_pw)
        r, al = a3.submit("admin/login/twofa", {"code": codes[0]})
        check("[2FA] 恢复码可以登录并提示剩余数量", "dashboard" in r.url and any("9" in m for _, m in al), (r.url, al))
        a4, r, al = login_admin("2fa-admin-d", admin_pw)
        r, al = a4.submit("admin/login/twofa", {"code": codes[0]})
        check("[2FA] 同一个恢复码不能用第二次", "admin/login/twofa" in r.url, (r.url, al))

        # 错误锁定 + 命令行解锁
        for i in range(5):
            a4.submit("admin/login/twofa", {"code": "%06d" % ((int(totp(secret)) + 500000 + i) % 1000000)})
        r, al = a4.submit("admin/login/twofa", {"code": fresh_code(secret, "admin", 1)})
        check("[2FA] 连续输错 5 次后锁定（正确验证码也拒绝）", "dashboard" not in r.url and any("次数过多" in m or "锁定" in m for _, m in al), al)
        rc, out = cli("unlock", "admin")
        check("[2FA] 命令行 unlock 解除锁定", rc == 0 and "已解除" in out, out)
        a4, r, al = login_admin("2fa-admin-d2", admin_pw)
        r, al = a4.submit("admin/login/twofa", {"code": fresh_code(secret, "admin", 1)})
        check("[2FA] 解锁后可以正常登录", "dashboard" in r.url, (r.url, al))

        # ---------- 敏感操作二次验证 ----------
        print("  等待二次验证窗口过期（admin_sudo_minutes=1）...", flush=True)
        time.sleep(62)
        slogan = "sudo-test-%d" % int(time.time())
        r, al = a4.submit("admin/settings/index", {"site_slogan": slogan})
        check("[2FA] 超过免验证时长后，保存系统设置要先输入验证码", "admin/login/sudo" in r.url and one("select value from %s where name='site_slogan'" % t("setting")) != slogan, r.url)
        r, al = a4.submit("admin/login/sudo", {"code": "123456" if totp(secret) != "123456" else "654321"})
        check("[2FA] 二次验证输错不放行", "admin/login/sudo" in r.url)
        r, al = a4.submit("admin/login/sudo", {"code": fresh_code(secret, "admin", 1)})
        check("[2FA] 二次验证通过后回到原页面", "admin/settings" in r.url, r.url)
        r, al = a4.submit("admin/settings/index", {"site_slogan": slogan})
        check("[2FA] 验证后可以保存敏感设置", one("select value from %s where name='site_slogan'" % t("setting")) == slogan, al)
        check("[2FA] 二次验证写入审计日志", int(one("select count(*) from %s where action='admin.sudo'" % t("audit_log"))) >= 1)

        # ---------- 强制绑定 + 超管限制 + 重置他人 ----------
        sql("delete from %s where username='ops2fa'" % t("admin"))
        r, al = a4.submit("admin/admin/edit", {"username": "ops2fa", "email": "ops2fa@example.test", "password": "123", "status": "1", "is_super": "0"})
        check("[2FA] 新建管理员拒绝弱密码", not one("select id from %s where username='ops2fa'" % t("admin")), al)
        r, al = a4.submit("admin/admin/edit", {"username": "ops2fa", "email": "ops2fa@example.test", "password": "Ops2fa@Pass9", "status": "1", "is_super": "0"})
        oid = one("select id from %s where username='ops2fa'" % t("admin"))
        check("[2FA] 超管可新建普通管理员（密码为哈希）", oid and one("select password from %s where id=%s" % (t("admin"), oid)).startswith("$2y$"), al)
        a4.submit("admin/settings/index", {"admin_2fa_required": "1"})
        check("[2FA] 开启强制两步验证", one("select value from %s where name='admin_2fa_required'" % t("setting")) == "1")
        op = F.UI("2fa-ops")
        r, al = op.submit("admin/login/index", {"username": "ops2fa", "password": "Ops2fa@Pass9"})
        check("[2FA] 强制开启后，未绑定的管理员登录被带到绑定页", "admin/profile/twofa" in r.url, r.url)
        check("[2FA] 未绑定前不能使用后台其他功能", "admin/profile/twofa" in op.get("admin/order/index").url)
        osec = page_secret(op.get("admin/profile/twofa").text)
        r, al = op.submit("admin/profile/twofa", {"code": fresh_code(osec, "admin", int(oid)), "password": "Ops2fa@Pass9"}, match="admin/profile/enable")
        check("[2FA] 普通管理员绑定成功后可进入后台", int(one("select totp_enabled from %s where id=%s" % (t("admin"), oid))) == 1 and "admin/order" in op.get("admin/order/index").url)
        check("[2FA] 普通管理员看不到「管理员」菜单与登录安全设置",
              'admin/admin/index' not in op.get("admin/dashboard/index").text and 'name="admin_2fa_required"' not in op.get("admin/settings/index").text)
        check("[2FA] 普通管理员不能打开管理员管理", op.get("admin/admin/index").status_code == 403)
        r = op.post("admin/admin/reset2fa", {"id": "1"})
        check("[2FA] 普通管理员不能重置别人的两步验证", int(one("select totp_enabled from %s where id=1" % t("admin"))) == 1)
        r, al = a4.post("admin/admin/reset2fa", {"id": str(oid)}, referer=BASE + "/index.php?s=/admin/admin/index")
        check("[2FA] 超管可以重置其他管理员的两步验证并留审计", int(one("select totp_enabled from %s where id=%s" % (t("admin"), oid))) == 0
              and int(one("select count(*) from %s where action like '2fa.admin.%%' and target like '%%ops2fa%%'" % t("audit_log"))) >= 1, al)
        a4.submit("admin/settings/index", {"admin_2fa_required": "0"})
        sql("delete from %s where username='ops2fa'" % t("admin"))

        # ---------- 后台找回密码：验证码 ----------
        anon = F.UI("2fa-forgot")
        n0 = len(sink_files())
        r, al = anon.submit("admin/login/forgot", {"email": "nobody@example.test"}, match=lambda f: f.find("input", {"name": "act", "value": "send"}) is not None)
        generic = " ".join(m for _, m in al)
        time.sleep(1)
        check("[找回] 不存在的邮箱也返回同样提示且不发信", len(sink_files()) == n0 and generic, al)
        n0 = len(sink_files())
        r, al = anon.submit("admin/login/forgot", {"email": ADMIN_EMAIL}, match=lambda f: f.find("input", {"name": "act", "value": "send"}) is not None)
        check("[找回] 存在的邮箱提示与不存在时一致", " ".join(m for _, m in al) == generic, (generic, al))
        m, body = last_mail(n0, ADMIN_EMAIL)
        code = re.search(r"\b(\d{6})\b", body or "")
        check("[找回] 收到 6 位验证码邮件", code is not None, body[:200])
        code = code.group(1) if code else ""
        check("[找回] 数据库只存验证码哈希", int(one("select count(*) from %s where scene='admin_reset' and code='%s'" % (t("verify_code"), code))) == 0)
        r, al = anon.submit("admin/login/forgot", {"email": ADMIN_EMAIL}, match=lambda f: f.find("input", {"name": "act", "value": "send"}) is not None)
        check("[找回] 60 秒内重复发送被限制", any("频繁" in m or "秒" in m for _, m in al) or len(sink_files()) == n0 + 1, al)
        old_session = a4
        reset = lambda extra: anon.submit("admin/login/forgot", dict({"email": ADMIN_EMAIL, "code": code}, **extra), match=lambda f: f.find("input", {"name": "act", "value": "reset"}) is not None)
        r, al = reset({"password": "Weakpass", "password2": "Weakpass", "twofa_code": fresh_code(secret, "admin", 1)})
        check("[找回] 弱密码被拒绝", any(k == "danger" for k, _ in al), al)
        r, al = reset({"password": "NewAdm1n@2026", "password2": "NewAdm1n@2026", "twofa_code": ""})
        check("[找回] 开启 2FA 的账号不输谷歌验证码不能重置（邮箱被盗也不行）", any("两步验证" in m for _, m in al) and login_admin("2fa-chk", admin_pw)[1].url.find("twofa") >= 0, al)
        r, al = reset({"password": "NewAdm1n@2026", "password2": "NewAdm1n@2026", "twofa_code": fresh_code(secret, "admin", 1)})
        check("[找回] 验证码 + 谷歌验证码 → 重置成功", "admin/login" in r.url and any("重置" in m for _, m in al), (r.url, al))
        check("[找回] 旧密码失效", "twofa" not in login_admin("2fa-old", admin_pw)[1].url)
        admin_pw = "NewAdm1n@2026"
        check("[找回] 新密码可用（仍需两步验证）", "admin/login/twofa" in login_admin("2fa-new", admin_pw)[1].url)
        check("[找回] 改密后其他已登录会话自动失效", "admin/login" in old_session.get("admin/dashboard/index").url)
        r, al = reset({"password": "Another1@Pass", "password2": "Another1@Pass", "twofa_code": fresh_code(secret, "admin", 1)})
        check("[找回] 同一个验证码不能再用", any(k == "danger" for k, _ in al), al)
        m, body = last_mail(n0, ADMIN_EMAIL)
        check("[找回] 改密后发送「密码已修改」提醒", any("密码" in str(email.message_from_bytes(open(f, "rb").read(), policy=policy.default)["Subject"]) and "修改" in str(email.message_from_bytes(open(f, "rb").read(), policy=policy.default)["Subject"]) for f in sink_files()[n0:]))
        check("[找回] 写入审计日志", int(one("select count(*) from %s where action='password.admin.changed'" % t("audit_log"))) >= 1)

        # ---------- 后台找回密码：链接 ----------
        a5, r, al = login_admin("2fa-admin-e", admin_pw)
        a5.submit("admin/login/twofa", {"code": fresh_code(secret, "admin", 1)})
        r, al = a5.submit("admin/settings/index", {"admin_reset_method": "link"})
        check("[找回] 后台切换为链接方式", one("select value from %s where name='admin_reset_method'" % t("setting")) == "link", al)
        time.sleep(61)
        n0 = len(sink_files())
        anon2 = F.UI("2fa-link")
        anon2.submit("admin/login/forgot", {"email": ADMIN_EMAIL}, match=lambda f: f.find("input", {"name": "act", "value": "send"}) is not None)
        m, body = last_mail(n0, ADMIN_EMAIL)
        link = re.search(r"https?://\S+token=([a-f0-9]{64})", body or "")
        check("[找回] 链接方式收到一次性重置链接", link is not None, (body or "")[:300])
        token = link.group(1) if link else ""
        check("[找回] 数据库只存链接令牌的哈希", int(one("select count(*) from %s where token_hash='%s'" % (t("password_reset"), token))) == 0
              and int(one("select count(*) from %s where token_hash=sha2('%s',256)" % (t("password_reset"), token))) == 1)
        r = anon2.get("admin/login/reset", token=token)
        check("[找回] 打开链接显示设置新密码页（要求谷歌验证码）", 'name="password2"' in r.text and 'name="twofa_code"' in r.text)
        r, al = anon2.submit("admin/login/reset", {"password": "Link1@Admin", "password2": "Link1@Admin", "twofa_code": fresh_code(secret, "admin", 1)}, token=token)
        check("[找回] 通过链接重置成功", any("重置" in m for _, m in al), (r.url, al))
        admin_pw = "Link1@Admin"
        r = anon2.get("admin/login/reset", token=token)
        check("[找回] 链接只能使用一次", 'name="password2"' not in r.text)
        check("[找回] 伪造的令牌无效", 'name="password2"' not in anon2.get("admin/login/reset", token="a" * 64).text)
        sql("update %s set value='code' where name='admin_reset_method'" % t("setting"))
        clear_cache()

        # ---------- 命令行重置 ----------
        rc, out = cli("reset-2fa", "admin")
        check("[2FA] 命令行 reset-2fa 关闭两步验证并留审计", rc == 0 and int(one("select totp_enabled from %s where id=1" % t("admin"))) == 0
              and int(one("select count(*) from %s where owner_type='admin' and owner_id=1" % t("twofa_recovery"))) == 0, out)
        rc, out = cli("reset-password", "admin")
        newpw = re.search(r"：(\S{16})", out)
        check("[2FA] 命令行 reset-password 生成可用的新密码", rc == 0 and newpw and "dashboard" in login_admin("2fa-cli", newpw.group(1))[1].url, out)
        # 恢复为测试默认密码
        h = subprocess.run([PHP, "-r", "echo password_hash('%s', PASSWORD_DEFAULT);" % F.ADMIN_PW], capture_output=True, text=True).stdout
        sql("update %s set password='%s' where id=1" % (t("admin"), h))
        check("[2FA] 恢复测试密码", "dashboard" in login_admin("2fa-restore", F.ADMIN_PW)[1].url)

        # ---------- 前台用户 ----------
        uname = "u2fa%d" % (int(time.time()) % 100000)
        uemail = uname + "@example.test"
        u = F.UI("2fa-user")
        r, al = u.submit("index/auth/register", {"username": uname, "email": uemail, "password": "12345678", "password2": "12345678"})
        check("[用户] 注册拒绝纯数字弱密码", not one("select id from %s where username='%s'" % (t("user"), uname)), al)
        r, al = u.submit("index/auth/register", {"username": uname, "email": uemail, "password": "User2fa@Pass1", "password2": "User2fa@Pass2"})
        check("[用户] 注册两次密码不一致被拒绝", not one("select id from %s where username='%s'" % (t("user"), uname)), al)
        r, al = u.submit("index/auth/register", {"username": uname, "email": uemail, "password": "User2fa@Pass1", "password2": "User2fa@Pass1"})
        uid = int(one("select id from %s where username='%s'" % (t("user"), uname)) or 0)
        check("[用户] 强密码注册成功", uid > 0, al)
        r, _ = u.post("index/auth/sendcode", {"scene": "reset", "email": uemail}, ajax=True)
        check("[用户] 旧的 sendcode 不能再发找回密码验证码", "不支持" in r.text, r.text[:120])
        r = u.get("index/user/security")
        usec = page_secret(r.text)
        check("[用户] 账号安全页显示二维码与密钥", "<svg" in r.text and len(usec) == 32 and "账号安全" in u.get("index/user/index").text)
        r, al = u.submit("index/user/security", {"code": fresh_code(usec, "user", uid), "password": "User2fa@Pass1"}, match="index/user/twofaenable")
        if not recovery_codes(r.text):
            r, al = u.submit("index/user/security", {"code": fresh_code(usec, "user", uid), "password": "User2fa@Pass1"}, match="twofaEnable")
        ucodes = recovery_codes(r.text)
        check("[用户] 开启两步验证并显示 10 个恢复码", int(one("select totp_enabled from %s where id=%d" % (t("user"), uid))) == 1 and len(ucodes) == 10, al)
        u2 = F.UI("2fa-user2")
        r, al = u2.submit("index/auth/login", {"account": uname, "password": "User2fa@Pass1"}, back="//evil.example/")
        check("[用户] 密码正确后进入两步验证页（未登录）", "index/auth/twofa" in r.url and "index/auth/login" in u2.get("index/user/index").url, r.url)
        r, al = u2.submit("index/auth/twofa", {"code": fresh_code(usec, "user", uid)})
        check("[用户] 两步验证后登录，且 back 不能跳到外站", "evil.example" not in r.url and "index/user" in r.url, r.url)
        u3 = F.UI("2fa-user3")
        u3.submit("index/auth/login", {"account": uemail, "password": "User2fa@Pass1"})
        r, al = u3.submit("index/auth/twofa", {"code": ucodes[0]})
        check("[用户] 恢复码登录", "index/user" in r.url, (r.url, al))
        # 改邮箱 / 改密码需要当前密码
        r, al = u2.submit("index/user/profile", {"email": "changed_" + uemail, "current_password": ""})
        check("[用户] 改邮箱必须输入当前密码", one("select email from %s where id=%d" % (t("user"), uid)) == uemail, al)
        r, al = u2.submit("index/user/profile", {"password": "Changed1@Pass", "password2": "Changed1@Pass", "current_password": "User2fa@Pass1"})
        check("[用户] 输入当前密码后可以改密码", "密码已修改" in " ".join(m for _, m in al), al)
        check("[用户] 改密码后本会话仍有效、其他会话退出",
              "index/user" in u2.get("index/user/index").url and "index/auth/login" in u3.get("index/user/index").url)
        # 用户找回密码：必须带 2FA
        time.sleep(1)
        n0 = len(sink_files())
        anon3 = F.UI("2fa-ureset")
        anon3.submit("index/auth/forgot", {"email": uemail}, match=lambda f: f.find("input", {"name": "act", "value": "send"}) is not None)
        m, body = last_mail(n0, uemail)
        ucode = re.search(r"\b(\d{6})\b", body or "")
        check("[用户] 找回密码收到验证码", ucode is not None, (body or "")[:200])
        ucode = ucode.group(1) if ucode else ""
        fr = lambda extra: anon3.submit("index/auth/forgot", dict({"email": uemail, "code": ucode}, **extra), match=lambda f: f.find("input", {"name": "act", "value": "reset"}) is not None)
        r, al = fr({"password": "Reset1@User", "password2": "Reset1@User", "twofa_code": ""})
        check("[用户] 开启 2FA 的用户找回密码必须输入谷歌验证码", any("两步验证" in m for _, m in al), al)
        r, al = fr({"password": "Reset1@User", "password2": "Reset1@User", "twofa_code": fresh_code(usec, "user", uid)})
        check("[用户] 验证码 + 谷歌验证码重置成功", "index/auth/login" in r.url and any("重置" in m for _, m in al), (r.url, al))
        check("[用户] 重置后旧会话失效", "index/auth/login" in u2.get("index/user/index").url)
        # 关闭两步验证
        u4 = F.UI("2fa-user4")
        u4.submit("index/auth/login", {"account": uname, "password": "Reset1@User"})
        u4.submit("index/auth/twofa", {"code": fresh_code(usec, "user", uid)})
        r, al = u4.submit("index/user/security", {"password": "Reset1@User", "code": fresh_code(usec, "user", uid)}, match=lambda f: "twofadisable" in f.get("action", "").lower())
        check("[用户] 输入密码 + 验证码后可以关闭两步验证", int(one("select totp_enabled from %s where id=%d" % (t("user"), uid))) == 0, al)
        # 后台重置用户 2FA（重新开一次再由管理员重置）
        sec2 = page_secret(u4.get("index/user/security").text)
        u4.submit("index/user/security", {"code": fresh_code(sec2, "user", uid), "password": "Reset1@User"}, match=lambda f: "twofaenable" in f.get("action", "").lower())
        ad, r, al = login_admin("2fa-admin-f", F.ADMIN_PW)
        r, al = ad.post("admin/user/reset2fa", {"id": str(uid)}, referer=BASE + "/index.php?s=/admin/user/index")
        check("[用户] 管理员可以重置用户的两步验证", int(one("select totp_enabled from %s where id=%d" % (t("user"), uid))) == 0, al)
        # 登录限流
        u5 = F.UI("2fa-user5")
        for i in range(5):
            u5.submit("index/auth/login", {"account": uname, "password": "Wrong%d@pass" % i})
        r, al = u5.submit("index/auth/login", {"account": uname, "password": "Reset1@User"})
        check("[用户] 连续输错 5 次密码后账号锁定（正确密码也拒绝）", "index/auth/login" in r.url and any("次数过多" in m for _, m in al), al)
    finally:
        sink.terminate()
        sql("delete from %s" % t("auth_guard"))
        sql("update %s set value='0' where name='smtp_enable'" % t("setting"))
        sql("update %s set value='5' where name='admin_sudo_minutes'" % t("setting"))
        sql("update %s set value='0' where name='admin_2fa_required'" % t("setting"))
        cli("reset-2fa", "admin")
        clear_cache()
    passed = sum(1 for _, ok, _ in F.RESULTS if ok)
    print("twofa_test: %d/%d passed" % (passed, len(F.RESULTS)))
    return 0 if passed == len(F.RESULTS) else 1


if __name__ == "__main__":
    sys.exit(main())
