#!/usr/bin/env python3
"""后台全页面爬取：登录后访问菜单里所有页面和每个列表的第一条编辑页、新增页，检查 HTTP 状态和 PHP 错误。
用法：python3 tests/e2e/admin_crawl.py http://127.0.0.1:8194 admin Admin@12345
"""
import re
import sys
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup

BASE, USER, PWD = sys.argv[1], sys.argv[2], sys.argv[3]
ERR = re.compile(r"(Fatal error|Parse error|Warning:|Notice:|Undefined (index|variable|offset)|"
                 r"Stack trace|ThinkPHP V|系统发生错误|think\\exception|Call to undefined)", re.I)
s = requests.Session()


def token(html):
    m = re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else ""


r = s.get(BASE + "/index.php?s=/admin/login/index")
r = s.post(BASE + "/index.php?s=/admin/login/index",
           data={"_token": token(r.text), "username": USER, "password": PWD, "captcha": ""})
if "退出" not in r.text and "logout" not in r.text:
    print("LOGIN FAILED", r.status_code, r.text[:500])
    sys.exit(1)

seen, fails, queue = set(), [], [BASE + "/index.php?s=/admin/index/index"]
pages = 0
while queue:
    url = queue.pop(0)
    if url in seen:
        continue
    seen.add(url)
    r = s.get(url, allow_redirects=True)
    pages += 1
    bad = r.status_code >= 400 or ERR.search(r.text)
    if bad:
        m = ERR.search(r.text)
        fails.append((r.status_code, url, (m.group(0) if m else "") + " | " +
                      re.sub(r"\s+", " ", BeautifulSoup(r.text, "html.parser").get_text())[:300]))
        continue
    soup = BeautifulSoup(r.text, "html.parser")
    for a in soup.find_all("a", href=True):
        href = urljoin(url, a["href"])
        if not href.startswith(BASE) or "s=/admin/" not in href:
            continue
        if any(x in href for x in ("logout", "delete", "export", "download", "/admin/login")):
            continue
        # 每个控制器只抓 index、第一条 edit、新增页，避免爆炸
        m = re.search(r"s=/admin/([a-z_]+)/([a-zA-Z_]+)", href)
        if not m:
            continue
        key = (m.group(1), m.group(2), "id=" in href)
        if key in {(k[0], k[1], k[2]) for k in getattr(sys, "_keys", set())}:
            continue
        sys._keys = getattr(sys, "_keys", set()) | {key}
        queue.append(href)

print("pages visited:", pages)
for f in fails:
    print("FAIL", f[0], f[1], "\n    ", f[2])
print("RESULT:", "PASS" if not fails else "%d FAIL" % len(fails))
sys.exit(1 if fails else 0)
