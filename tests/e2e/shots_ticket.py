#!/usr/bin/env python3
import os
import sys
from playwright.sync_api import sync_playwright
BASE = os.environ.get('IDC_BASE', 'http://127.0.0.1:8270').rstrip('/')
OUT = os.path.join(os.path.dirname(__file__), '../../shots/tickets')
os.makedirs(OUT, exist_ok=True)
def url(route): return BASE + '/index.php?s=/' + route

def login(page, admin=False):
    page.goto(url('admin/login/index' if admin else 'index/auth/login'))
    page.fill('input[name=username]' if admin else 'input[name=account], input[name=email]', 'admin' if admin else os.environ.get('IDC_USER', 'demo'))
    page.fill('input[name=password]', 'Admin@12345' if admin else os.environ.get('IDC_PASS', 'Demo@12345'))
    page.click('form button'); page.wait_for_load_state('networkidle')

def shot(page, name, route):
    page.goto(url(route)); page.wait_for_load_state('networkidle'); page.screenshot(path=os.path.join(OUT, name + '.png'), full_page=True)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    for size, suffix in [({'width': 1366, 'height': 900}, 'desktop'), ({'width': 390, 'height': 844}, 'mobile')]:
        context = browser.new_context(viewport=size, locale='zh-CN'); page = context.new_page()
        try:
            login(page); shot(page, 'user-list-' + suffix, 'index/ticket/index'); shot(page, 'new-ticket-' + suffix, 'index/ticket/create')
        except Exception as exc: print('user screenshot skipped:', exc)
        context.close()
    for size, suffix in [({'width': 1366, 'height': 900}, 'desktop'), ({'width': 390, 'height': 844}, 'mobile')]:
        context = browser.new_context(viewport=size, locale='zh-CN'); page = context.new_page()
        try:
            login(page, True); shot(page, 'admin-list-' + suffix, 'admin/ticket/index'); shot(page, 'canned-' + suffix, 'admin/ticket/canned'); shot(page, 'departments-' + suffix, 'admin/ticket/departments'); shot(page, 'satisfaction-' + suffix, 'admin/satisfaction/index'); shot(page, 'ticket-settings-' + suffix, 'admin/settings/index')
        except Exception as exc: print('admin screenshot skipped:', exc)
        context.close()
    browser.close()
print('screenshots:', sorted(os.listdir(OUT)))
