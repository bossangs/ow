#!/usr/bin/env python3
import os
import re
import sys
import time
from urllib.parse import urlencode
import requests
from bs4 import BeautifulSoup

BASE = os.environ.get('IDC_BASE', 'http://127.0.0.1:8270').rstrip('/')
RESULTS = []

def check(name, ok, detail=''):
    RESULTS.append((name, bool(ok)))
    print(('PASS ' if ok else 'FAIL ') + name + ((' -> ' + detail) if not ok else ''), flush=True)

def url(route, **params):
    out = BASE + '/index.php?s=/' + route
    return out + ('&' + urlencode(params) if params else '')

class UI:
    def __init__(self):
        self.s = requests.Session(); self.token = ''
    def get(self, route, **params):
        r = self.s.get(url(route, **params), timeout=30); self.remember(r.text); return r
    def post(self, route, data=None, files=None):
        data = dict(data or {}); data.setdefault('_token', self.token)
        r = self.s.post(url(route), data=data, files=files, timeout=30); self.remember(r.text); return r
    def remember(self, html):
        m = re.search(r'name="_token" value="([^"]+)"', html)
        if m: self.token = m.group(1)
    def form(self, route):
        r = self.get(route); soup = BeautifulSoup(r.text, 'html.parser'); f = soup.find('form', method=re.compile('post', re.I)); data = {}
        if f:
            for x in f.find_all(['input', 'select', 'textarea']):
                n = x.get('name')
                if not n: continue
                if x.name == 'textarea': data[n] = x.text
                elif x.name == 'select':
                    o = x.find('option', selected=True) or x.find('option'); data[n] = o.get('value', '') if o else ''
                elif x.get('type') not in ('file', 'submit', 'checkbox', 'radio'): data[n] = x.get('value', '')
        return r, data

u = UI()
r = u.get('index/auth/register'); check('register page', r.status_code == 200 and 'username' in r.text)
name = 'ticket_' + str(int(time.time()))
r = u.post('index/auth/register', {'username': name, 'email': name + '@example.test', 'password': 'Demo@12345'})
if '登录' in r.text or 'login' in r.url: u.post('index/auth/login', {'account': name, 'password': 'Demo@12345'})
r, data = u.form('index/ticket/create'); check('ticket create form', r.status_code == 200 and 'attachments' in r.text)
data.update({'subject': 'E2E **XSS** ticket', 'department': '技术支持', 'priority': '高', 'related': '', 'content': '**Markdown** [safe](https://example.com) <script>alert(1)</script>'})
r = u.post('index/ticket/create', data, {'attachments[]': ('proof.png', b'\x89PNG\r\n\x1a\n' + b'0' * 20, 'image/png')})
check('ticket create submitted', r.status_code in (200, 302) and ('工单' in r.text or 'ticket' in r.url))
r = u.get('index/ticket/index'); check('ticket list', r.status_code == 200 and 'ticket' in r.text.lower())
m = re.search(r'/index.php\?s=/index/ticket/detail[^"]*', r.text)
if m:
    detail = m.group(0).replace('&amp;', '&'); r = u.s.get(BASE + detail); check('markdown escaped and rendered', '<strong>Markdown</strong>' in r.text and '<script>alert' not in r.text)
else: check('ticket detail link', False)

# Admin login and list/detail smoke.
a = UI(); r = a.get('admin/login/index'); check('admin login page', r.status_code == 200)
r = a.post('admin/login/index', {'username': 'admin', 'password': 'Admin@12345'}); r = a.get('admin/ticket/index'); check('admin ticket list', r.status_code == 200 and '工单' in r.text)
# Static checks for security invariants.
check('ticket files outside web root', 'DATA_PATH' in open('src/core/application/index/controller/Ticket.php').read())
check('csrf on ticket forms', 'csrf_field' in open('src/core/application/index/view/ticket/create.php').read())
print('TICKET TESTS: %d passed, %d failed' % (sum(1 for _, ok in RESULTS if ok), sum(1 for _, ok in RESULTS if not ok)))
sys.exit(0 if all(ok for _, ok in RESULTS) else 1)
