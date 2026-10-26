<?php

namespace app\common\service;

use think\Db;
use app\common\upstream\UpstreamFactory;
use app\common\lib\Notify;

/**
 * 云主机开通 / 续费 / 升级 / 管理（通过上游 WHMCS / 智简魔方）
 */
class ProvisionService
{
    /** @return \app\common\upstream\UpstreamDriver */
    public static function driver($upstreamId)
    {
        $u = Db::name('upstream')->find($upstreamId);
        if (!$u) {
            throw new \Exception('上游接口不存在');
        }
        if (!$u['status']) {
            throw new \Exception('上游接口已停用');
        }

        return UpstreamFactory::make($u['driver'], decrypt_json($u['config']));
    }

    /** 商品价格表 [cycle => price]（已按定价模式计算） */
    public static function pricing(array $product)
    {
        $p = json_arr($product['pricing']);
        $out = [];
        foreach (billing_cycles() as $c => $info) {
            if (isset($p[$c]) && $p[$c] !== '' && $p[$c] !== null && (float)$p[$c] >= 0 && $p[$c] !== '-1') {
                $out[$c] = round((float)$p[$c], 2);
            }
        }

        return $out;
    }

    public static function priceOf(array $product, $cycle)
    {
        $p = self::pricing($product);
        if (!isset($p[$cycle])) {
            throw new \Exception('该商品不支持所选付款周期');
        }

        return $p[$cycle];
    }

