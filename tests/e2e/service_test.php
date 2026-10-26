<?php
/** IDCDNS service-layer E2E checks; offline/local mocks only. PHP 7.0 compatible. */
$site = getenv('IDC_SITE') ?: '/tmp/idcsite_8240/';
$site = rtrim($site, '/') . '/';
$dp = $site . 'data/';
if (is_file($dp . 'datapath.php')) { $x = include $dp . 'datapath.php'; if (is_string($x) && $x !== '') { $dp = rtrim($x, '/\\') . '/'; } }
if (!is_file($dp . 'config.php')) { fwrite(STDERR, "site not installed\n"); exit(2); }
define('WEB_ROOT', $site); define('CORE_PATH', $site . 'core/'); define('IDC_CLI', true); define('IDC_VERSION', '1.0.0'); define('DATA_PATH', $dp);
$GLOBALS['IDC_CONFIG'] = include DATA_PATH . 'config.php';
require CORE_PATH . 'polyfill.php'; require CORE_PATH . 'thinkphp/base.php';
// 与 core/boot.php 一致：PHP 8.1+ 忽略 ThinkPHP 5.1 内核的弃用提示，其余错误仍交给框架
if (PHP_VERSION_ID >= 80000) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    set_error_handler(function ($errno, $errstr, $errfile = '', $errline = 0) {
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) { return true; }
        \think\Error::appError($errno, $errstr, $errfile, $errline);
        return true;
    });
}
\think\Loader::addNamespace('PHPMailer\\PHPMailer', CORE_PATH . 'vendor/phpmailer/phpmailer/src/');
require CORE_PATH . 'application/common.php'; require CORE_PATH . 'application/common/SafeApp.php';
$app = new \app\common\SafeApp(CORE_PATH . 'application/'); $app->initialize();
\think\Db::init(array_merge(include CORE_PATH . 'config/database.php', array('query' => '\\think\\db\\Query')));
\think\Loader::addNamespace('app', CORE_PATH . 'application');
use think\Db;
use app\common\service\OrderService;
use app\common\service\DnsService;
use app\common\service\LifecycleService;
use app\common\service\MarketService;
use app\common\service\OrderAdminService;
use app\common\nat\NatScript;

$pass = 0; $fail = 0; $groups = array();
function ok($name, $cond, $detail = '') { global $pass, $fail, $groups; if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail ? ': ' . $detail : '') . "\n"; } $groups[] = array($name, (bool)$cond, $detail); }
function throws($name, $fn) { try { $fn(); ok($name, false, 'expected exception'); } catch (Exception $e) { ok($name, true); } }
function row($table, $id) { return Db::name($table)->find($id); }

