#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""IDCDNS NAT node agent; Python 3.6-compatible standard library only."""
from __future__ import print_function
import hashlib, hmac, json, logging, os, re, socket, socketserver, ssl, subprocess, threading, time
import urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

VERSION = '1.1.0'
NAME_RE = re.compile(r'^[a-z][a-z0-9-]{2,30}$')

def now(): return int(time.time())
def jdump(x): return json.dumps(x, ensure_ascii=False, separators=(',', ':'))
def fail(msg, code=1): return {'code': code, 'msg': msg}

class State(object):
    def __init__(self, cfg):
        self.cfg = cfg; self.path = cfg.get('state_file', '/var/lib/idcnat/state.json'); self.lock = threading.RLock()
        self.data = {'instances': {}, 'nonces': {}}
        try:
            with open(self.path, 'r') as f: self.data.update(json.load(f))
        except Exception: pass
        self.data.setdefault('instances', {}); self.data.setdefault('nonces', {})
    def save(self):
        d = os.path.dirname(self.path)
        if d and not os.path.isdir(d): os.makedirs(d)
        tmp = self.path + '.tmp'
        with open(tmp, 'w') as f: json.dump(self.data, f, ensure_ascii=False, indent=2)
        try: os.chmod(tmp, 0o600)
        except OSError: pass
        os.replace(tmp, self.path)
    def clean_nonces(self):
        cutoff = now() - 600
        self.data['nonces'] = dict((k, v) for k, v in self.data['nonces'].items() if int(v) >= cutoff)

