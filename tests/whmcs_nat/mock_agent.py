#!/usr/bin/env python3
import hashlib
import hmac
import json
import os
import re
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

TOKEN = os.environ.get('IDCNAT_TEST_TOKEN', 'local-test-token')
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
    server_version = 'IDCNATTest/1.0'

    def log_message(self, fmt, *args):
        return

    def reply(self, status, payload):
        raw = json.dumps(payload, separators=(',', ':')).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def authenticate(self, body):
        ts = self.headers.get('X-IDC-Ts', '')
        nonce = self.headers.get('X-IDC-Nonce', '')
        signature = self.headers.get('X-IDC-Sign', '')
        try:
            if abs(int(time.time()) - int(ts)) > 300:
                return False
        except (TypeError, ValueError):
            return False
        if not re.match(r'^[A-Za-z0-9]{16,128}$', nonce):
            return False
        signed = '%s\n%s\n%s\n%s\n%s' % (
            ts,
            nonce,
            self.command,
            self.path,
            hashlib.sha256(body).hexdigest(),
        )
        expected = hmac.new(TOKEN.encode(), signed.encode(), hashlib.sha256).hexdigest()
        return hmac.compare_digest(expected, signature)

    def do_GET(self):
        body = b''
        if not self.authenticate(body):
            return self.reply(401, {'code': 1, 'msg': 'unauthorized'})
        path = urlsplit(self.path).path
        if path == '/v1/info':
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': {
                'hostname': 'mock-node',
                'version': 'test',
                'images': ['debian12', 'ubuntu2404'],
                'max_instances': 20,
            }})
        match = re.match(r'^/v1/instances/([a-z0-9-]+)$', path)
        if match:
            state = load_state()
            instance = state['instances'].get(match.group(1))
            if instance is None:
                return self.reply(404, {'code': 1, 'msg': 'instance not found'})
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': instance})
        return self.reply(404, {'code': 1, 'msg': 'not found'})

    def do_POST(self):
        length = int(self.headers.get('Content-Length', '0'))
        body = self.rfile.read(length)
        if not self.authenticate(body):
            return self.reply(401, {'code': 1, 'msg': 'unauthorized'})
        try:
            data = json.loads(body.decode('utf-8')) if body else {}
        except ValueError:
            return self.reply(400, {'code': 1, 'msg': 'bad json'})
        path = urlsplit(self.path).path
        if path == '/v1/instances':
            name = data.get('name', '')
            if not re.match(r'^[a-z][a-z0-9-]{2,30}$', name):
                return self.reply(400, {'code': 1, 'msg': 'invalid name'})
            state = load_state()
            if name in state['instances']:
                return self.reply(400, {'code': 1, 'msg': 'instance exists'})
            instance = {
                'name': name,
                'status': 'running',
                'image': data.get('image', ''),
                'public_ipv4': '203.0.113.20',
                'ipv4_internal': '10.88.0.10',
                'ipv6': '',
                'ssh_port': 21000,
                'port_start': 21000,
                'port_end': 21000 + int(data.get('nat_ports', 0)),
                'cpu': int(data.get('cpu', 1)),
                'memory_mb': int(data.get('memory_mb', 512)),
                'disk_gb': int(data.get('disk_gb', 10)),
                '_password': data.get('password', ''),
                '_suspended': False,
            }
            state['instances'][name] = instance
            save_state(state)
            public = {key: value for key, value in instance.items() if not key.startswith('_')}
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': public})
        match = re.match(r'^/v1/instances/([a-z0-9-]+)/action$', path)
        if match:
            state = load_state()
            instance = state['instances'].get(match.group(1))
            if instance is None:
                return self.reply(404, {'code': 1, 'msg': 'instance not found'})
            action = data.get('action')
            if action == 'suspend':
                instance['status'] = 'stopped'
                instance['_suspended'] = True
            elif action == 'unsuspend':
                instance['status'] = 'running'
                instance['_suspended'] = False
            elif action == 'start':
                if instance['_suspended']:
                    return self.reply(400, {'code': 1, 'msg': 'instance suspended'})
                instance['status'] = 'running'
            elif action == 'stop':
                instance['status'] = 'stopped'
            elif action == 'restart':
                instance['status'] = 'running'
            elif action == 'reset_password':
                instance['_password'] = data.get('password', '')
            else:
                return self.reply(400, {'code': 1, 'msg': 'unsupported action'})
            state['instances'][match.group(1)] = instance
            save_state(state)
            public = {key: value for key, value in instance.items() if not key.startswith('_')}
            return self.reply(200, {'code': 0, 'msg': 'ok', 'data': public})
        return self.reply(404, {'code': 1, 'msg': 'not found'})

    def do_DELETE(self):
        body = b''
        if not self.authenticate(body):
            return self.reply(401, {'code': 1, 'msg': 'unauthorized'})
        path = urlsplit(self.path).path
        match = re.match(r'^/v1/instances/([a-z0-9-]+)$', path)
        if not match:
            return self.reply(404, {'code': 1, 'msg': 'not found'})
        state = load_state()
        if match.group(1) not in state['instances']:
            return self.reply(404, {'code': 1, 'msg': 'instance not found'})
        del state['instances'][match.group(1)]
        save_state(state)
        return self.reply(200, {'code': 0, 'msg': 'ok', 'data': {'deleted': match.group(1)}})


if __name__ == '__main__':
    host = os.environ.get('IDCNAT_TEST_HOST', '127.0.0.1')
    port = int(os.environ.get('IDCNAT_TEST_PORT', '19184'))
    ThreadingHTTPServer((host, port), Handler).serve_forever()
