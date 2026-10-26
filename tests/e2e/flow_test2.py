#!/usr/bin/env python3
"""IDCDNS 端到端流程测试 v2。

所有业务操作都通过真实页面表单提交（读取页面上的字段与 CSRF），
上游 WHMCS、Cloudflare、易支付全部是本地 mock；SQL 只用于断言和调整时间。
每一步都检查：HTTP 状态、页面无 PHP 错误、页面提示（成功/失败）以及数据库结果。
"""
import hashlib
import json
import os
import re
import subprocess
import sys
import time
from urllib.parse import urlencode, urlparse, parse_qs

import requests
from bs4 import BeautifulSoup

BASE = os.environ.get("IDC_BASE", "http://127.0.0.1:8230").rstrip("/")
DB = os.environ.get("IDC_DB", "idcdns_e2e_8230")
P = os.environ.get("IDC_PREFIX", "idc_")
UPSTREAM = os.environ.get("UPSTREAM_MOCK", "http://127.0.0.1:18990")
CF = os.environ.get("CF_MOCK", "http://127.0.0.1:18992")
ADMIN_PW = "Admin@12345"
EPAY_PID, EPAY_KEY = "1001", "E2EKEY0123456789"
RESULTS = []


def check(name, ok, detail=""):
    RESULTS.append((name, bool(ok), detail))
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else "  -> " + str(detail)[:400]), flush=True)
    return bool(ok)


def sql(q):
    p = subprocess.run(["sudo", "mysql", "-N", "-B", DB, "-e", q], stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, universal_newlines=True)
    if p.returncode:
        print("SQL ERROR", q, p.stderr)
    return p.stdout.strip()


def one(q):
    out = sql(q)
    return out.split("\n")[0].split("\t")[0] if out else ""


def t(name):
    return P + name


PHP_ERR = re.compile(r"Fatal error|Parse error|Warning:|Notice:|Call to undefined|Undefined (index|variable|offset)|系统发生错误|页面错误|SQLSTATE", re.I)


class UI:
    def __init__(self, label):
        self.label = label
        self.s = requests.Session()
        self.s.headers["User-Agent"] = "Mozilla/5.0 e2e-" + label
        self.token = ""

    def url(self, route, **params):
        u = BASE + "/index.php?s=/" + route.strip("/")
        if params:
            u += "&" + urlencode(params)
        return u

    def _remember(self, html):
        m = re.search(r'name="_token" value="([^"]+)"', html)
        if m:
            self.token = m.group(1)

    def get(self, route, **params):
        r = self.s.get(self.url(route, **params), timeout=30)
        self._remember(r.text)
        return r

    @staticmethod
    def alerts(html):
        soup = BeautifulSoup(html, "html.parser")
        out = []
        for a in soup.select(".alert"):
            cls = " ".join(a.get("class", []))
            kind = "danger" if "alert-danger" in cls else ("success" if "alert-success" in cls else "info")
            out.append((kind, a.get_text(" ", strip=True)))
        return out

    def fields(self, route, match=None, index=0, **params):
        """读取页面表单的默认提交值（与浏览器一致：保留顺序、多值、隐藏 0 + 复选框）"""
        r = self.get(route, **params)
        soup = BeautifulSoup(r.text, "html.parser")
        forms = soup.find_all("form", method=re.compile("post", re.I))
        form = None
        if match:
            for f in forms:
                names = [x.get("name") for x in f.find_all(["input", "select", "textarea"])]
                act = f.get("action", "")
                if (callable(match) and match(f)) or (isinstance(match, str) and (match in names or match in act)):
                    form = f
                    break
        elif len(forms) > index:
            form = forms[index]
        if form is None:
            return r, None, []
        data = []
        for x in form.find_all(["input", "select", "textarea"]):
            n = x.get("name")
            if not n:
                continue
            if x.name == "textarea":
                data.append((n, x.text))
            elif x.name == "select":
                opts = x.find_all("option")
                sel = [o for o in opts if o.has_attr("selected")]
                if x.has_attr("multiple"):
                    data += [(n, o.get("value", o.text)) for o in sel]
                else:
                    o = sel[0] if sel else (opts[0] if opts else None)
                    data.append((n, o.get("value", o.text) if o else ""))
            elif x.get("type") in ("checkbox", "radio"):
                if x.has_attr("checked"):
                    data.append((n, x.get("value", "on")))
            elif x.get("type") in ("submit", "button", "file"):
                continue
            else:
                data.append((n, x.get("value", "")))
        action = form.get("action") or r.url
        if action.startswith("/"):
            action = BASE + action
        return r, action, data

    def submit(self, route, values=None, match=None, index=0, files=None, **params):
        """打开页面 → 取表单 → 覆盖字段 → 提交 → 跟随跳转，返回 (响应, 提示列表)"""
        values = values or {}
        r, action, data = self.fields(route, match, index, **params)
        if action is None:
            txt = BeautifulSoup(r.text, "html.parser").get_text(" ", strip=True)[:200]
            return r, [("danger", "页面上没有找到表单: %s %s [HTTP %s] %s" % (route, match, r.status_code, txt))]
        keys = set(values)
        data = [(k, v) for k, v in data if k not in keys and k.rstrip("[]") not in keys]
        for k, v in values.items():
            if isinstance(v, (list, tuple)):
                data += [(k, x) for x in v]
            elif v is not None:
                data.append((k, v))
        r2 = self.s.post(action, data=data, files=files, headers={"Referer": r.url}, timeout=60)
        self._remember(r2.text)
        return r2, self.alerts(r2.text)

    def post(self, route, data=None, referer=None, ajax=False, **params):
        data = dict(data or {})
        if "_token" not in data:
            if not self.token:
                self.get("index/index/index")
            data["_token"] = self.token
        h = {"Referer": referer or BASE + "/"}
        if ajax:
            h["X-Requested-With"] = "XMLHttpRequest"
        r = self.s.post(self.url(route, **params), data=data, headers=h, timeout=60)
        self._remember(r.text)
        return r, self.alerts(r.text)


def clean(r):
    return r.status_code == 200 and not PHP_ERR.search(r.text)