    /** 根据新购订单创建服务并开通 */
    public static function createFromOrder(array $order, array $user, array $data)
    {
        $exists = Db::name('service')->where('order_id', $order['id'])->find();
        if ($exists) {
            if (in_array($exists['status'], ['pending', 'failed'], true)) {
                self::provision($exists['id']);
            }

            return $exists['id'];
        }
        $product = Db::name('product')->find($data['product_id']);
        if (!$product) {
            throw new \Exception('商品不存在');
        }
        $now = time();
        $sid = Db::name('service')->insertGetId([
            'user_id' => $user['id'],
            'product_id' => $product['id'],
            'order_id' => $order['id'],
            'upstream_id' => $product['upstream_id'],
            'hostname' => isset($data['hostname']) ? $data['hostname'] : '',
            'billing_cycle' => $data['cycle'],
            'price' => $order['pay_amount'],
            'status' => 'pending',
            'os' => isset($data['os']) ? $data['os'] : '',
            'password' => !empty($data['password']) ? encrypt_str($data['password']) : '',
            'options' => json_encode(isset($data['options']) ? $data['options'] : [], JSON_UNESCAPED_UNICODE),
            'reg_time' => $now,
            'due_time' => add_cycle($now, $data['cycle']),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        // 有库存预留记录的订单在付款时已计入已售（InventoryService::commit），这里只处理无预留的手工开通
        if (!Db::name('inventory_hold')->where('order_id', (int)$order['id'])->count()) {
            Db::name('product')->where('id', $product['id'])->setInc('sold');
        }
        if ($product['auto_setup']) {
            self::provision($sid);
        } else {
            Db::name('service')->where('id', $sid)->update(['last_error' => '等待管理员手动开通']);
        }

        return $sid;
    }

    /** 向上游下单开通（可重试） */
    public static function provision($sid)
    {
        $s = Db::name('service')->find($sid);
        $product = Db::name('product')->find($s['product_id']);
        $user = Db::name('user')->find($s['user_id']);
        if (!$product['upstream_id']) {
            Db::name('service')->where('id', $sid)->update(['last_error' => '商品未绑定上游，需人工开通']);

            return false;
        }
        try {
            $drv = self::driver($product['upstream_id']);
            $opts = json_arr($product['upstream_options']);
            $userOpts = json_arr($s['options']);
            if ($s['os'] !== '') {
                $userOpts['os'] = $s['os'];
            }
            $res = $drv->createService([
                'pid' => $product['upstream_pid'],
                'billingcycle' => $s['billing_cycle'],
                'hostname' => $s['hostname'],
                'password' => decrypt_str($s['password']),
                'os' => $s['os'],
                'options' => array_merge($opts, $userOpts),
                'client' => [
                    'email' => $user['email'],
                    'firstname' => $user['username'],
                    'lastname' => 'User',
                    'user_id' => $user['id'],
                ],
            ]);
            $remote = isset($res['service_id']) ? (string)$res['service_id'] : '';
            Db::name('service')->where('id', $sid)->update([
                'remote_id' => $remote,
                'remote_order_id' => isset($res['order_id']) ? (string)$res['order_id'] : '',
                'status' => $remote !== '' ? 'active' : 'pending',
                'last_error' => $remote !== '' ? '' : '上游已受理，等待返回服务 ID',
                'update_time' => time(),
            ]);
            audit('service.provision', '服务#' . $sid, ['remote_id' => $remote], 'info', 'system', 0, 'provision');
            if ($remote !== '') {
                self::sync($sid, true);
                $s = Db::name('service')->find($sid);
                Notify::user('service_active', $user, [
                    'hostname' => $s['hostname'] ?: ('#' . $sid), 'product' => $product['name'], 'ip' => $s['ip'] ?: '分配中',
                    'host_user' => $s['username'] ?: 'root', 'due_date' => date('Y-m-d', $s['due_time']),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Db::name('service')->where('id', $sid)->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 490), 'update_time' => time()]);
            audit('service.provision_fail', '服务#' . $sid, ['error' => $e->getMessage()], 'warn', 'system', 0, 'provision');
            Notify::admin('service_failed', ['service_id' => $sid, 'product' => $product['name'], 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** 从上游同步 IP/状态/账号 */
    public static function sync($sid, $silent = false)
    {
        $s = Db::name('service')->find($sid);
        if (!$s || $s['remote_id'] === '') {
            return false;
        }
        try {
            $info = self::driver($s['upstream_id'])->getService($s['remote_id']);
            $ip = isset($info['ip']) ? $info['ip'] : '';
            if ($ip === '' && !empty($info['ips'])) {
                $ip = implode(',', array_filter((array)$info['ips']));
            }
            $up = ['last_sync_time' => time(), 'update_time' => time()];
            if ($ip !== '') {
                $up['ip'] = mb_substr($ip, 0, 250);
            }
            if (!empty($info['username'])) {
                $up['username'] = mb_substr($info['username'], 0, 60);
            }
            if (!empty($info['password'])) {
                $up['password'] = encrypt_str($info['password']);
            }
            if (!empty($info['hostname']) && $s['hostname'] === '') {
                $up['hostname'] = mb_substr($info['hostname'], 0, 100);
            }
            if (!empty($info['status']) && in_array($info['status'], ['active', 'suspended', 'terminated'], true) && $s['status'] !== 'terminated') {
                $up['status'] = $info['status'];
            }
            $extra = $info;
            unset($extra['raw'], $extra['password']);
            $up['info'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
            Db::name('service')->where('id', $sid)->update($up);

            return true;
        } catch (\Exception $e) {
            if (!$silent) {
                throw $e;
            }

            return false;
        }
    }

    public static function renew($sid, $cycle)
    {
        $s = Db::name('service')->find($sid);
        if (!$s) {
            throw new \Exception('服务不存在');
        }
        $base = max(time(), (int)$s['due_time']);
        $due = add_cycle($base, $cycle);
        $remoteMsg = '';
        if ($s['remote_id'] !== '' && $s['upstream_id']) {
            try {
                self::driver($s['upstream_id'])->renewService($s['remote_id'], $cycle);
            } catch (\Exception $e) {
                $remoteMsg = $e->getMessage();
            }
        }
        $up = ['due_time' => $due, 'billing_cycle' => $cycle, 'remind_time' => 0, 'update_time' => time()];
        if ($s['status'] === 'suspended' && $s['suspend_reason'] === 'overdue' && setting('lc_auto_unsuspend', '1') === '1') {
            try {
                if ($s['remote_id'] !== '') {
                    self::driver($s['upstream_id'])->unsuspend($s['remote_id']);
                }
                $up['status'] = 'active';
                $up['suspend_reason'] = '';
            } catch (\Exception $e) {
                $remoteMsg .= ' 解除暂停失败：' . $e->getMessage();
            }
        }
        if ($remoteMsg !== '') {
            $up['last_error'] = mb_substr('上游续费失败：' . $remoteMsg, 0, 490);
            Notify::admin('service_failed', ['service_id' => $sid, 'product' => '续费', 'error' => $remoteMsg]);
        }
        Db::name('service')->where('id', $sid)->update($up);
        audit('service.renew', '服务#' . $sid, ['cycle' => $cycle, 'due' => date('Y-m-d', $due), 'upstream_error' => $remoteMsg], 'info', 'system', 0, 'deliver');

        return true;
    }

    /** 升级：同一上游时调用上游升级接口（若驱动支持），否则转人工 */
    public static function upgrade($sid, $newPid, $cycle)
    {
        $s = Db::name('service')->find($sid);
        $np = Db::name('product')->find($newPid);
        if (!$s || !$np) {
            throw new \Exception('服务或商品不存在');
        }
        $msg = '已升级';
        $manual = false;
        if ($s['remote_id'] !== '' && $np['upstream_id'] == $s['upstream_id']) {
            $drv = self::driver($s['upstream_id']);
            if (method_exists($drv, 'upgrade')) {
                try {
                    $drv->upgrade($s['remote_id'], $np['upstream_pid'], $cycle);
                } catch (\Exception $e) {
                    $manual = true;
                    $msg = '上游升级失败，已转人工处理：' . $e->getMessage();
                }
            } else {
                $manual = true;
                $msg = '该上游不支持自动升级，已转人工处理';
            }
        } elseif ($s['remote_id'] !== '') {
            $manual = true;
            $msg = '跨上游升级，需人工处理';
        }
        Db::name('service')->where('id', $sid)->update(['product_id' => $newPid, 'billing_cycle' => $cycle, 'price' => self::priceOf($np, $cycle), 'last_error' => $manual ? mb_substr($msg, 0, 490) : '', 'update_time' => time()]);
        if ($manual) {
            Notify::admin('service_failed', ['service_id' => $sid, 'product' => $np['name'] . '（升级）', 'error' => $msg]);
        }

        return $msg;
    }

    /** 升级差价：按剩余天数折算 */
    public static function upgradePrice(array $service, array $newProduct)
    {
        $cycle = $service['billing_cycle'];
        $newPrice = self::priceOf($newProduct, $cycle);
        $oldPrice = (float)$service['price'];
        $total = max(1, add_cycle($service['reg_time'] ?: time(), $cycle) - ($service['reg_time'] ?: time()));
        $remain = max(0, (int)$service['due_time'] - time());
        $diff = round(($newPrice - $oldPrice) * min(1, $remain / $total), 2);

        return max(0, $diff);
    }

    /** 管理操作：on/off/reboot/reinstall/reset_password/suspend/unsuspend/terminate */
    public static function action($sid, $action, array $params = [], $byAdmin = false)
    {
        $s = Db::name('service')->find($sid);
        if (!$s || $s['remote_id'] === '') {
            throw new \Exception('服务尚未开通');
        }
        if (!$byAdmin && $s['status'] !== 'active') {
            throw new \Exception('服务当前状态不可操作');
        }
        $drv = self::driver($s['upstream_id']);
        switch ($action) {
            case 'suspend':
                $drv->suspend($s['remote_id'], isset($params['reason']) ? $params['reason'] : '');
                Db::name('service')->where('id', $sid)->update(['status' => 'suspended', 'suspend_reason' => isset($params['reason']) ? $params['reason'] : 'admin']);
                break;
            case 'unsuspend':
                $drv->unsuspend($s['remote_id']);
                Db::name('service')->where('id', $sid)->update(['status' => 'active', 'suspend_reason' => '']);
                break;
            case 'terminate':
                $drv->terminate($s['remote_id']);
                Db::name('service')->where('id', $sid)->update(['status' => 'terminated']);
                break;
            default:
                $allowed = ['on', 'off', 'reboot', 'reinstall', 'reset_password'];
                if (!in_array($action, $allowed, true)) {
                    throw new \Exception('不支持的操作');
                }
                $drv->action($s['remote_id'], $action, $params);
                if ($action === 'reset_password' && !empty($params['password'])) {
                    Db::name('service')->where('id', $sid)->update(['password' => encrypt_str($params['password'])]);
                }
                if ($action === 'reinstall' && !empty($params['os'])) {
                    Db::name('service')->where('id', $sid)->update(['os' => mb_substr($params['os'], 0, 100)]);
                }
        }
        audit('service.' . $action, '服务#' . $sid, ['by_admin' => $byAdmin], in_array($action, ['terminate', 'suspend'], true) ? 'warn' : 'info');

        return true;
    }
}