try {
  $u = Db::name('user')->where('email','e2e-user@example.com')->find();
  if (!$u) { $uid = Db::name('user')->insertGetId(array('email'=>'e2e-user@example.com','username'=>'e2euser','password'=>password_hash('Passw0rd!', PASSWORD_DEFAULT),'balance'=>100,'email_verified'=>1,'create_time'=>time())); $u = row('user',$uid); } else $uid = $u['id'];
  Db::name('gateway')->where('code','manual')->update(array('enabled'=>1));
  $o = OrderService::create($u, 'recharge', 'E2E recharge', array(), 10, false);
  $p = OrderService::createPayment($u, 'recharge', 0, 10, 'manual');
  ok('payment create', (bool)$p && $p['status'] == 0);
  try { OrderService::confirmPayment($p['trade_no'], 9, 'bad', array(), 'e2e'); } catch (Exception $e) { ok('payment amount mismatch rejected', true); }
  ok('payment remains unpaid after mismatch', row('payment',$p['id'])['status'] == 0);
  ok('payment confirmation credits balance', OrderService::confirmPayment($p['trade_no'], 10, 'OUT-1', array('mock'=>1), 'e2e') === true);
  $bal = (float)Db::name('user')->where('id',$uid)->value('balance');
  ok('payment balance credited', $bal >= 110);
  ok('payment callback idempotent', OrderService::confirmPayment($p['trade_no'], 10, 'OUT-1', array(), 'e2e') === false);
  ok('payment one balance log', Db::name('balance_log')->where('user_id',$uid)->where('remark','like','%'.$p['trade_no'].'%')->count() == 1);

  $gid = Db::name('product_group')->insertGetId(array('name'=>'E2E')); $pid = Db::name('product')->insertGetId(array('group_id'=>$gid,'name'=>'E2E host','upstream_id'=>0,'pricing'=>json_encode(array('monthly'=>10)),'status'=>1,'create_time'=>time()));
  $dom = Db::name('dns_domain')->where('domain','e2e.test')->find();
  if (!$dom) { $pr = Db::name('dns_provider')->insertGetId(array('name'=>'E2E provider','driver'=>'cloudflare','config'=>encrypt_json(array('token'=>'TOKENEXAMPLE','endpoint'=>getenv('CF_MOCK_BASE') ?: 'http://127.0.0.1:18991')),'status'=>1,'create_time'=>time())); $did = Db::name('dns_domain')->insertGetId(array('provider_id'=>$pr,'domain'=>'e2e.test','listing_status'=>'active','status'=>1,'period_days'=>30,'types'=>'A,AAAA,CNAME,TXT','min_len'=>3,'max_len'=>32,'default_ttl'=>600,'create_time'=>time())); $dom=row('dns_domain',$did); } else $did=$dom['id'];
  setting_save(array('dns_live_conflict_check'=>'0'));
  $sub = DnsService::registerSub($u, $did, 'nodee2e', false, 0); ok('dns subdomain register', $sub['status'] === 'active');
  throws('dns reserved prefix rejected', function() use ($u,$did) { DnsService::registerSub($u,$did,'www',false,0); });
  throws('dns quota rejected', function() use ($u,$did) { DnsService::registerSub($u,$did,'nodee2e2',false,0); });
  $rid = DnsService::addRecord($u, $sub['id'], '@', 'A', '1.2.3.4', 'default', 120, 10, false, false); ok('dns record validation/create', (bool)$rid);
  throws('dns private IP rejected', function() use ($u,$sub) { DnsService::addRecord($u,$sub['id'],'x','A','127.0.0.1','default',120,10,false,false); });
  $cf = getenv('CF_MOCK_BASE'); if ($cf) { $d = row('dns_domain',$did); ok('cloudflare provider configured', strpos(decrypt_str(row('dns_provider',$d['provider_id'])['config']),'TOKENEXAMPLE') !== false); }

  $old = time() - 2 * 86400; Db::name('service')->insert(array('user_id'=>$uid,'product_id'=>$pid,'status'=>'active','due_time'=>$old,'suspend_reason'=>'','create_time'=>$old));
  $stats = LifecycleService::services(); ok('lifecycle overdue service handled', is_array($stats));
  setting_save(array('lc_remind_enable'=>'0','lc_suspend_enable'=>'0','lc_terminate_enable'=>'0','lc_unpaid_cancel_enable'=>'0')); ok('lifecycle switches disable', LifecycleService::defaults()['suspend_enable'] === false);

  $free = OrderService::create($u,'host_new','free host',array('product_id'=>$pid,'cycle'=>'monthly'),0,false); $admin = OrderAdminService::provision($free['id'],false,'e2e'); ok('order admin free provision', $admin['status'] === 'paid');
  throws('order admin invalid status rejected', function() use ($free) { OrderAdminService::update($free['id'],array('status'=>'bogus'),'e2e'); });
  ok('order related returns payments/services', is_array(OrderAdminService::related($admin)));

  ok('nat script renders', strpos(NatScript::render(array('panel_url'=>'http://127.0.0.1:19001','node_id'=>'n1','reg_token'=>'t1')), 'n1') !== false);
  throws('nat script injection rejected', function() { NatScript::render(array('panel_url'=>'http://127.0.0.1:19001','node_id'=>"n1\nrm -rf /")); });
  ok('market commission bounded', MarketService::commissionRate(array('commission_rate'=>-1,'user_id'=>$uid)) >= 0 && MarketService::commissionRate(array('commission_rate'=>-1,'user_id'=>$uid)) <= 100);
} catch (Exception $e) { ok('service test harness', false, $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine()); }

echo "SERVICE E2E: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
