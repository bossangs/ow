#!/usr/bin/env python3
"""Offline browser-like E2E flow for IDCDNS.

The test uses requests/BeautifulSoup only; all mutations go through HTTP forms and
all upstream/payment/DNS calls are local mocks.  SQL is used only for assertions
and for clock/lifecycle fixtures.
"""
import hashlib
import os
import re
import subprocess
import sys
import time
from urllib.parse import urlencode, urljoin

import requests
from bs4 import BeautifulSoup

BASE = os.environ.get("IDC_BASE", "http://127.0.0.1:8230")
DB = os.environ.get("IDC_DB", "idcdns_e2e_8230")
UPSTREAM = os.environ.get("UPSTREAM_MOCK", "http://127.0.0.1:18990")
CF = os.environ.get("CF_MOCK", "http://127.0.0.1:18991")
PASS = FAIL = 0


def check(name, ok, detail=""):
    global PASS, FAIL
    if ok:
        PASS += 1
        print("PASS", name)
    else:
        FAIL += 1
        print("FAIL", name, detail)
    return ok


def sql(q):
    p = subprocess.run(["sudo", "mysql", "-N", "-B", "-e", q],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       universal_newlines=True)
    return p.stdout.strip()


def row(table, where="1=1"):
    out = sql("select * from %s where %s order by id desc limit 1" % (table, where))
    return out


class UI:
    def __init__(self, base):
        self.base = base.rstrip("/")
        self.s = requests.Session()
        self.last_token = ""

    def url(self, route, **params):
        q = "/index.php?s=/%s" % route.strip("/")
        if params:
            q += "&" + urlencode(params)
        return self.base + q

    def get(self, route, **params):
        r = self.s.get(self.url(route, **params), timeout=15)
        token = self.token(r.text)
        if token:
            self.last_token = token
        return r

    @staticmethod
    def token(html):
        m = re.search(r'name=["\']_token["\'] value=["\']([^"\']+)', html)
        return m.group(1) if m else ""

    def post(self, route, data=None, **params):
        data = dict(data or {})
        if "_token" not in data:
            data["_token"] = self.last_token
            if not data["_token"]:
                data["_token"] = self.token(self.get("admin/index/index").text)
        return self.s.post(self.url(route, **params), data=data, timeout=20,
                           allow_redirects=False)

    def form(self, route, **params):
        r = self.get(route, **params)
        soup = BeautifulSoup(r.text, "html.parser")
        f = soup.find("form")
        data = {}
        if f:
            for x in f.find_all(["input", "textarea", "select"]):
                n = x.get("name")
                if not n:
                    continue
                if x.name == "select" and x.get("multiple"):
                    continue
                if x.name == "textarea":
                    data[n] = x.text
                elif x.name == "select":
                    o = x.find("option", selected=True) or x.find("option")
                    data[n] = o.get("value", "") if o else ""
                elif x.get("type") in ("checkbox", "radio"):
                    if x.has_attr("checked"):
                        data[n] = x.get("value", "1")
                else:
                    data[n] = x.get("value", "")
        data["_token"] = self.token(r.text)
        return r, data

    def submit_form(self, route, data, **params):
        return self.s.post(self.url(route, **params), data=data, timeout=20,
                           allow_redirects=False)


def no_php_error(r):
    return r.status_code in (200, 302) and not re.search(
        r"Fatal error|Parse error|Warning:|Notice:|Call to undefined|系统发生错误",
        r.text, re.I)