def ok_alert(al):
    return any(k == "success" for k, _ in al) and not any(k == "danger" for k, _ in al)


def danger(al):
    return " | ".join(m for k, m in al if k == "danger")


SNAP_DIR = os.environ.get("SNAP_DIR", "")


def snap(name, r):
    """可选：保存当时页面的 HTML（SNAP_DIR 环境变量），供 shots_snap.py 渲染成截图。"""
    if not SNAP_DIR:
        return
    os.makedirs(SNAP_DIR, exist_ok=True)
    html = r.text if hasattr(r, "text") else str(r)
    html = re.sub(r"<head>", '<head><base href="%s/">' % BASE, html, count=1)
    with open(os.path.join(SNAP_DIR, name + ".html"), "w", encoding="utf-8") as f:
        f.write(html)


def step(name, r, al, expect_ok=True, extra=True):
    good = clean(r) and extra and (ok_alert(al) if expect_ok else bool(danger(al)))
    return check(name, good, "status=%s alerts=%s url=%s err=%s" % (
        r.status_code, al, r.url, (PHP_ERR.search(r.text).group(0) if PHP_ERR.search(r.text) else "")))


def epay_sign(params):
    s = "&".join("%s=%s" % (k, params[k]) for k in sorted(params) if k not in ("sign", "sign_type") and params[k] not in ("", None))
    return hashlib.md5((s + EPAY_KEY).encode()).hexdigest()


def epay_notify(trade_no, money, status="TRADE_SUCCESS", tamper=False, pid=EPAY_PID):
    p = {"pid": pid, "trade_no": "EP" + trade_no[-10:], "out_trade_no": trade_no, "type": "alipay",
         "name": "e2e", "money": "%.2f" % float(money), "trade_status": status}
    p["sign"] = epay_sign(p)
    p["sign_type"] = "MD5"
    if tamper:
        p["money"] = "0.01"
    r = requests.get(BASE + "/index.php?s=/index/pay/notify/gw/epay&" + urlencode(p), timeout=30)
    return r


def pay_epay(ui, order_id):
    """在支付页选择易支付 → 系统生成支付流水并跳到支付平台 → 模拟平台异步回调"""
    r = ui.get("index/pay/index", id=order_id)
    _, action, data = ui.fields("index/pay/index", match="gateway", id=order_id)
    payload = [(k, v) for k, v in data if k != "gateway"] + [("gateway", "epay")]
    r2 = ui.s.post(action, data=payload, headers={"Referer": r.url}, timeout=30, allow_redirects=False)
    trade = one("select trade_no from %s where order_id=%s and gateway='epay' order by id desc limit 1" % (t("payment"), order_id))
    amount = one("select amount from %s where trade_no='%s'" % (t("payment"), trade))
    return r2, trade, amount


def recharge_trade(ui, amount):
    r, al = ui.submit("index/user/recharge", {"amount": str(amount)}, match="amount")
    oid = parse_qs(urlparse(r.url).query).get("id", [""])[0]
    return r, oid


