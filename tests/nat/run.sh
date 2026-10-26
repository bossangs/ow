#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
python3 -m py_compile "$ROOT/src/core/application/common/nat/agent/idcnat_agent.py"
bash -n "$ROOT/src/core/application/common/nat/install.sh.tpl"
shellcheck "$ROOT/src/core/application/common/nat/install.sh.tpl"
php7.0 -l "$ROOT/src/core/application/common/nat/NatScript.php"
php7.0 -l "$ROOT/src/core/application/common/upstream/NatNode.php"
chmod +x "$ROOT/tests/nat/fake-incus"
TD=$(mktemp -d); PID=''; PANELPID=''; trap 'test -z "$PID" || kill "$PID" 2>/dev/null || true; test -z "$PANELPID" || kill "$PANELPID" 2>/dev/null || true; rm -rf "$TD"' EXIT
ln -sf fake-incus "$ROOT/tests/nat/incus"
NAT_PANEL_LOG="$TD/panel.json" php7.0 -S 127.0.0.1:19183 "$ROOT/tests/nat/panel_mock.php" >"$TD/panel.log" 2>&1 & PANELPID=$!
cat >"$TD/config.json" <<JSON
{"bind":"127.0.0.1","port":19182,"agent_token":"local-test-token","backend":"incus","state_file":"$TD/state.json","port_start":20000,"port_end":20010,"max_instances":3,"images":["debian12"],"forward_mode":"proxy","node_id":"node-test","panel_url":"http://127.0.0.1:19183","heartbeat_interval":1}
JSON
FAKE_INCUS_STATE="$TD/fake.json" NAT_PANEL_LOG="$TD/panel.json" PATH="$ROOT/tests/nat:$PATH" IDCNAT_CONFIG="$TD/config.json" python3 "$ROOT/src/core/application/common/nat/agent/idcnat_agent.py" >"$TD/agent.log" 2>&1 & PID=$!
sleep .5
PORT=19182 TOKEN=local-test-token python3 - <<'PY'
import hashlib,hmac,json,os,time,urllib.request,urllib.error
T=os.environ['TOKEN']; port=os.environ['PORT']
def q(m,p,d=None,ts=None,nonce=None):
 b=json.dumps(d,separators=(',',':')).encode() if d is not None else b''; n=nonce or ('test'+str(time.time_ns())); t=str(ts if ts is not None else int(time.time())); s=hmac.new(T.encode(),('%s\n%s\n%s\n%s\n%s'%(t,n,m,p,hashlib.sha256(b).hexdigest())).encode(),hashlib.sha256).hexdigest(); r=urllib.request.Request('http://127.0.0.1:'+port+p,b,method=m,headers={'X-IDC-Ts':t,'X-IDC-Nonce':n,'X-IDC-Sign':s}); return json.loads(urllib.request.urlopen(r).read())
assert q('GET','/v1/info')['code']==0
x=q('POST','/v1/instances',{'name':'vmone','image':'debian12','password':'password1','nat_ports':2}); assert x['code']==0 and x['data']['port_start']==20000
assert q('POST','/v1/instances/vmone/action',{'action':'restart'})['code']==0
assert q('POST','/v1/instances/vmone/action',{'action':'suspend'})['code']==0
try: q('POST','/v1/instances/vmone/action',{'action':'start'}); raise AssertionError('suspended start')
except urllib.error.HTTPError: pass
assert q('POST','/v1/instances/vmone/action',{'action':'unsuspend'})['code']==0
n='replaynonce-123456'; q('GET','/v1/info',nonce=n)
try: q('GET','/v1/info',nonce=n); raise AssertionError('replay accepted')
except urllib.error.HTTPError: pass
try: q('GET','/v1/info',ts=int(time.time())-301); raise AssertionError('expired accepted')
except urllib.error.HTTPError: pass
assert q('DELETE','/v1/instances/vmone')['code']==0
print('NAT agent auth/lifecycle passed')
PY
php7.0 "$ROOT/tests/nat/driver_test.php"
php7.0 -r "require '$ROOT/src/core/application/common/nat/NatScript.php'; file_put_contents('$TD/rendered.sh', app\\common\\nat\\NatScript::render(array('panel_url'=>'http://127.0.0.1:19183','node_id'=>'4242','reg_token'=>'reg','agent_port'=>19184,'images'=>'debian12,ubuntu2404','tls'=>0)));"
bash -n "$TD/rendered.sh"; shellcheck "$TD/rendered.sh"
IDCNAT_DRY_RUN=1 IDCNAT_SKIP_INSTALL=1 IDCNAT_TEST_ALLOW_NONROOT=1 bash "$TD/rendered.sh" >/"$TD/render.log"
grep -q '"node_id":"4242"' "$TD/render.log"
echo 'NAT render/dry-run passed'