def main():
    ui = UI(BASE)
    # Authentication and public UI.
    r = ui.get("admin/login/index")
    tok = ui.token(r.text)
    r = ui.s.post(ui.url("admin/login/index"), data={"_token": tok,
        "username": "admin", "password": "Admin@12345", "captcha": ""},
        timeout=15, allow_redirects=False)
    logged = r.status_code == 302 and "/admin/" in r.headers.get("location", "")
    if not logged:
        # PHP's built-in server may serve one request from a stale settings
        # cache immediately after setup_site changes captcha_enable.  Refresh
        # the form/session once; never bypass CSRF or authentication.
        r = ui.get("admin/login/index")
        tok = ui.token(r.text)
        r = ui.s.post(ui.url("admin/login/index"), data={"_token": tok,
            "username": "admin", "password": "Admin@12345", "captcha": ""},
            timeout=15, allow_redirects=False)
        logged = r.status_code == 302 and "/admin/" in r.headers.get("location", "")
    check("admin login", logged, "HTTP %s Location=%r" %
          (r.status_code, r.headers.get("location", "")))
    for route in ("admin/index/index", "admin/order/index", "admin/settings/index",
                  "index/shop/index", "index/shop/domains"):
        check("page " + route, no_php_error(ui.get(route)))

    # Upstream configured through its dynamic driver form, tested, and imported.
    r, d = ui.form("admin/upstream/edit")
    d.update({"name": "Local WHMCS", "driver": "whmcs", "cfg_whmcs_url": UPSTREAM,
              "cfg_whmcs_identifier": "id", "cfg_whmcs_secret": "sec",
              "cfg_whmcs_auto_create_client": "yes", "cfg_whmcs_payment_method": "",
              "cfg_whmcs_currency": "USD", "cfg_whmcs_renew_mode": "extend_duedate",
              "status": "1", "remark": "offline e2e"})
    r = ui.submit_form("admin/upstream/edit", d)
    check("add WHMCS upstream", r.status_code in (200, 302))
    uid = sql("select id from %s.idc_upstream order by id desc limit 1" % DB)
    if uid:
        r = ui.post("admin/upstream/test", {"id": uid})
        check("WHMCS test connection", no_php_error(r))
        r = ui.post("admin/upstream/importProducts", {"id": uid, "price_mode": "markup",
                                                        "markup": "20"})
        check("import upstream products", no_php_error(r))
    pid = sql("select id from %s.idc_product order by id desc limit 1" % DB)
    check("imported product row", bool(pid))

    # Product group and product publication/pricing are edited through admin forms.
    r, d = ui.form("admin/product_group/edit")
    d.update({"name": "E2E Cloud", "description": "local", "status": "1"})
    r = ui.submit_form("admin/product_group/edit", d)
    check("create product group", r.status_code in (200, 302))
    gid = sql("select id from %s.idc_product_group order by id desc limit 1" % DB)
    if pid:
        r, d = ui.form("admin/product/edit", id=pid)
        d.update({"name": "E2E WHMCS Cloud", "group_id": gid or "0", "status": "1",
                  "price_mode": "markup", "markup": "20", "pricing_monthly": "12",
                  "stock": "-1", "auto_setup": "1"})
        r = ui.submit_form("admin/product/edit", d)
        check("publish imported product and compute pricing", r.status_code in (200, 302))
    # A second fixed-price product.
    r, d = ui.form("admin/product/edit")
    d.update({"name": "E2E Fixed", "group_id": gid or "0", "upstream_id": "0",
              "price_mode": "fixed", "pricing_monthly": "8", "status": "1",
              "stock": "-1"})
    r = ui.submit_form("admin/product/edit", d)
    check("create fixed-price product", r.status_code in (200, 302))

    # Payment gateways: Epay is configured but never contacts a real endpoint;
    # balance is enabled through the gateway form.
    r, d = ui.form("admin/gateway/edit", id="epay")
    d.update({"enabled": "1", "cfg_epay_api_url": "http://127.0.0.1:19999/",
              "cfg_epay_pid": "e2e-merchant", "cfg_epay_key": "e2e-key",
              "cfg_epay_mode": "submit", "fee_rate": "0", "min_amount": "0",
              "max_amount": "0"})
    r = ui.submit_form("admin/gateway/edit", d, id="epay")
    check("configure epay gateway", r.status_code in (200, 302))
    r = ui.post("admin/gateway/edit", {"id": "manual", "enabled": "1"}, id="manual")
    check("enable balance/manual gateway", r.status_code in (200, 302))

    # Keep registration entirely offline: this is the normal admin setting,
    # not a test-only bypass or application backdoor.
    r, d = ui.form("admin/settings/index")
    d["captcha_enable"] = "0"
    d["reg_email_verify"] = "0"
    check("disable registration email verification", ui.submit_form("admin/settings/index", d).status_code in (200, 302))

    # Cloudflare provider, test, sync, and two domains (paid + free).
    r, d = ui.form("admin/dnsprovider/edit")
    d.update({"name": "Local Cloudflare", "driver": "cloudflare",
              "cfg_cloudflare_token": "TOKENEXAMPLE", "cfg_cloudflare_endpoint": CF,
              "status": "1"})
    r = ui.submit_form("admin/dnsprovider/edit", d)
    check("add Cloudflare provider", r.status_code in (200, 302))
    dnsid = sql("select id from %s.idc_dns_provider order by id desc limit 1" % DB)
    if dnsid:
        check("Cloudflare test connection", no_php_error(ui.post("admin/dnsprovider/test", {"id": dnsid})))
        check("sync root domains", no_php_error(ui.post("admin/dnsprovider/syncDomains", {"id": dnsid})))
    did = sql("select id from %s.idc_dns_domain order by id desc limit 1" % DB)
    if did:
        r, d = ui.form("admin/domain/edit", id=did)
        d.update({"status": "1", "listing_status": "active", "price": "2",
                  "period_days": "365", "types": "A,AAAA,CNAME,TXT", "allow_proxy": "1"})
        check("enable paid root domain", ui.submit_form("admin/domain/edit", d, id=did).status_code in (200, 302))
        r, d = ui.form("admin/domain/edit")
        d.update({"domain": "free.e2e.test", "provider_id": dnsid, "remote_id": "zone-free",
                  "status": "1", "listing_status": "active", "price": "0",
                  "period_days": "365", "types": "A,AAAA,CNAME,TXT"})
        check("create free root domain", ui.submit_form("admin/domain/edit", d).status_code in (200, 302))

    # Tier, DNS package, coupon and card batch.
    r, d = ui.form("admin/tier/edit")
    d.update({"name": "E2E Gold", "level": "2", "price": "1", "period_days": "30",
              "discount": "95", "dns_max_subdomains": "10", "dns_max_records": "50",
              "status": "1", "purchasable": "1"})
    check("create member tier", ui.submit_form("admin/tier/edit", d).status_code in (200, 302))
    r, d = ui.form("admin/package/edit")
    d.update({"name": "E2E DNS package", "price": "3", "days": "365", "sub_quota": "5",
              "record_quota": "20", "types": "A,AAAA,CNAME,TXT", "status": "1", "stock": "-1"})
    check("create DNS package", ui.submit_form("admin/package/edit", d).status_code in (200, 302))
    r, d = ui.form("admin/coupon/edit")
    d.update({"code": "E2E10", "name": "E2E coupon", "type": "fixed", "value": "1",
              "scenes/a[]": "new", "item_types/a[]": "host", "min_amount": "1",
              "per_user_limit": "1", "status": "1"})
    check("create coupon", ui.submit_form("admin/coupon/edit", d).status_code in (200, 302))
    r, d = ui.form("admin/card/generate")
    d.update({"name": "E2E balance", "face_value": "20", "qty": "1", "prefix": "E2E",
              "per_user_limit": "1"})
    rr = ui.submit_form("admin/card/generate", d)
    check("generate recharge card batch", rr.status_code in (200, 302))

    # Register/login a real front-end user.
    email = "flow_a_%d@example.test" % int(time.time())
    r, d = ui.form("index/auth/register")
    d.update({"username": "flowA", "email": email, "password": "Passw0rd!", "captcha": ""})
    r = ui.submit_form("index/auth/register", d)
    check("register user A", r.status_code in (200, 302))
    r, d = ui.form("index/auth/login")
    d.update({"account": email, "password": "Passw0rd!", "captcha": ""})
    r = ui.submit_form("index/auth/login", d)
    check("login user A", r.status_code in (200, 302))
    for route in ("index/user/index", "index/host/index", "index/dns/index",
                  "index/order/index", "index/user/balance", "index/user/card",
                  "index/ticket/index", "index/user/profile", "index/user/notices",
                  "index/shop/index", "index/shop/domains", "index/shop/packages",
                  "index/market/index"):
        check("front crawl " + route, no_php_error(ui.get(route)))

    uid_user = sql("select id from %s.idc_user where email='%s'" % (DB, email))
    # Buy host through the host form, use coupon preview where available.
    pid2 = sql("select id from %s.idc_product where status=1 order by id asc limit 1" % DB)
    if pid2:
        r, d = ui.form("index/host/buy", id=pid2)
        d.update({"product_id": pid2, "cycle": "monthly", "hostname": "flow-a",
                  "os": "linux", "password": "RootPass123!", "coupon_code": "E2E10"})
        rr = ui.submit_form("index/host/buy", d)
        check("create cloud-host order with coupon", rr.status_code in (200, 302))
    oid = sql("select id from %s.idc_order where user_id=%s order by id desc limit 1" % (DB, uid_user or 0))
    check("host order exists", bool(oid))
    if oid:
        # Payment page creation is real UI; notify is a local signed callback.
        r, d = ui.form("index/pay/index", id=oid)
        d.update({"order_id": oid, "gateway": "epay", "channel": "pc"})
        rr = ui.submit_form("index/pay/index", d, id=oid)
        check("start Epay payment", rr.status_code in (200, 302))
        trade = sql("select trade_no from %s.payment where order_id=%s order by id desc limit 1" % (DB, oid))
        if trade:
            params = {"pid": "e2e-merchant", "trade_no": trade, "out_trade_no": trade,
                      "type": "alipay", "name": "E2E", "money": "12.00",
                      "trade_status": "TRADE_SUCCESS"}
            raw = "&".join("%s=%s" % (k, params[k]) for k in sorted(params)) + "e2e-key"
            params["sign_type"] = "MD5"
            params["sign"] = hashlib.md5(raw.encode()).hexdigest()
            rr = ui.s.get(ui.url("index/pay/notify", gw="epay"), params=params, timeout=15)
            check("signed Epay notify accepted", rr.status_code == 200 and "success" in rr.text.lower())
            check("order paid in database", sql("select status from %s.idc_order where id=%s" % (DB, oid)) == "paid")
            check("WHMCS mock provision recorded", "101" in sql("select upstream_sid from %s.idc_service where order_id=%s" % (DB, oid)))
            check("host detail page", no_php_error(ui.get("index/host/detail", id=oid)))

    # DNS free registration and record CRUD through UI.
    free = sql("select id from %s.idc_dns_domain where price=0 and status=1 order by id desc limit 1" % DB)
    if free:
        r, d = ui.form("index/dns/register")
        d.update({"domain_id": free, "prefix": "flowa"})
        rr = ui.submit_form("index/dns/register", d)
        check("register free subdomain", rr.status_code in (200, 302))
    sid = sql("select id from %s.idc_dns_subdomain where user_id=%s order by id desc limit 1" % (DB, uid_user or 0))
    if sid:
        r, d = ui.form("index/dns/records", id=sid)
        d.update({"sub_id": sid, "host": "@", "type": "A", "value": "198.51.100.10",
                  "line": "default", "ttl": "600", "mx": "10"})
        check("add DNS A record", ui.submit_form("index/dns/records", d, id=sid).status_code in (200, 302))
        r, d = ui.form("index/dns/records", id=sid)
        d.update({"sub_id": sid, "host": "_e2e", "type": "TXT", "value": "flow", "line": "default", "ttl": "600"})
        check("add DNS TXT record", ui.submit_form("index/dns/records", d, id=sid).status_code in (200, 302))
        rid = sql("select id from %s.idc_dns_record where subdomain_id=%s order by id desc limit 1" % (DB, sid))
        if rid:
            check("edit DNS record", ui.post("index/dns/records", {"id": rid, "sub_id": sid, "host": "_e2e", "type": "TXT", "value": "edited", "line": "default", "ttl": "600"}, id=sid).status_code in (200, 302))
            check("delete DNS record", ui.post("index/dns/deleteRecord", {"id": rid}).status_code in (200, 302))

    # Cron success and lifecycle suspension fixture/assertion.
    key = sql("select value from %s.idc_setting where name='cron_key'" % DB)
    rr = ui.get("api/cron/run", cron_key=key)
    check("web cron returns success", rr.status_code == 200 and not re.search(r"Fatal|Warning|Notice", rr.text, re.I))
    overdue = sql("select id from %s.idc_service order by id desc limit 1" % DB)
    if overdue:
        sql("update %s.idc_service set due_time=%d where id=%s" % (DB, int(time.time()) - 3 * 86400, overdue))
        rr = ui.get("api/cron/run", cron_key=key)
        check("cron suspends overdue service", sql("select status from %s.idc_service where id=%s" % (DB, overdue)) in ("suspended", "suspend"))

    # Admin list/detail/crawler is a final populated-site regression gate.
    for route in ("admin/order/index", "admin/order/detail", "admin/audit/index",
                  "admin/balance/index", "admin/operations/index", "admin/market_sale/index",
                  "admin/withdraw/index"):
        check("admin populated " + route, no_php_error(ui.get(route)))
    print("FLOW RESULT PASS=%d FAIL=%d" % (PASS, FAIL))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