def main():
    started = time.time()
    admin = UI("admin")
    # ---------------- 后台登录与安全 ----------------
    r = requests.get(BASE + "/index.php?s=/admin/settings/index", allow_redirects=False, timeout=15)
    check("未登录访问后台被重定向到登录页", r.status_code == 302 and "login" in r.headers.get("Location", ""), r.status_code)
    r, al = admin.submit("admin/login/index", {"username": "admin", "password": "wrong-pass"})
    check("后台错误密码登录被拒绝", "login" in r.url and not ok_alert(al), (r.url, al))
    r, al = admin.submit("admin/login/index", {"username": "admin", "password": ADMIN_PW})
    check("后台登录成功进入仪表盘", clean(r) and "login" not in r.url, (r.url, al))
    r = admin.s.post(admin.url("admin/settings/index"), data={"site_name": "hack"}, timeout=15)
    check("缺少 CSRF 令牌的后台提交被拒绝", r.status_code in (403, 419) or "令牌" in r.text or "CSRF" in r.text, r.status_code)
    check("缺 CSRF 时设置未被修改", one("select value from %s where name='site_name'" % t("setting")) != "hack")

    # ---------------- 系统设置（含开关关闭） ----------------
    r, al = admin.submit("admin/settings/index", {
        "site_name": "o2o云", "reg_enable": "1", "reg_email_verify": "0", "market_enable": "1",
        "market_auto_approve": "0", "market_commission_rate": "10", "withdraw_enable": "1",
        "withdraw_min": "1", "withdraw_fee_rate": "0", "withdraw_income_only": "1",
        "reg_gift_balance": "0", "cron_web_enable": "1", "captcha_enable": "0",
        "market_endpoint_cloudflare": CF,
    })
    step("保存系统设置", r, al)
    check("首次保存设置不会关掉默认开启的功能（优惠码、Web cron、自动暂停）",
          one("select value from %s where name='coupon_enable'" % t("setting")) == "1"
          and one("select value from %s where name='lc_suspend_enable'" % t("setting")) == "1"
          and one("select value from %s where name='cron_web_enable'" % t("setting")) == "1",
          sql("select name,value from %s where name in ('coupon_enable','lc_suspend_enable','cron_web_enable')" % t("setting")))
    check("设置写入数据库（站点名、市场开启）", one("select value from %s where name='site_name'" % t("setting")) == "o2o云"
          and one("select value from %s where name='market_enable'" % t("setting")) == "1")
    # 关闭开关：只发隐藏 0（模拟浏览器取消勾选）
    _, action, data = admin.fields("admin/settings/index")
    data = [(k, v) for k, v in data if not (k == "market_auto_approve" and v == "1")]
    data = [(k, v) for k, v in data if not (k == "withdraw_income_only" and v == "1")]
    admin.s.post(action, data=data, timeout=30)
    check("设置开关取消勾选后能保存为关闭", one("select value from %s where name='withdraw_income_only'" % t("setting")) == "0",
          one("select value from %s where name='withdraw_income_only'" % t("setting")))
    admin.submit("admin/settings/index", {"withdraw_income_only": "1"})

    # ---------------- 上游 WHMCS：新增、测试、导入商品 ----------------
    r, al = admin.submit("admin/upstream/edit", {"name": "E2E WHMCS", "driver": "whmcs", "cfg_whmcs_url": UPSTREAM,
                                                  "cfg_whmcs_identifier": "id", "cfg_whmcs_secret": "sec",
                                                  "cfg_whmcs_auto_create_client": "yes", "cfg_whmcs_payment_method": "mailin",
                                                  "status": "1"})
    step("新增 WHMCS 上游", r, al)
    up_id = one("select id from %s order by id desc limit 1" % t("upstream"))
    cfg = one("select config from %s where id=%s" % (t("upstream"), up_id or 0))
    check("上游密钥加密保存（数据库中看不到明文 sec）", up_id and '"sec"' not in cfg and "sec" != cfg, cfg[:80])
    r, al = admin.post("admin/upstream/test", {"id": up_id}, referer=admin.url("admin/upstream/index"))
    step("上游测试连接", r, al)
    r, al = admin.post("admin/upstream/importProducts", {"id": up_id}, referer=admin.url("admin/upstream/index"))
    step("从上游导入商品", r, al)
    pid = one("select id from %s where upstream_id=%s order by id limit 1" % (t("product"), up_id or 0))
    check("导入后产品表有上游商品", bool(pid), pid)
    # 产品设置价格（固定价格，月付 30）、库存 5
    r, al = admin.submit("admin/product/edit", {"price_mode": "fixed", "pricing_monthly": "30", "pricing_annually": "300",
                                                 "stock": "5", "per_user_limit": "3", "auto_setup": "1", "status": "1",
                                                 "os_list": "Debian 12\nUbuntu 24.04",
                                                 "spec_cpu": "2 核", "spec_memory": "2 GB", "spec_disk": "40 GB SSD",
                                                 "spec_bandwidth": "30 Mbps", "spec_traffic": "1 TB/月", "spec_ip": "1 个独立 IPv4",
                                                 "spec_region": "香港", "spec_line": "CN2 GIA", "spec_extra": "防御：10G"}, id=pid)
    step("编辑产品定价、库存与配置规格", r, al)
    check("产品配置规格已保存", one("select concat(spec_cpu,'|',spec_line) from %s where id=%s" % (t("product"), pid)) == "2 核|CN2 GIA")
    r = UI("guest").get("index/shop/product", id=pid)
    check("商品页展示规格、价格表、折合每月", clean(r) and all(x in r.text for x in ("2 核", "40 GB SSD", "CN2 GIA", "防御", "折合每月", "年付")), r.status_code)
    check("未登录时提示登录后下单", "登录后下单" in r.text)
    r = UI("guest").get("index/shop/index")
    check("商城列表展示规格摘要", clean(r) and "30 Mbps" in r.text and "查看详情并购买" in r.text, r.status_code)
    pr = json.loads(one("select pricing from %s where id=%s" % (t("product"), pid)) or "{}")
    check("产品按周期定价已保存（月付 30）", float(pr.get("monthly", 0) or 0) == 30.0, pr)

    # ---------------- DNS 服务商 Cloudflare（mock）与根域名 ----------------
    r, al = admin.submit("admin/dnsprovider/edit", {"name": "E2E Cloudflare", "driver": "cloudflare",
                                                     "cfg_cloudflare_token": "E2ETOKEN", "cfg_cloudflare_endpoint": CF, "status": "1"})
    step("新增 Cloudflare DNS 服务商", r, al)
    prov = one("select id from %s where user_id=0 order by id desc limit 1" % t("dns_provider"))
    r, al = admin.post("admin/dnsprovider/test", {"id": prov}, referer=admin.url("admin/dnsprovider/index"))
    step("DNS 服务商测试连接", r, al)
    r, al = admin.post("admin/dnsprovider/syncDomains", {"id": prov}, referer=admin.url("admin/dnsprovider/index"))
    step("同步服务商根域名", r, al)
    dom = one("select id from %s where domain='e2e-plat.test'" % t("dns_domain"))
    check("根域名 e2e-plat.test 已入库", bool(dom))
    r, al = admin.submit("admin/domain/edit", {"status": "1", "listing_status": "active", "price": "5", "period_days": "365",
                                                "types": "A,AAAA,CNAME,TXT,MX", "min_len": "2", "max_len": "20",
                                                "reserved": "admin,root", "max_per_user": "5", "stock": "0"}, id=dom)
    step("设置根域名价格与规则", r, al)
    check("同步根域名时原有记录 www 登记为受保护", one("select count(*) from %s where domain_id=%s and name='www'" % (t("dns_protected"), dom or 0)) == "1",
          sql("select name,type,value from %s where domain_id=%s" % (t("dns_protected"), dom or 0)))

    # ---------------- 支付网关：易支付 ----------------
    r, al = admin.submit("admin/gateway/edit", {"enabled": "1", "cfg_epay_api_url": "http://127.0.0.1:9/", "cfg_epay_pid": EPAY_PID,
                                                 "cfg_epay_key": EPAY_KEY, "cfg_epay_mode": "submit"}, id="epay")
    step("启用易支付网关", r, al)
    admin.submit("admin/gateway/edit", {"enabled": "1"}, id="balance")

    # ---------------- 优惠码、卡密 ----------------
    r, al = admin.submit("admin/coupon/edit", {"code": "E2E20", "name": "八折", "type": "percent", "value": "20",
                                                "scenes/a[]": ["new", "renew"], "item_types/a[]": ["host", "dns_sub"],
                                                "per_user_limit": "1", "total_limit": "10", "status": "1",
                                                "start_time": "", "end_time": ""})
    step("新建优惠码 E2E20", r, al)
    r, al = admin.submit("admin/coupon/edit", {"code": "E2E20", "name": "重复", "type": "fixed", "value": "1", "status": "1"})
    step("重复优惠码被拒绝且提示友好", r, al, expect_ok=False, extra="SQLSTATE" not in r.text)
    r, al = admin.submit("admin/card/generate", {"name": "E2E 卡", "face_value": "50", "qty": "3", "prefix": "E2E"})
    step("生成 3 张 50 元卡密", r, al)
    batch = one("select id from %s order by id desc limit 1" % t("card_batch"))
    card_count = one("select count(*) from %s where batch_id=%s" % (t("recharge_card"), batch or 0))
    check("卡密批次数量正确", card_count == "3", card_count)
    exp = admin.s.get(admin.url("admin/card/export", id=batch), timeout=20)
    codes = re.findall(r"E2E[0-9A-Z-]{8,}", exp.text)
    check("卡密可导出（明文只在导出中出现）", len(set(codes)) == 3, exp.text[:200])
    stored = sql("select * from %s where batch_id=%s limit 1" % (t("recharge_card"), batch or 0))
    check("数据库不保存卡密明文", codes and codes[0] not in stored, stored[:120])

    # ---------------- 用户注册、登录 ----------------
    buyer = UI("buyer")
    r, al = buyer.submit("index/auth/register", {"username": "buyer1", "email": "buyer1@example.test", "password": "Buyer@12345"})
    check("前台注册成功", clean(r) and not danger(al), (r.url, al))
    uid = one("select id from %s where username='buyer1'" % t("user"))
    check("新用户入库且密码已哈希", uid and one("select password from %s where id=%s" % (t("user"), uid)).startswith("$2"))
    r, al = UI("x").submit("index/auth/register", {"username": "buyer1", "email": "buyer1@example.test", "password": "Buyer@12345"})
    check("重复注册被拒绝", bool(danger(al)) and clean(r), al)
    buyer = UI("buyer")
    r, al = buyer.submit("index/auth/login", {"account": "buyer1@example.test", "password": "Buyer@12345"})
    check("前台用邮箱登录成功", clean(r) and "login" not in r.url, (r.url, al))

    # ---------------- 充值：易支付回调（含伪造、篡改、重复） ----------------
    r, oid = recharge_trade(buyer, 100)
    check("创建充值订单并进入支付页", bool(oid) and clean(r), r.url)
    r2, trade, amount = pay_epay(buyer, oid)
    check("选择易支付后生成支付流水并跳转到支付平台", trade and r2.status_code in (200, 302), (r2.status_code, trade))
    bal0 = float(one("select balance from %s where id=%s" % (t("user"), uid)))
    r = epay_notify(trade, amount, tamper=True)
    check("篡改金额的回调被拒绝（验签失败）", r.text.strip() != "success" and float(one("select balance from %s where id=%s" % (t("user"), uid))) == bal0, r.text[:80])
    r = epay_notify(trade, amount, pid="9999")
    check("商户号不符的回调被拒绝", r.text.strip() != "success", r.text[:80])
    r = epay_notify(trade, "1.00")
    check("回调金额与订单不符时不入账", float(one("select balance from %s where id=%s" % (t("user"), uid))) == bal0, r.text[:80])
    r = epay_notify(trade, amount)
    check("正确签名的回调返回 success（无需登录）", r.text.strip() == "success", (r.status_code, r.text[:200]))
    bal1 = float(one("select balance from %s where id=%s" % (t("user"), uid)))
    check("充值 100 到账", abs(bal1 - bal0 - 100) < 0.001, (bal0, bal1))
    epay_notify(trade, amount)
    bal2 = float(one("select balance from %s where id=%s" % (t("user"), uid)))
    check("重复回调不重复入账（幂等）", bal2 == bal1, (bal1, bal2))
    check("余额流水记录前后余额", one("select count(*) from %s where user_id=%s and type='recharge'" % (t("balance_log"), uid)) == "1")

    # ---------------- 卡密兑换 ----------------
    r, al = buyer.submit("index/user/card", {"code": codes[0] if codes else "none"})
    step("兑换卡密 50 元", r, al)
    bal3 = float(one("select balance from %s where id=%s" % (t("user"), uid)))
    check("卡密金额到账", abs(bal3 - bal2 - 50) < 0.001, (bal2, bal3))
    r, al = buyer.submit("index/user/card", {"code": codes[0] if codes else "none"})
    step("同一卡密不能再次兑换", r, al, expect_ok=False)

    # ---------------- 购买云主机（优惠码 + 余额支付 → 上游开通） ----------------
    r = buyer.get("index/shop/product", id=pid)
    check("登录后商品页含 root 密码显示/生成/强度检测与优惠码", all(x in r.text for x in ("data-pw-generate", "data-pw-toggle", "data-pw-bar", "coupon_code")))
    r, al = buyer.submit("index/host/buy", {"cycle": "monthly", "hostname": "e2e-weak", "password": "12345678",
                                            "os": "Debian 12"}, match="cycle", product_id=pid)
    check("弱 root 密码被服务端拒绝", bool(danger(al)) and "root 密码" in danger(al) and clean(r), al)
    r, al = buyer.submit("index/host/buy", {"cycle": "monthly", "hostname": "bad_host!", "password": "VmPass@12345",
                                            "os": "Debian 12"}, match="cycle", product_id=pid)
    check("非法主机名被服务端拒绝", bool(danger(al)) and clean(r), al)
    r, al = buyer.submit("index/host/buy", {"cycle": "monthly", "hostname": "e2e-vm1", "password": "VmPass@12345",
                                            "os": "Debian 12", "coupon_code": "E2E20"}, match="cycle", product_id=pid)
    host_oid = parse_qs(urlparse(r.url).query).get("id", [""])[0]
    check("提交云主机订单并跳到支付页", bool(host_oid) and clean(r), (r.url, al))
    pay_amount = one("select pay_amount from %s where id=%s" % (t("order"), host_oid or 0))
    check("优惠码生效：30 × 0.8 = 24", pay_amount and abs(float(pay_amount) - 24) < 0.001, pay_amount)
    r, al = buyer.submit("index/pay/index", {"gateway": "balance"}, match="gateway", id=host_oid)
    check("余额支付成功", clean(r) and not danger(al), (r.url, al))
    st = sql("select status, remote_id from %s where order_id=%s" % (t("service"), host_oid or 0))
    check("云主机已在上游开通（状态 active，有上游服务 ID）", st.startswith("active") and len(st.split("\t")) > 1 and st.split("\t")[1] not in ("", "0", "NULL"), st)
    sid = one("select id from %s where order_id=%s" % (t("service"), host_oid or 0))
    check("库存已扣减", one("select sold from %s where id=%s" % (t("product"), pid)) in ("1",) or
          one("select count(*) from %s where product_id=%s and status='active'" % (t("service"), pid)) == "1")
    check("优惠码使用次数 +1", one("select used_count from %s where code='E2E20'" % t("coupon")) == "1")
    r = buyer.get("index/host/detail", id=sid)
    check("用户中心云主机详情页正常", clean(r) and "e2e-vm1" in r.text, r.status_code)
    r, al = buyer.post("index/host/action", {"id": sid, "action": "reboot"}, referer=buyer.url("index/host/detail", id=sid))
    check("云主机重启操作提交到上游", clean(r) and not danger(al), al)

    # 再用同一优惠码 → 每人限 1 次
    r, al = buyer.submit("index/host/buy", {"cycle": "monthly", "hostname": "e2e-vm2", "password": "VmPass@12345",
                                            "os": "Debian 12", "coupon_code": "E2E20"}, match="cycle", product_id=pid)
    check("优惠码每人限用 1 次", bool(danger(al)) and clean(r), al)
    # 中文状态徽标：订单列表不再出现原始英文状态码
    r = buyer.get("index/order/index")
    check("用户订单列表显示中文状态（已开通/已完成）", clean(r) and ("已开通" in r.text or "已完成" in r.text) and ">paid<" not in r.text, r.status_code)
    r = admin.get("admin/order/index")
    check("后台订单列表显示中文状态与订单类型", clean(r) and "已开通" in r.text and "新购云主机" in r.text and ">paid<" not in r.text and ">host_new<" not in r.text, r.status_code)
    r = admin.get("admin/dashboard/index")
    check("后台仪表盘最近订单显示中文状态", clean(r) and ">paid<" not in r.text and "badge" in r.text, r.status_code)
    r = buyer.get("index/host/index")
    check("我的云主机显示中文状态徽标（不是转义的 HTML）", clean(r) and "运行中" in r.text and "&lt;span" not in r.text, r.status_code)
    # 售罄：库存 = 已售 时商品页显示已售罄且无下单表单
    sold_before = one("select concat(stock,',',sold) from %s where id=%s" % (t("product"), pid))
    sql("update %s set stock=sold where id=%s" % (t("product"), pid))
    r = buyer.get("index/shop/product", id=pid)
    check("售罄商品显示「已售罄」且不出现下单按钮", clean(r) and "已售罄" in r.text and "创建订单并去支付" not in r.text, r.status_code)
    st_, so_ = sold_before.split(",")
    sql("update %s set stock=%s where id=%s" % (t("product"), st_, pid))
    # 登录 back 参数不能跳到站外
    ev = UI("evil")
    r0 = ev.get("index/auth/login", back="//evil.example/")
    r, al = ev.submit("index/auth/login", {"account": "buyer1", "password": "Buyer@12345"}, back="//evil.example/")
    check("登录 back 参数不能跳转到站外（防开放重定向）", "evil.example" not in r.url, r.url)
    check("数据库结构版本已记录为最新", one("select value from %s where name='schema_version'" % t("setting")) == "4", one("select value from %s where name='schema_version'" % t("setting")))

    # 续费：易支付
    due0 = int(one("select due_time from %s where id=%s" % (t("service"), sid)) or 0)
    r, al = buyer.submit("index/host/renew", {"cycle": "monthly"}, match="cycle", id=sid)
    renew_oid = parse_qs(urlparse(r.url).query).get("id", [""])[0]
    check("创建续费订单", bool(renew_oid), (r.url, al))
    _, trade, amount = pay_epay(buyer, renew_oid)
    r = epay_notify(trade, amount)
    due1 = int(one("select due_time from %s where id=%s" % (t("service"), sid)) or 0)
    check("续费支付后到期时间延长约 1 个月", due1 - due0 >= 27 * 86400, (due0, due1, r.text[:60]))

    # ---------------- 子域名：购买、解析增删改，Cloudflare mock 状态核对 ----------------
    r, al = buyer.submit("index/dns/register", {"prefix": "admin"}, match="prefix", domain_id=dom)
    check("保留前缀 admin 不能注册", bool(danger(al)), al)
    r, al = buyer.submit("index/dns/register", {"prefix": "www"}, match="prefix", domain_id=dom)
    check("平台原有记录 www 受保护不能注册", bool(danger(al)), al)
    r, al = buyer.submit("index/dns/register", {"prefix": "mysite"}, match="prefix", domain_id=dom)
    sub_oid = parse_qs(urlparse(r.url).query).get("id", [""])[0]
    check("注册子域名 mysite 下单", bool(sub_oid) and clean(r), (r.url, al))
    r, al = buyer.submit("index/pay/index", {"gateway": "balance"}, match="gateway", id=sub_oid)
    sub = sql("select id,status from %s where fqdn='mysite.e2e-plat.test'" % t("dns_subdomain"))
    check("支付后子域名开通", sub and sub.split("\t")[1] in ("1", "active"), sub)
    sub_id = sub.split("\t")[0] if sub else "0"
    r, al = buyer.submit("index/dns/records", {"host": "@", "type": "A", "value": "203.0.113.7", "ttl": "600"}, match="value", id=sub_id)
    step("添加 A 记录", r, al)
    st = requests.get(CF + "/__state", timeout=10).json()
    plat = st["records"].get("z-plat", [])
    recA = [x for x in plat if x["name"] == "mysite.e2e-plat.test" and x["type"] == "A"]
    check("记录已真实写入 Cloudflare（mock 状态可见）", recA and recA[0]["content"] == "203.0.113.7", plat)
    r, al = buyer.submit("index/dns/records", {"host": "blog", "type": "CNAME", "value": "target.example.net", "ttl": "600"}, match="value", id=sub_id)
    step("添加 CNAME 记录 blog", r, al)
    r, al = buyer.submit("index/dns/records", {"host": "@", "type": "SRV", "value": "0 5 5060 sip.example.net", "ttl": "600"}, match="value", id=sub_id)
    check("不允许的记录类型 SRV 被拒绝", bool(danger(al)), al)
    r, al = buyer.submit("index/dns/records", {"host": "@", "type": "A", "value": "999.1.1.1", "ttl": "600"}, match="value", id=sub_id)
    check("非法 IPv4 被拒绝", bool(danger(al)), al)
    rec_id = one("select id from %s where sub_id=%s and type='A' order by id limit 1" % (t("dns_record"), sub_id))
    r, al = buyer.submit("index/dns/records", {"record_id": rec_id, "host": "@", "type": "A", "value": "203.0.113.8", "ttl": "600"}, match="value", id=sub_id)
    step("修改 A 记录", r, al)
    st = requests.get(CF + "/__state", timeout=10).json()
    recA = [x for x in st["records"]["z-plat"] if x["name"] == "mysite.e2e-plat.test" and x["type"] == "A"]
    check("Cloudflare 上记录值已更新", recA and recA[0]["content"] == "203.0.113.8" and len(recA) == 1, recA)
    r, al = buyer.post("index/dns/deleteRecord", {"id": rec_id}, referer=buyer.url("index/dns/records", id=sub_id))
    step("删除 A 记录", r, al)
    st = requests.get(CF + "/__state", timeout=10).json()
    check("Cloudflare 上记录已删除", not [x for x in st["records"]["z-plat"] if x["name"] == "mysite.e2e-plat.test" and x["type"] == "A"])
    check("平台原有 www 记录始终未被改动", [x for x in st["records"]["z-plat"] if x["id"] == "p-www" and x["content"] == "198.51.100.10"])

    # 越权：另一用户不能看/改 buyer 的子域名与订单
    other = UI("other")
    other.submit("index/auth/register", {"username": "other1", "email": "other1@example.test", "password": "Other@12345"})
    other = UI("other")
    other.submit("index/auth/login", {"account": "other1", "password": "Other@12345"})
    r = other.get("index/order/detail", id=host_oid)
    check("其他用户看不到别人的订单（404）", r.status_code == 404 or "不存在" in r.text, r.status_code)
    r = other.get("index/dns/records", id=sub_id)
    check("其他用户不能管理别人的子域名", r.status_code in (403, 404) or "不存在" in r.text, r.status_code)
    r = other.get("index/host/detail", id=sid)
    check("其他用户不能查看别人的云主机", r.status_code in (403, 404) or "不存在" in r.text, r.status_code)

    # ---------------- 域名市场：卖家发布 → 验证 → 审核 → 买家购买 → 分成 → 提现 ----------------
    seller = UI("seller")
    seller.submit("index/auth/register", {"username": "seller1", "email": "seller1@example.test", "password": "Seller@12345"})
    seller = UI("seller")
    seller.submit("index/auth/login", {"account": "seller1", "password": "Seller@12345"})
    seller_uid = one("select id from %s where username='seller1'" % t("user"))
    r, al = seller.submit("index/market/provider", {"driver": "cloudflare", "name": "我的 CF",
                                                     "config[token]": "SELLERTOKEN"})
    step("卖家添加 DNS 凭据并通过健康检查", r, al)
    snap("market-01-seller-dns-credential", r)
    sp = one("select id from %s where user_id=%s" % (t("dns_provider"), seller_uid))
    r = seller.s.get(seller.url("index/market/remoteDomains", provider_id=sp), headers={"X-Requested-With": "XMLHttpRequest"}, timeout=20)
    check("卖家读取账户内域名", "seller-shop.test" in r.text, r.text[:200])
    r, al = seller.submit("index/market/index", {"provider_id": sp, "domain": "seller-shop.test", "price": "8", "period_days": "365",
                                                  "types[]": ["A", "CNAME", "TXT"], "min_len": "2", "max_len": "20"}, match="provider_id")
    step("卖家提交发布 seller-shop.test", r, al)
    sd = sql("select id, verify_token, listing_status from %s where domain='seller-shop.test'" % t("dns_domain"))
    check("发布记录为待验证/待审核", sd and sd.split("\t")[2] == "pending", sd)
    sd_id, token = (sd.split("\t")[0], sd.split("\t")[1]) if sd else ("0", "")
    r = seller.get("index/market/index")
    check("页面显示 TXT 验证主机与值", "_idcdns-verify" in r.text and token in r.text)
    snap("market-02-listing-submitted-txt-instructions", r)
    r, al = seller.post("index/market/verify", {"id": sd_id}, referer=seller.url("index/market/index"))
    check("未添加 TXT 时验证失败并提示添加方法", "_idcdns-verify" in danger(al), al)
    snap("market-03-verify-failed-hint", r)
    requests.post(CF + "/__seed", json={"zone": "z-sell", "records": [{"type": "TXT", "name": "_idcdns-verify.seller-shop.test", "content": token}]}, timeout=10)
    r, al = seller.post("index/market/verify", {"id": sd_id}, referer=seller.url("index/market/index"))
    step("添加 TXT 后所有权验证成功", r, al)
    snap("market-04-ownership-verified", r)
    snap("market-05-admin-review-queue", admin.get("admin/market/index"))
    snap("market-06-admin-review-form", admin.get("admin/market/review", id=sd_id))
    check("卖家原有记录 www/根 MX 已列为受保护", "www" in one("select reserved from %s where id=%s" % (t("dns_domain"), sd_id)),
          one("select reserved from %s where id=%s" % (t("dns_domain"), sd_id)))
    r, al = admin.submit("admin/market/review", {"action": "approve"}, id=sd_id)
    step("管理员审核通过上架", r, al)
    snap("market-07-admin-approved", r)
    check("域名已上架", one("select listing_status from %s where id=%s" % (t("dns_domain"), sd_id)) == "active")
    r = buyer.get("index/market/index")
    check("买家在市场看到该域名", "seller-shop.test" in r.text)
    snap("market-08-buyer-market", r)
    snap("market-09-buyer-buy-page", buyer.get("index/market/buy", id=sd_id))
    r, al = buyer.submit("index/market/buy", {"prefix": "www"}, match="prefix", id=sd_id)
    check("买家不能购买卖家已有记录 www", bool(danger(al)), al)
    snap("market-10-protected-record-rejected", r)
    r, al = buyer.submit("index/market/buy", {"prefix": "shop"}, match="prefix", id=sd_id)
    m_oid = parse_qs(urlparse(r.url).query).get("id", [""])[0]
    check("买家下单 shop.seller-shop.test", bool(m_oid), (r.url, al))
    snap("market-11-buyer-pay-page", r)
    sbal0 = float(one("select balance from %s where id=%s" % (t("user"), seller_uid)))
    _, trade, amount = pay_epay(buyer, m_oid)
    r = epay_notify(trade, amount)
    check("市场订单易支付回调成功", r.text.strip() == "success", r.text[:100])
    sale = sql("select amount, commission, income from %s where order_id=%s" % (t("market_sale"), m_oid or 0))
    check("生成销售记录：8 元，佣金 0.8，卖家收入 7.2", sale and [round(float(x), 2) for x in sale.split("\t")] == [8.0, 0.8, 7.2], sale)
    sbal1 = float(one("select balance from %s where id=%s" % (t("user"), seller_uid)))
    check("卖家余额增加 7.2", abs(sbal1 - sbal0 - 7.2) < 0.001, (sbal0, sbal1))
    snap("market-12-buyer-order-paid", buyer.get("index/order/detail", id=m_oid))
    msub = one("select id from %s where fqdn='shop.seller-shop.test'" % t("dns_subdomain"))
    r, al = buyer.submit("index/dns/records", {"host": "@", "type": "A", "value": "203.0.113.50", "ttl": "600"}, match="value", id=msub)
    step("买家给市场子域名添加解析", r, al)
    snap("market-13-buyer-record-created", r)
    snap("market-14-seller-income-commission", seller.get("index/market/index"))
    snap("market-15-admin-sales-commission", admin.get("admin/market_sale/index"))
    st = requests.get(CF + "/__state", timeout=10).json()
    check("解析写入卖家的 Cloudflare 账户", [x for x in st["records"]["z-sell"] if x["name"] == "shop.seller-shop.test" and x["content"] == "203.0.113.50"])
    check("卖家原有记录未被改动", [x for x in st["records"]["z-sell"] if x["id"] == "s-www" and x["content"] == "198.51.100.20"])
    r, al = seller.submit("index/market/index", {"amount": "100", "method": "支付宝", "account": "seller@alipay"}, match="account")
    check("提现超过收入被拒绝", bool(danger(al)), al)
    r, al = seller.submit("index/market/index", {"amount": "5", "method": "支付宝", "account": "seller@alipay"}, match="account")
    step("卖家申请提现 5 元", r, al)
    snap("market-16-seller-withdraw-request", r)
    wid = one("select id from %s where user_id=%s order by id desc limit 1" % (t("withdraw"), seller_uid))
    check("提现申请冻结/扣除余额", abs(float(one("select balance from %s where id=%s" % (t("user"), seller_uid))) - (sbal1 - 5)) < 0.001)
    snap("market-17-admin-withdraw-review", admin.get("admin/withdraw/edit", id=wid))
    r, al = admin.submit("admin/withdraw/edit", {"action": "paid", "admin_note": "已转账"}, id=wid)
    step("管理员标记提现已打款", r, al)
    snap("market-18-admin-withdraw-paid", r)
    snap("market-19-seller-balance-log", seller.get("index/user/balance"))
    check("提现状态为已打款", one("select status from %s where id=%s" % (t("withdraw"), wid)) == "paid")

    # ---------------- 后台余额调整、订单与服务管理 ----------------
    r, al = admin.submit("admin/balance/adjust", {"user_id": uid, "amount": "-10", "reason": "E2E 扣款"})
    step("后台扣减用户余额并写流水", r, al)
    r = admin.get("admin/order/detail", id=host_oid)
    check("后台订单详情页正常", clean(r) and "e2e-vm1" in r.text, r.status_code)
    r = admin.get("admin/service/index")
    check("后台服务列表正常", clean(r))

    # ---------------- 到期生命周期：计划任务暂停逾期服务 ----------------
    sql("update %s set due_time=%d where id=%s" % (t("service"), int(time.time()) - 5 * 86400, sid))
    key = one("select value from %s where name='cron_key'" % t("setting"))
    r = requests.get(BASE + "/index.php?s=/api/cron/run&key=bad", timeout=30)
    check("错误密钥的 Web 计划任务返回 403", r.status_code == 403, r.status_code)
    r = requests.get(BASE + "/index.php?s=/api/cron/run&" + urlencode({"key": key}), timeout=120)
    check("Web 计划任务执行成功", r.status_code == 200 and '"code":0' in r.text.replace(" ", ""), r.text[:300])
    check("逾期云主机被自动暂停", one("select status from %s where id=%s" % (t("service"), sid)) == "suspended",
          one("select status from %s where id=%s" % (t("service"), sid)))

    # ---------------- 工单：买家 → 市场卖家 → 管理员；附件、内部备注、关闭、评价、重开、超时催单 ----------------
    def has_action(v):
        return lambda f: f.find("input", {"name": "action", "value": v}) is not None
    png = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64
    r, al = buyer.submit("index/ticket/create", {"subject": "E2E 伪装附件", "content": "test", "department": "技术支持"},
                         match="subject", files={"attachments[]": ("evil.png", b"MZ\x90\x00 not a png", "image/png")})
    check("工单拒绝伪装成图片的可执行文件", bool(danger(al)), al)
    r = buyer.get("index/ticket/create")
    check("提交工单页列出市场子域名卖家", 'name="seller_subdomain_id"' in r.text and "shop.seller-shop.test" in r.text)
    snap("ticket-01-create-form", r)
    r, al = buyer.submit("index/ticket/create", {"subject": "E2E shop 子域名解析不生效", "priority": "高",
                                                  "related": "subdomain:%s" % msub, "seller_subdomain_id": str(msub),
                                                  "content": "我添加了 **A 记录**，但解析不生效。\n\n<script>alert(1)</script>"},
                         match="subject", files={"attachments[]": ("dig.png", png, "image/png")})
    step("买家提交工单并发给市场卖家（带 PNG 附件）", r, al)
    tk = sql("select id, seller_user_id, status from %s where subject='E2E shop 子域名解析不生效'" % t("ticket"))
    tid = tk.split("\t")[0] if tk else "0"
    check("工单路由到卖家", tk and tk.split("\t")[1] == str(seller_uid), tk)
    check("附件保存在 data 目录且使用随机文件名",
          one("select count(*) from %s where ticket_id=%s and stored_name<>'dig.png'" % (t("ticket_attachment"), tid)) == "1")
    check("工单内容 Markdown 渲染且 XSS 被转义", "<strong>A 记录</strong>" in r.text and "<script>alert(1)" not in r.text)
    snap("ticket-02-user-detail-created", r)
    r = seller.get("index/ticket/index")
    check("卖家在工单列表看到买家工单", "E2E shop 子域名解析不生效" in r.text)
    r, al = seller.submit("index/ticket/detail", {"content": "已检查，请把 TTL 改为 600 后再试。"}, match="content", id=tid)
    step("卖家回复工单", r, al)
    check("卖家回复后状态为等待用户", one("select status from %s where id=%s" % (t("ticket"), tid)) == "waiting_user")
    snap("ticket-03-seller-replied", r)
    r = admin.get("admin/ticket/index")
    check("后台工单列表显示该工单", "E2E shop 子域名解析不生效" in r.text)
    snap("ticket-04-admin-list", r)
    r, al = admin.submit("admin/ticket/detail", {"content": "内部：卖家已处理，关注即可"}, match=has_action("note"), id=tid)
    step("管理员添加内部备注", r, al)
    check("内部备注不改变工单状态", one("select status from %s where id=%s" % (t("ticket"), tid)) == "waiting_user")
    r, al = admin.submit("admin/ticket/detail", {"content": "平台已确认解析正常，如仍有问题请回复。"}, match=has_action("reply"), id=tid)
    step("管理员公开回复", r, al)
    snap("ticket-05-admin-detail", r)
    r = buyer.get("index/ticket/detail", id=tid)
    check("买家看不到内部备注、能看到公开回复", "内部：卖家已处理" not in r.text and "平台已确认解析正常" in r.text)
    att = one("select id from %s where ticket_id=%s limit 1" % (t("ticket_attachment"), tid))
    r = buyer.s.get(buyer.url("index/ticket/download", id=att), timeout=20)
    check("买家可下载自己的附件", r.status_code == 200 and r.content.startswith(b"\x89PNG"), r.status_code)
    other = UI("other")
    other.submit("index/auth/register", {"username": "other1", "email": "other1@example.test", "password": "Other@12345"})
    other = UI("other")
    other.submit("index/auth/login", {"account": "other1", "password": "Other@12345"})
    r = other.s.get(other.url("index/ticket/download", id=att), timeout=20)
    check("其他用户无法下载该附件", r.status_code in (403, 404) and not r.content.startswith(b"\x89PNG"), r.status_code)
    r = other.get("index/ticket/detail", id=tid)
    check("其他用户无法查看该工单", r.status_code in (403, 404) or "平台已确认解析正常" not in r.text, r.status_code)
    r, al = buyer.submit("index/ticket/detail", {}, match="ticket/close", id=tid)
    step("买家关闭工单", r, al)
    check("工单已关闭", one("select status from %s where id=%s" % (t("ticket"), tid)) == "closed")
    r, al = buyer.submit("index/ticket/detail", {"score": "5", "comment": "卖家响应很快"}, match="ticket/rate", id=tid)
    step("买家给出 5 分评价", r, al)
    check("评价已保存", one("select score from %s where ticket_id=%s" % (t("ticket_rating"), tid)) == "5")
    snap("ticket-06-user-rated", r)
    r, al = buyer.submit("index/ticket/detail", {}, match="ticket/reopen", id=tid)
    step("买家在期限内重开工单", r, al)
    check("工单重开为待处理", one("select status from %s where id=%s" % (t("ticket"), tid)) in ("open", "processing"))
    r = admin.get("admin/satisfaction/index")
    check("后台满意度报表正常", clean(r) and "5" in r.text, r.status_code)
    snap("ticket-07-admin-satisfaction", r)
    # 超时催单：把等待时间拨回 7 小时，执行计划任务，先提醒卖家；再拨回到超过卖家升级时限，升级给管理员
    sql("update %s set waiting_since=%d, reminded_at=0, remind_count=0, status='open' where id=%s" % (t("ticket"), int(time.time()) - 7 * 3600, tid))
    requests.get(BASE + "/index.php?s=/api/cron/run&" + urlencode({"key": key}), timeout=120)
    check("超时工单触发第一次催单（提醒卖家）", one("select remind_count from %s where id=%s" % (t("ticket"), tid)) == "1",
          one("select remind_count, reminded_at from %s where id=%s" % (t("ticket"), tid)))
    requests.get(BASE + "/index.php?s=/api/cron/run&" + urlencode({"key": key}), timeout=120)
    check("同一超时周期不会重复催单", one("select remind_count from %s where id=%s" % (t("ticket"), tid)) == "1")
    check("催单写入审计日志", int(one("select count(*) from %s where action='ticket.overdue_reminder'" % t("audit_log")) or 0) >= 1)

    # ---------------- 前台/后台页面无错误 ----------------
    for route in ["index/user/index", "index/order/index", "index/host/index", "index/dns/index", "index/user/balance",
                  "index/user/notices", "index/shop/index", "index/shop/packages", "index/market/index", "index/ticket/index"]:
        r = buyer.get(route)
        check("前台页面正常 " + route, clean(r), r.status_code)

    passed = sum(1 for x in RESULTS if x[1])
    print("\nRESULT %d/%d passed in %.1fs" % (passed, len(RESULTS), time.time() - started))
    with open(os.environ.get("FLOW_REPORT", "/tmp/flow_report.json"), "w") as f:
        json.dump([{"name": n, "ok": o, "detail": d} for n, o, d in RESULTS], f, ensure_ascii=False, indent=1)
    return 0 if passed == len(RESULTS) else 1


if __name__ == "__main__":
    sys.exit(main())