class Backend(object):
    def __init__(self, cfg):
        self.cfg = cfg; self.kind = cfg.get('backend', 'auto')
        if self.kind == 'auto': self.kind = 'incus' if self.which('incus') else 'lxd'
        self.bin = self.kind
    def which(self, x):
        for p in os.environ.get('PATH', '').split(os.pathsep):
            q = os.path.join(p, x)
            if os.path.isfile(q) and os.access(q, os.X_OK): return q
        return None
    def run(self, args, check=True):
        if not isinstance(args, list): raise ValueError('命令参数无效')
        p = subprocess.Popen([self.bin] + args, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        out, err = p.communicate()
        if check and p.returncode: raise RuntimeError((err or out).decode('utf-8', 'replace')[:500])
        return out.decode('utf-8', 'replace').strip()
    def info(self, name):
        raw = self.run(['info', name, '--format', 'json'])
        try: return json.loads(raw)
        except Exception: return {}
    def exists(self, name):
        try: self.info(name); return True
        except Exception: return False
    def launch(self, name, image): self.run(['launch', image, name])
    def config(self, name, key, value): self.run(['config', name, key, str(value)])
    def device_add(self, name, dev, typ, **kwargs):
        a = ['config', 'device', 'add', name, dev, typ]
        for k, v in kwargs.items(): a.extend([k, str(v)])
        self.run(a)
    def start(self, n): self.run(['start', n])
    def stop(self, n): self.run(['stop', n])
    def delete(self, n): self.run(['delete', n])
    def exec(self, n, cmd, input_data=None):
        p = subprocess.Popen([self.bin, 'exec', n, '--'] + cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        out, err = p.communicate(input_data.encode() if input_data is not None else None)
        if p.returncode: raise RuntimeError(err.decode('utf-8', 'replace'))
        return out.decode('utf-8', 'replace')

class App(object):
    def __init__(self, cfg):
        self.cfg = cfg; self.state = State(cfg); self.backend = Backend(cfg); self.token = str(cfg.get('agent_token', '')); self.lock = self.state.lock
    def auth(self, h, method, path, body):
        try: ts = int(h.get('X-IDC-Ts', '0')); nonce = h.get('X-IDC-Nonce', ''); sig = h.get('X-IDC-Sign', '')
        except Exception: return False, '认证头无效'
        if abs(now() - ts) > 300: return False, '请求已过期'
        if len(nonce) < 16 or len(nonce) > 128: return False, '随机数无效'
        with self.lock:
            self.state.clean_nonces()
            if nonce in self.state.data['nonces']: return False, '请求重复'
            msg = str(ts) + '\n' + nonce + '\n' + method + '\n' + path + '\n' + hashlib.sha256(body).hexdigest()
            expected = hmac.new(self.token.encode(), msg.encode(), hashlib.sha256).hexdigest()
            if not hmac.compare_digest(expected, sig): return False, '签名错误'
            self.state.data['nonces'][nonce] = now(); self.state.save()
        return True, ''
    def validate_name(self, n):
        if not isinstance(n, str) or not NAME_RE.match(n): raise ValueError('实例名称无效')
    def ports(self):
        u = []
        for x in self.state.data['instances'].values(): u += list(range(int(x['port_start']), int(x['port_end']) + 1))
        return u
    def alloc(self, count):
        start = int(self.cfg.get('port_start', 20000)); end = int(self.cfg.get('port_end', 30000)); used = set(self.ports())
        for p in range(start, end - count + 2):
            if all(q not in used for q in range(p, p + count)): return p, p + count - 1
        raise ValueError('端口资源不足')
    def image_ok(self, img):
        for x in self.cfg.get('images', []):
            if isinstance(x, dict) and img in (x.get('alias'), x.get('name')): return True
            if img == x: return True
        return False
    def internal_ip(self, name):
        deadline = time.time() + 60
        while time.time() < deadline:
            try:
                i = self.backend.info(name)
                net = i.get('state', {}).get('network', {}) if isinstance(i, dict) else {}
                for v in net.values():
                    for a in v.get('addresses', []) if isinstance(v, dict) else []:
                        if a.get('family') == 'inet' and not a.get('address', '').startswith('127.'):
                            return a.get('address')
                ip = i.get('ipv4') or i.get('ipv4_internal')
                if ip: return ip
            except Exception: pass
            time.sleep(1)
        return ''
    def forwarding(self, n, x, remove=False):
        mode = self.cfg.get('forward_mode', 'proxy'); a, b = int(x['port_start']), int(x['port_end'])
        if mode == 'proxy':
            if remove: return
            self.backend.device_add(n, 'ssh', 'proxy', listen='tcp:0.0.0.0:%d' % a, connect='tcp:127.0.0.1:22')
            if b >= a + 1:
                self.backend.device_add(n, 'nat-tcp', 'proxy', listen='tcp:0.0.0.0:%d-%d' % (a + 1, b), connect='tcp:127.0.0.1:%d-%d' % (a + 1, b))
                self.backend.device_add(n, 'nat-udp', 'proxy', listen='udp:0.0.0.0:%d-%d' % (a + 1, b), connect='udp:127.0.0.1:%d-%d' % (a + 1, b))
            return
        ip = x.get('ipv4_internal', '')
        if not ip: return
        if remove:
            self.iptables(['-D', 'IDCNAT', '-d', ip, '-p', 'tcp', '--dport', '%d:%d' % (a, b), '-j', 'DNAT', '--to-destination', ip])
            self.iptables(['-D', 'IDCNAT', '-d', ip, '-p', 'udp', '--dport', '%d:%d' % (a, b), '-j', 'DNAT', '--to-destination', ip])
        else:
            self.iptables(['-A', 'IDCNAT', '-p', 'tcp', '--dport', '%d:%d' % (a, b), '-j', 'DNAT', '--to-destination', ip])
            self.iptables(['-A', 'IDCNAT', '-p', 'udp', '--dport', '%d:%d' % (a, b), '-j', 'DNAT', '--to-destination', ip])
    def iptables(self, args):
        try: subprocess.call(['iptables'] + args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        except OSError: pass
    def apply_all_rules(self):
        if self.cfg.get('forward_mode', 'proxy') != 'iptables': return
        self.iptables(['-N', 'IDCNAT'])
        self.iptables(['-F', 'IDCNAT'])
        for n, x in self.state.data['instances'].items(): self.forwarding(n, x)
    def detail(self, n, x):
        try: bi = self.backend.info(n)
        except Exception: bi = {}
        st = bi.get('status', 'stopped'); st = st.lower() if isinstance(st, str) else 'stopped'
        return {'name': n, 'status': st, 'image': x.get('image', ''), 'ipv4_internal': x.get('ipv4_internal', ''), 'ipv6': x.get('ipv6', ''), 'public_ipv4': self.cfg.get('public_ipv4', ''), 'ssh_port': x['port_start'], 'port_start': x['port_start'], 'port_end': x['port_end'], 'cpu': x.get('cpu', 1), 'memory_mb': x.get('memory_mb', 512), 'disk_gb': x.get('disk_gb', 10), 'created_at': x.get('created_at', '')}
    def set_password(self, n, password, enable=True):
        if not isinstance(password, str) or len(password) < 8 or len(password) > 128 or '\n' in password: raise ValueError('密码长度或格式无效')
        self.backend.exec(n, ['sh', '-c', 'chpasswd'], 'root:' + password + '\n')
        if enable:
            cmds = [['sh', '-c', 'command -v apk >/dev/null && apk add --no-cache openssh || true'], ['sh', '-c', 'command -v apk >/dev/null && rc-update add sshd default || true'], ['sh', '-c', "command -v apt-get >/dev/null && (apt-get update -y && DEBIAN_FRONTEND=noninteractive apt-get install -y openssh-server) || true"], ['sh', '-c', "sed -i -E 's/^#?PermitRootLogin.*/PermitRootLogin yes/; s/^#?PasswordAuthentication.*/PasswordAuthentication yes/' /etc/ssh/sshd_config 2>/dev/null || true"], ['sh', '-c', 'command -v systemctl >/dev/null && systemctl enable --now ssh || service ssh start || true']]
            for c in cmds:
                try: self.backend.exec(n, c)
                except Exception: pass
    def configure(self, n, x, p):
        self.backend.config(n, 'limits.cpu', x['cpu']); self.backend.config(n, 'limits.memory', str(x['memory_mb']) + 'MB'); self.backend.config(n, 'limits.root.size', str(x['disk_gb']) + 'GB')
        if int(p.get('swap_mb', 0)) > 0: self.backend.config(n, 'limits.memory.swap', str(int(p['swap_mb'])) + 'MB')
        bw = int(p.get('bandwidth_mbps', 0))
        if bw > 0: self.backend.config(n, 'limits.ingress', str(bw) + 'Mbit'); self.backend.config(n, 'limits.egress', str(bw) + 'Mbit')
        if p.get('ipv6'): self.backend.config(n, 'security.nesting', 'true')
    def create(self, p):
        n = p.get('name'); self.validate_name(n)
        if n in self.state.data['instances'] or self.backend.exists(n): raise ValueError('实例已存在')
        img = p.get('image')
        if not self.image_ok(img): raise ValueError('镜像不在允许列表')
        cpu = int(p.get('cpu', 1)); mem = int(p.get('memory_mb', 512)); disk = int(p.get('disk_gb', 10)); extra = int(p.get('nat_ports', 0))
        if cpu < 1 or cpu > 128 or mem < 64 or disk < 1 or extra < 0 or extra > 10000: raise ValueError('资源参数无效')
        a, b = self.alloc(extra + 1); x = {'image': img, 'port_start': a, 'port_end': b, 'cpu': cpu, 'memory_mb': mem, 'disk_gb': disk, 'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'suspended': False}
        self.backend.launch(n, img); self.configure(n, x, p)
        if p.get('password'): self.set_password(n, p['password'])
        x['ipv4_internal'] = self.internal_ip(n); self.state.data['instances'][n] = x; self.state.save()
        self.forwarding(n, x); self.state.save(); return self.detail(n, x)
    def action(self, n, act, p):
        if n not in self.state.data['instances']: raise ValueError('实例不存在')
        x = self.state.data['instances'][n]
        if act == 'start' and x.get('suspended'): raise ValueError('实例已暂停')
        if act in ('start', 'unsuspend'): self.backend.start(n); x['suspended'] = False
        elif act in ('stop', 'suspend'): self.backend.stop(n); x['suspended'] = act == 'suspend'
        elif act in ('restart', 'reboot'): self.backend.stop(n); self.backend.start(n)
        elif act == 'reinstall':
            img = p.get('image', x['image'])
            if not self.image_ok(img): raise ValueError('镜像不在允许列表')
            try: self.backend.stop(n)
            except Exception: pass
            self.backend.delete(n); self.backend.launch(n, img); x['image'] = img; self.configure(n, x, p); x['ipv4_internal'] = self.internal_ip(n); self.forwarding(n, x)
        elif act == 'reset_password': self.set_password(n, p.get('password', ''), False)
        else: raise ValueError('不支持的操作')
        self.state.save(); return self.detail(n, x)
    def info(self):
        running = 0
        for n in self.state.data['instances']:
            try:
                if str(self.backend.info(n).get('status', '')).lower() == 'running': running += 1
            except Exception: pass
        return {'version': VERSION, 'backend': self.backend.kind, 'hostname': socket.gethostname(), 'public_ipv4': self.cfg.get('public_ipv4', ''), 'public_ipv6': self.cfg.get('public_ipv6', ''), 'cpu_cores': os.cpu_count() or 1, 'mem_mb': 0, 'disk_gb': 0, 'instances_total': len(self.state.data['instances']), 'instances_running': running, 'port_start': self.cfg.get('port_start', 20000), 'port_end': self.cfg.get('port_end', 30000), 'ports_used': len(self.ports()), 'max_instances': self.cfg.get('max_instances', 100), 'images': self.cfg.get('images', [])}
    def heartbeat(self):
        while True:
            try:
                ts = str(now()); node = str(self.cfg.get('node_id', '')); payload = {'node_id': node, 'ts': int(ts), 'sign': hmac.new(self.token.encode(), (node + '|' + ts).encode(), hashlib.sha256).hexdigest(), 'instances_total': len(self.state.data['instances']), 'instances_running': self.info()['instances_running'], 'ports_used': len(self.ports()), 'load1': os.getloadavg()[0] if hasattr(os, 'getloadavg') else 0, 'mem_used_mb': 0, 'version': VERSION}
                raw = json.dumps(payload).encode(); url = self.cfg.get('panel_url', '').rstrip('/') + '/index.php?s=/api/nat/heartbeat'; req = urllib.request.Request(url, raw, {'Content-Type': 'application/json'}, method='POST'); urllib.request.urlopen(req, timeout=10).read()
            except Exception as e: logging.warning('heartbeat failed: %s', e)
            time.sleep(60)

class Handler(BaseHTTPRequestHandler):
    server_version = 'idcnat/' + VERSION
    def log_message(self, fmt, *args): logging.info(fmt, *args)
    def reply(self, d, status=200):
        b = jdump(d).encode('utf8'); self.send_response(status); self.send_header('Content-Type', 'application/json; charset=utf-8'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def do(self):
        ln = int(self.headers.get('Content-Length', '0') or 0); body = self.rfile.read(ln); path = urllib.parse.urlsplit(self.path).path; ok, msg = self.server.app.auth(self.headers, self.command, self.path, body)
        if not ok: return self.reply(fail(msg), 401)
        try:
            p = json.loads(body.decode('utf8')) if body else {}; parts = path.strip('/').split('/'); app = self.server.app
            if self.command == 'GET' and path == '/v1/info': d = app.info()
            elif self.command == 'GET' and path == '/v1/images': d = app.cfg.get('images', [])
            elif len(parts) == 3 and parts[:2] == ['v1', 'instances'] and self.command == 'GET': d = app.detail(parts[2], app.state.data['instances'][parts[2]])
            elif self.command == 'POST' and path == '/v1/instances':
                if len(app.state.data['instances']) >= int(app.cfg.get('max_instances', 100)): raise ValueError('已达到实例数量上限')
                d = app.create(p)
            elif len(parts) == 4 and parts[:2] == ['v1', 'instances'] and parts[3] == 'action' and self.command == 'POST': d = app.action(parts[2], p.get('action'), p)
            elif len(parts) == 3 and parts[:2] == ['v1', 'instances'] and self.command == 'DELETE':
                n = parts[2]; x = app.state.data['instances'].get(n)
                if not x: raise ValueError('实例不存在')
                app.forwarding(n, x, True)
                try: app.backend.delete(n)
                finally: del app.state.data['instances'][n]; app.state.save()
                d = {'deleted': n}
            else: return self.reply(fail('接口不存在'), 404)
            self.reply({'code': 0, 'msg': 'ok', 'data': d})
        except KeyError: self.reply(fail('实例不存在'), 404)
        except Exception as e: logging.exception('请求失败'); self.reply(fail(str(e) or '操作失败'), 400)
    do_GET = do; do_POST = do; do_DELETE = do

class Server(socketserver.ThreadingMixIn, HTTPServer): daemon_threads = True

def main():
    path = os.environ.get('IDCNAT_CONFIG', '/etc/idcnat/agent.json')
    with open(path, 'r') as f: cfg = json.load(f)
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s'); srv = Server((cfg.get('bind', '0.0.0.0'), int(cfg.get('port', 9180))), Handler); srv.app = App(cfg); srv.app.apply_all_rules()
    if cfg.get('panel_url') and cfg.get('node_id'): threading.Thread(target=srv.app.heartbeat, daemon=True).start()
    if cfg.get('tls'):
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cfg['tls_cert'], cfg['tls_key']); srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
    logging.info('NAT agent listening on %s', cfg.get('port', 9180)); srv.serve_forever()
if __name__ == '__main__': main()
