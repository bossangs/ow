<?php
$root = '/workspace/idcdns/src/core/application';
spl_autoload_register(function ($c) use ($root) { if (strpos($c, 'app\\') === 0) { $p = $root . '/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($p)) require_once $p; } });
use app\common\dns\Cloudflare;
use app\common\dns\DnsException;
$n = 0; $passed = 0;
function expectTrue($condition, $label) { global $n, $passed; $n++; if (!$condition) { echo "FAIL: $label\n"; return false; } $passed++; echo "PASS: $label\n"; return true; }
function expectException($fn, $code, $contains, $label) { try { $fn(); echo "FAIL: $label (no exception)\n"; return false; } catch (DnsException $e) { $ok = ((int)$e->getCode() === (int)$code && strpos($e->getMessage(), $contains) !== false); expectTrue($ok, $label . ' code/message'); return $ok; } }
$base = getenv('CF_MOCK_BASE');
if (!$base) { fwrite(STDERR, "CF_MOCK_BASE is required\n"); exit(2); }
$tokenCfg = array('token'=>'TOKENEXAMPLE','endpoint'=>$base);
$d = new Cloudflare($tokenCfg);
expectTrue($d->check() === true, 'API Token check uses token verify');
$inactive = new Cloudflare(array('token'=>'INACTIVE','endpoint'=>$base));
expectException(function () use ($inactive) { $inactive->check(); }, 0, 'inactive', 'inactive token fails');
$g = new Cloudflare(array('email'=>'user@example.com','key'=>'GLOBALKEY','endpoint'=>$base));
expectTrue($g->check() === true, 'Global Key check uses /user');
$z = new Cloudflare(array('token'=>'TOKENEXAMPLE','account_id'=>'acc1','endpoint'=>$base));
$domains = $z->getDomains('example');
expectTrue(count($domains) === 2 && $domains[0]['domain'] === 'example.com' && $domains[1]['domain'] === 'second.com', 'getDomains paginates and normalizes names');
$records = $z->getRecords('example.com', 'z1');
expectTrue(count($records) === 3, 'getRecords paginates');
expectTrue($records[0]['name'] === '@' && $records[0]['proxied'] === true, 'record relative name and proxied');
expectTrue($records[1]['type'] === 'MX' && $records[1]['mx'] === 20 && $records[1]['value'] === 'mx.example.net', 'MX priority and value');
expectTrue($records[2]['type'] === 'SRV' && $records[2]['value'] === '5 10 5060 sip.example.com.', 'SRV data flattened');
$d->proxied = true; expectTrue($d->addRecord('example.com','z1','a','A','1.2.3.4','default',600) === 'new-record', 'A proxied uses ttl 1 and proxied true');
$d->proxied = false; expectTrue($d->addRecord('example.com','z1','plain','A','1.2.3.4','default',10) === 'new-record', 'non-proxied A clamps ttl to 60 and omits proxied');
expectTrue($d->addRecord('example.com','z1','txt','TXT','hello','default',600) === 'new-record', 'TXT omits proxied');
expectTrue($d->addRecord('example.com','z1','mail','MX','mx.example.net.','default',600,15) === 'new-record', 'MX sends priority');
expectTrue($d->addRecord('example.com','z1','_sip._tcp','SRV','5 10 5060 sip.example.com.','default',600) === 'new-record', 'SRV sends data object');
expectTrue($d->addRecord('example.com','z1','@','CAA','0 issue "letsencrypt.org"','default',600) === 'new-record', 'CAA parses flags/tag/value');
expectTrue($d->updateRecord('example.com','z1','r-a','a','A','5.6.7.8') === true, 'updateRecord uses PUT');
expectTrue($d->deleteRecord('example.com','z1','gone81044') === true, 'delete 81044 is idempotent');
expectTrue($d->deleteRecord('example.com','z1','gone404') === true, 'delete HTTP 404 is idempotent');
expectException(function () use ($d) { $d->deleteRecord('example.com','z1','baddelete'); }, 81057, '相同的记录已存在', 'delete other errors propagate');
foreach (array('10000'=>'鉴权失败','9109'=>'API Token 无效','81057'=>'相同的记录','429'=>'限流') as $code => $text) {
    $e = new Cloudflare(array('token'=>'TOKENEXAMPLE','endpoint'=>$base.'/error'));
    expectException(function () use ($e, $code) { $e->getDomains($code); }, (int)$code, $text, 'error mapping '.$code);
}
echo "Cloudflare tests: $passed/$n passed\n"; exit($passed === $n ? 0 : 1);
