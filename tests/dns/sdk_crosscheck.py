#!/usr/bin/env python3
"""Compare every captured localhost request with the installed vendor SDK signers."""
import base64, hashlib, hmac, json, os, re, sys
from urllib.parse import urlsplit, parse_qsl, quote, quote_plus
ROOT = os.path.dirname(os.path.abspath(__file__))
CAP = '/tmp/dns_capture.jsonl'
OUT = os.path.join(ROOT, 'sdk_crosscheck_results.json')
AK, SK = 'AKIDtest123', 'SKtest456'
EPOCH = 1700000000

def hdr(h, name, default=''):
    for k, v in h.items():
        if k.lower() == name.lower(): return v
    return default

def enc(v): return quote(str(v), safe='~')
def hmachex(k, s): return hmac.new(k, s.encode(), hashlib.sha256).hexdigest()
def canonical_query(q):
    return '&'.join(enc(k) + '=' + enc(v) for k, v in sorted(parse_qsl(q, keep_blank_values=True)))
def auth_sig(provider, req):
    u = urlsplit(req['uri']); prefix = '/' + u.path.strip('/').split('/')[0]
    path = u.path[len(prefix):] or '/'; query = u.query; hs = req['headers']; body = req.get('body','')
    host = hdr(hs, 'Host'); method = req['method']; auth = hdr(hs, 'Authorization')
    if provider == 'Dnspod':
        ts = int(hdr(hs, 'X-TC-Timestamp')); date = __import__('datetime').datetime.utcfromtimestamp(ts).strftime('%Y-%m-%d')
        ct = hdr(hs, 'Content-Type'); action = hdr(hs, 'X-TC-Action'); version = hdr(hs, 'X-TC-Version')
        ch = 'content-type:%s\nhost:%s\n' % (ct, host)
        cr = 'POST\n/\n\n' + ch + '\ncontent-type;host\n' + hashlib.sha256(body.encode()).hexdigest()
        sts = 'TC3-HMAC-SHA256\n%d\n%s/dnspod/tc3_request\n%s' % (ts, date, hashlib.sha256(cr.encode()).hexdigest())
        kd = hmac.new(('TC3'+SK).encode(), date.encode(), hashlib.sha256).digest(); ks = hmac.new(kd, b'dnspod', hashlib.sha256).digest(); key = hmac.new(ks, b'tc3_request', hashlib.sha256).digest()
        expected = 'TC3-HMAC-SHA256 Credential=%s/%s/dnspod/tc3_request, SignedHeaders=content-type;host, Signature=%s' % (AK,date,hmac.new(key,sts.encode(),hashlib.sha256).hexdigest())
        return expected, auth
    if provider == 'Aliyun':
        params = dict(parse_qsl(query, keep_blank_values=True)); sig = params.pop('Signature'); canon = '&'.join(enc(k)+'='+enc(v) for k,v in sorted(params.items()))
        sts = 'GET&%2F&' + enc(canon); expected = base64.b64encode(hmac.new((SK+'&').encode(), sts.encode(), hashlib.sha1).digest()).decode()
        return expected, sig
    if provider == 'Volcengine':
        xdate = hdr(hs,'X-Date'); date=xdate[:8]; signed='content-type;host;x-content-sha256;x-date'; payload=hashlib.sha256(body.encode()).hexdigest(); ch='content-type:application/json\nhost:%s\nx-content-sha256:%s\nx-date:%s\n' % (host,payload,xdate)
        cr='POST\n%s\n%s\n%s\n%s\n%s' % (path,canonical_query(query),ch,signed,payload); scope='%s/cn-north-1/DNS/request'%date; sts='HMAC-SHA256\n%s\n%s\n%s'%(xdate,scope,hashlib.sha256(cr.encode()).hexdigest())
        kd=hmac.new(SK.encode(),date.encode(),hashlib.sha256).digest(); kr=hmac.new(kd,b'cn-north-1',hashlib.sha256).digest(); ks=hmac.new(kr,b'DNS',hashlib.sha256).digest(); key=hmac.new(ks,b'request',hashlib.sha256).digest()
        return hmac.new(key,sts.encode(),hashlib.sha256).hexdigest(), auth.split('Signature=')[-1]
    if provider == 'Jdcloud':
        from jdcloud_sdk.core.signer import Signer
        from jdcloud_sdk.core.credential import Credential
        from jdcloud_sdk.core.logger import Logger
        hh={k.lower():v for k,v in hs.items() if k.lower() in ('content-type','x-jdcloud-date','x-jdcloud-nonce')}
        s=Signer(Logger(0)); c=Credential(AK,SK); s.signV3(method,'domainservice', 'cn-north-1',host,path,query,hh,body,c,'')
        return hdr(hh,'Authorization'), auth
    if provider == 'Huawei':
        from huaweicloudsdkcore.sdk_request import SdkRequest
        from huaweicloudsdkcore.auth.credentials import BasicCredentials
        from huaweicloudsdkcore.signer.signer import Signer
        q=[(k,v) for k,v in parse_qsl(query,keep_blank_values=True)]
        hh={k:v for k,v in hs.items() if k.lower() in ('host','content-type','x-sdk-date')}
        req=SdkRequest(method=method,host=host,resource_path=path,query_params=q,header_params=hh,body=body)
        out=Signer(BasicCredentials(AK,SK)).sign(req)
        return hdr(out.header_params,'Authorization'),auth
    if provider == 'Baidu':
        from baidubce.auth import bce_v1_signer
        from baidubce.auth.bce_credentials import BceCredentials
        t=hdr(hs,'X-Bce-Date'); ts=__import__('datetime').datetime.strptime(t,'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=__import__('datetime').timezone.utc).timestamp()
        headers={k.lower().encode():v for k,v in hs.items() if k.lower() in ('host','content-type','x-bce-date')}
        params={k.encode():v.encode() for k,v in parse_qsl(query,keep_blank_values=True)}
        got=bce_v1_signer.sign(BceCredentials(AK.encode(),SK.encode()),method.encode(),path.encode(),headers,params,int(ts),1800,set([b'host',b'content-type',b'x-bce-date']))
        return got.decode().split('/')[-1], auth.split('/')[-1]
    raise RuntimeError(provider)

providers = ['Dnspod','Aliyun','Volcengine','Jdcloud','Huawei','Baidu']
requests=[]
if not os.path.exists(CAP): raise SystemExit('capture missing: '+CAP)
with open(CAP) as f:
    requests=[json.loads(x) for x in f if x.strip()]
results=[]
for p in providers:
    rows=[r for r in requests if '/' + p.lower() + '/' in r['uri'] or r['uri'].startswith('/'+p.lower()+'?')]
    mism=[]
    for i,r in enumerate(rows):
        try: expected, actual=auth_sig(p,r); ok=hmac.compare_digest(expected,actual)
        except Exception as e: ok=False; expected='ERROR'; actual=str(e)
        if not ok: mism.append({'request':i,'uri':r['uri'],'expected':expected,'actual':actual})
    results.append({'provider':p,'captured':len(rows),'status':'MATCH' if rows and not mism else 'MISMATCH','mismatches':mism})
with open(OUT,'w') as f: json.dump(results,f,indent=2,ensure_ascii=False)
print(json.dumps(results,indent=2,ensure_ascii=False))
if any(x['status']!='MATCH' for x in results): sys.exit(1)
