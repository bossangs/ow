#!/usr/bin/env python3
import json
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

TOKEN = os.environ.get('IDCNAT_TEST_TOKEN', '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef')
STATE = os.environ['IDCNAT_TEST_STATE']


def load_state():
    try:
        with open(STATE, 'r', encoding='utf-8') as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return {'instances': {}}


def save_state(state):
    with open(STATE, 'w', encoding='utf-8') as handle:
        json.dump(state, handle)


class Handler(BaseHTTPRequestHandler):
    server_version = 'IDCNATPanelTest/1.0'

    def log_message(self, fmt, *args):
        return

    def reply(self, status, payload):
        raw = json.dumps(payload, separators=(',', ':')).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def authorized(self):
        return self.headers.get('Authorization', '') == 'Bearer ' + TOKEN

    def read_body(self):
        length = int(self.headers.get('Content-Length', '0'))
        raw = self.rfile.read(length)
        try:
            return json.loads(raw.decode('utf-8')) if raw else {}
        except ValueError:
            return None

    def route(self):
        query = parse_qs(urlsplit(self.path).query)
        value = query.get('s', [''])[0]
        return value.rsplit('/', 1)[-1], query

    def do_GET(self):
        if not self.authorized():
            return self.reply(401, {'code': 401, 'msg': 'bridge key invalid'})
        route, query = self.route()
        if route == 'whmcsInfo':
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': {
                'site_name': 'mock-idcdns',
                'nodes': [
                    {'id': 7, 'name': 'mock-node', 'state': 'online', 'public_ip': '203.0.113.20', 'used': 1, 'capacity': 20, 'available': 19, 'images': ['debian12', 'ubuntu2404']},
                ],
            }})
        if route == 'whmcsGet':
            external_id = query.get('external_id', [''])[0]
            data = load_state()['instances'].get(external_id)
            if data is None:
                return self.reply(404, {'code': 404, 'msg': 'service not found'})
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': data})
        return self.reply(404, {'code': 404, 'msg': 'not found'})

    def do_POST(self):
        if not self.authorized():
            return self.reply(401, {'code': 401, 'msg': 'bridge key invalid'})
        route, query = self.route()
        body = self.read_body()
        if body is None:
            return self.reply(400, {'code': 400, 'msg': 'bad json'})
        state = load_state()
        if route == 'whmcsCreate':
            external_id = str(body.get('external_id', ''))
            if not external_id.isdigit():
                return self.reply(422, {'code': 422, 'msg': 'external id invalid'})
            if external_id not in state['instances']:
                instance = {
                    'status': 'active',
                    'ip': '203.0.113.20',
                    'ips': ['203.0.113.20'],
                    'username': 'root',
                    'hostname': 'vm-' + external_id,
                    'os': body.get('image', ''),
                    'ssh_port': 21000,
                    'port_range': '21000-21005',
                    'ipv4_internal': '10.88.0.10',
                    'ipv6': '',
                    'raw': {
                        'name': 'vm-' + external_id,
                        'status': 'running',
                        'public_ipv4': '203.0.113.20',
                        'port_start': 21000,
                        'port_end': 21005,
                        'cpu': int(body.get('cpu', 1)),
                        'memory_mb': int(body.get('memory_mb', 512)),
                        'disk_gb': int(body.get('disk_gb', 10)),
                    },
                }
                state['instances'][external_id] = {
                    'external_id': external_id,
                    'node_id': 7,
                    'node_name': 'mock-node',
                    'instance': instance,
                    'request': {key: value for key, value in body.items() if key != 'password'},
                    '_password': body.get('password', ''),
                    '_suspended': False,
                }
                save_state(state)
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': state['instances'][external_id]})
        if route == 'whmcsAction':
            external_id = str(query.get('external_id', [''])[0])
            entry = state['instances'].get(external_id)
            if entry is None:
                return self.reply(404, {'code': 404, 'msg': 'service not found'})
            instance = entry['instance']
            action = body.get('action')
            if action == 'suspend':
                instance['status'] = 'suspended'
                entry['_suspended'] = True
            elif action == 'unsuspend':
                instance['status'] = 'active'
                entry['_suspended'] = False
            elif action == 'start':
                instance['status'] = 'active'
            elif action == 'stop':
                instance['status'] = 'suspended'
            elif action == 'restart':
                instance['status'] = 'active'
            elif action == 'reset_password':
                entry['_password'] = body.get('password', '')
            else:
                return self.reply(422, {'code': 422, 'msg': 'unsupported action'})
            entry['instance'] = instance
            state['instances'][external_id] = entry
            save_state(state)
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': entry})
        if route == 'whmcsTerminate':
            external_id = str(body.get('external_id', ''))
            entry = state['instances'].get(external_id)
            if entry is not None:
                entry['instance']['status'] = 'terminated'
                entry['_password'] = ''
                state['instances'][external_id] = entry
                save_state(state)
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': {'external_id': external_id, 'status': 'terminated'}})
        return self.reply(404, {'code': 404, 'msg': 'not found'})


if __name__ == '__main__':
    host = os.environ.get('IDCNAT_TEST_HOST', '127.0.0.1')
    port = int(os.environ.get('IDCNAT_TEST_PORT', '19184'))
    ThreadingHTTPServer((host, port), Handler).serve_forever()
