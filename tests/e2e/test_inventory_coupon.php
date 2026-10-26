<?php
/**
 * Inventory/coupon/cron integration checks. Local synthetic data only.
 * Usage: IDC_SITE=/tmp/idcsite_8193 php7.0 test_inventory_coupon.php
 */
$site = rtrim(getenv('IDC_SITE') ?: '/tmp/idcsite_8193', '/') . '/';
if (!is_file($site . 'data/config.php') && !is_file($site . 'data/datapath.php')) {
    fwrite(STDERR, "site not installed\n"); exit(2);
}
define('WEB_ROOT', $site); define('CORE_PATH', $site . 'core/'); define('IDC_CLI', true);
define('IDC_VERSION', '1.0.0');
$dp = $site . 'data/';
if (is_file($site . 'data/datapath.php')) { $x = include $site . 'data/datapath.php'; if (is_string($x) && is_dir($x)) $dp = rtrim($x, '/') . '/'; }
define('DATA_PATH', $dp);
$GLOBALS['IDC_CONFIG'] = include DATA_PATH . 'config.php';
require CORE_PATH . 'polyfill.php'; require CORE_PATH . 'thinkphp/base.php';
require CORE_PATH . 'application/common.php'; require CORE_PATH . 'application/common/SafeApp.php';
$app = new \app\common\SafeApp(CORE_PATH . 'application/'); $app->initialize();
\think\Db::init(array_merge(include CORE_PATH . 'config/database.php', array('query' => '\\think\\db\\Query')));
\think\Loader::addNamespace('app', CORE_PATH . 'application');
use think\Db; use app\common\service\OrderService; use app\common\service\OrderAdminService;
use app\common\service\InventoryService; use app\common\service\CouponService; use app\common\service\CronService;
$pass = 0; $fail = 0;
function case_ok($name, $condition, $detail = '') { global $pass, $fail; if ($condition) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name" . ($detail ? ': ' . $detail : '') . "\n"; } }
function must_throw($name, $fn) { try { $fn(); case_ok($name, false, 'expected exception'); } catch (Exception $e) { case_ok($name, true); } }
function fresh_user($tag, $balance = 1000) { $n = 'e2e_' . $tag . '_' . substr(sha1(uniqid('', true)), 0, 8); $id = Db::name('user')->insertGetId(array('email'=>$n.'@example.test','username'=>$n,'password'=>password_hash('LocalOnly-123', PASSWORD_DEFAULT),'balance'=>$balance,'email_verified'=>1,'create_time'=>time())); return Db::name('user')->find($id); }
function product($name, $stock, $limit = 0) { $g = Db::name('product_group')->insertGetId(array('name'=>$name,'status'=>1)); return Db::name('product')->insertGetId(array('group_id'=>$g,'name'=>$name,'upstream_id'=>0,'upstream_options'=>'','pricing'=>json_encode(array('monthly'=>10)),'stock'=>$stock,'sold'=>0,'reserved'=>0,'per_user_limit'=>$limit,'stock_alert'=>1,'stock_state'=>'ok','nat_stock_cap'=>-1,'status'=>1,'auto_setup'=>0,'create_time'=>time())); }
function order_for($u, $pid, $title = 'E2E host', $amount = 10, $coupon = '') { return OrderService::create($u, 'host_new', $title, array('product_id'=>$pid,'cycle'=>'monthly','hostname'=>'e2e'), $amount, false, $coupon); }
try {
    setting_save(array('stock_alert_enable'=>'1','stock_alert_default'=>'1','coupon_enable'=>'1','market_coupon_bearer'=>'platform'));
    $u1 = fresh_user('inv_a'); $u2 = fresh_user('inv_b'); $pid = product('E2E stock product', 2);
    $o1 = order_for($u1, $pid, 'reserve one'); $o2 = order_for($u2, $pid, 'reserve two');
    case_ok('two orders reserve stock', (int)Db::name('product')->where('id',$pid)->value('reserved') === 2 && InventoryService::available('product',$pid) === 0);
    must_throw('third order is sold out', function() use ($u1,$pid) { order_for($u1,$pid,'sold out'); });
    OrderAdminService::userCancel($u1, $o1['id']);
    case_ok('unpaid cancel releases reservation', (int)Db::name('product')->where('id',$pid)->value('reserved') === 1);
    $o3 = order_for($u1, $pid, 'replacement');
    case_ok('replacement order reserves', (int)Db::name('product')->where('id',$pid)->value('reserved') === 2);
    OrderService::payWithBalance($o3);
    $pr = Db::name('product')->find($pid);
    case_ok('payment commits sold and clears reserved', (int)$pr['sold'] === 1 && (int)$pr['reserved'] === 1);
    $before = (int)$pr['sold']; OrderService::deliver(Db::name('order')->find($o3['id']));
    case_ok('redelivery does not double increment sold', (int)Db::name('product')->where('id',$pid)->value('sold') === $before);
    OrderAdminService::cancel($o3['id'], array('refund_mode'=>'none','reason'=>'e2e'));
    case_ok('paid cancel releases sold', (int)Db::name('product')->where('id',$pid)->value('sold') === 0);

    $cpid = product('E2E coupon product', -1); $couponCode = 'E2E' . strtoupper(substr(sha1(uniqid('',true)),0,8));
    $cid = Db::name('coupon')->insertGetId(array('code'=>$couponCode,'name'=>'E2E 100 percent','type'=>'percent','value'=>100,'max_discount'=>0,'min_amount'=>5,'scenes'=>'new','item_types'=>'host','product_ids'=>$cpid,'group_ids'=>'','package_ids'=>'','domain_ids'=>'','total_limit'=>1,'per_user_limit'=>1,'used_count'=>0,'reserved_count'=>0,'new_user_only'=>0,'new_user_days'=>7,'first_order_only'=>0,'tier_ids'=>'','start_time'=>0,'end_time'=>0,'status'=>1,'create_time'=>time(),'update_time'=>time()));
    $co = order_for($u1, $cpid, 'coupon zero', 10, $couponCode);
    $couponReserved = (int)Db::name('coupon')->where('id',$cid)->value('reserved_count');
    case_ok('coupon reservation and cap', (int)$co['coupon_id'] === (int)$cid && (float)$co['pay_amount'] === 0.0 && $couponReserved === 1, 'order_coupon=' . (int)$co['coupon_id'] . ' cid=' . (int)$cid . ' pay=' . (float)$co['pay_amount'] . ' reserved=' . $couponReserved);
    Db::name('coupon')->where('id',$cid)->update(array('status'=>0)); must_throw('disabled coupon blocks payment revalidation', function() use ($co) { OrderService::payWithBalance($co); });
    Db::name('coupon')->where('id',$cid)->update(array('status'=>1)); OrderService::payWithBalance(Db::name('order')->find($co['id']));
    case_ok('zero amount balance payment succeeds', Db::name('order')->where('id',$co['id'])->value('status') === 'paid' && (int)Db::name('coupon')->where('id',$cid)->value('used_count') === 1);
    $c2 = 'REL' . strtoupper(substr(sha1(uniqid('',true)),0,8)); $cid2 = Db::name('coupon')->insertGetId(array('code'=>$c2,'name'=>'E2E fixed','type'=>'fixed','value'=>3,'max_discount'=>2,'min_amount'=>10,'scenes'=>'new','item_types'=>'host','product_ids'=>'','group_ids'=>'','package_ids'=>'','domain_ids'=>'','total_limit'=>0,'per_user_limit'=>2,'status'=>1,'create_time'=>time(),'update_time'=>time()));
    $ro = order_for($u2, $cpid, 'remove coupon', 10, $c2); $removed = CouponService::removeFromOrder($ro);
    case_ok('remove coupon restores pay amount', (float)$removed['pay_amount'] === 10.0 && (int)Db::name('coupon')->where('id',$cid2)->value('reserved_count') === 0);

    $statePid = product('E2E alert product', 2); InventoryService::checkItem('product',$statePid); InventoryService::checkItem('product',$statePid);
    $alerts0 = Db::name('audit_log')->where('action','like','inventory.%')->where('target','like','产品#'.$statePid)->count();
    Db::name('product')->where('id',$statePid)->update(array('stock'=>1)); InventoryService::checkItem('product',$statePid); InventoryService::checkItem('product',$statePid);
    $alerts1 = Db::name('audit_log')->where('action','like','inventory.%')->where('target','like','产品#'.$statePid)->count();
    Db::name('product')->where('id',$statePid)->update(array('stock'=>0)); InventoryService::checkItem('product',$statePid); InventoryService::checkItem('product',$statePid);
    $alerts2 = Db::name('audit_log')->where('action','like','inventory.%')->where('target','like','产品#'.$statePid)->count();
    Db::name('product')->where('id',$statePid)->update(array('stock'=>2)); InventoryService::checkItem('product',$statePid); InventoryService::checkItem('product',$statePid);
    $alerts3 = Db::name('audit_log')->where('action','like','inventory.%')->where('target','like','产品#'.$statePid)->count();
    case_ok('stock alerts transition without duplicates', $alerts0 <= $alerts1 && $alerts1 < $alerts2 && $alerts2 < $alerts3);

    setting_save(array('cron_time_budget'=>'1','cron_next_task'=>'notify_queue','cron_visit_trigger'=>'0'));
    $r1 = CronService::run(1); $next = setting('cron_next_task',''); $r2 = CronService::run(1);
    case_ok('cron records cursor and resumes', is_array($r1) && is_array($r2) && setting('cron_last_run','0') !== '0' && ($next === '' || isset($r1[$next]) || isset($r2[$next])));
} catch (Exception $e) { case_ok('test harness', false, $e->getMessage()); }
echo "INVENTORY/COUPON/CRON: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
