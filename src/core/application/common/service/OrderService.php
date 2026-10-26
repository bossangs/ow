<?php

namespace app\common\service;

use think\Db;
use app\common\payment\PayFactory;
use app\common\lib\Notify;

/**
 * 订单 + 支付 + 交付
 *
 * 订单类型 type：
 *  host_new      新购云主机   data: product_id, cycle, hostname, os, password
 *  host_renew    云主机续费   data: service_id, cycle
 *  host_upgrade  云主机升级   data: service_id, new_product_id, cycle
 *  dns_package   DNS 套餐     data: package_id, user_package_id(续费时)
 *  dns_sub_new   付费子域名   data: domain_id, prefix
 *  dns_sub_renew 子域名续期   data: sub_id
 *  tier          会员等级     data: tier_id
 *  recharge      余额充值     data: []
 */
class OrderService
{
    public static function types()
    {
        return [
            'host_new' => '新购云主机', 'host_renew' => '云主机续费', 'host_upgrade' => '云主机升级',
            'dns_package' => 'DNS 套餐', 'dns_sub_new' => '子域名注册', 'dns_sub_renew' => '子域名续期',
            'tier' => '会员升级', 'recharge' => '余额充值',
        ];
    }

    /** 用户折扣（会员等级） 0-100 */
    public static function discountOf(array $user)
    {
        $tier = TierService::current($user);
        $d = $tier ? (int)$tier['discount'] : 100;

        return max(1, min(100, $d ?: 100));
    }

    /** 创建订单 */
    public static function create(array $user, $type, $title, array $data, $amount, $applyDiscount = true, $couponCode = '')
    {
        $amount = round((float)$amount, 2);
        $discount = 0.0;
        if ($applyDiscount && $type !== 'recharge' && $type !== 'tier') {
            $rate = self::discountOf($user);
            if ($rate < 100) {
                $discount = round($amount * (100 - $rate) / 100, 2);
            }
        }
        // 订单、库存预留、优惠码预留在同一事务内完成：任何一步失败（售罄/限购/优惠码无效）整体回滚
        Db::startTrans();
        try {
            $id = Db::name('order')->insertGetId([
                'order_no' => order_no('O'),
                'user_id' => $user['id'],
                'type' => $type,
                'title' => mb_substr($title, 0, 250),
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'amount' => $amount,
                'discount' => $discount,
                'pay_amount' => max(0, round($amount - $discount, 2)),
                'status' => 'unpaid',
                'create_time' => time(),
            ]);
            $order = Db::name('order')->find($id);
            InventoryService::reserve($order);
            if (trim((string)$couponCode) !== '') {
                $order = CouponService::reserve($user, $order, $couponCode);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        audit('order.create', '订单#' . $id, ['type' => $type, 'amount' => $amount, 'title' => $title, 'coupon' => $order['coupon_code'], 'coupon_discount' => $order['coupon_discount']]);

        return $order;
    }

    /**
     * 创建支付流水
     * @param string $purpose order|recharge
     */
    public static function createPayment(array $user, $purpose, $orderId, $amount, $gateway, $channel = '')
    {
        if ($orderId) {
            $o = Db::name('order')->find($orderId);
            if (!$o || $o['status'] !== 'unpaid' || (int)$o['user_id'] !== (int)$user['id']) {
                throw new \Exception('订单状态不允许支付');
            }
            if ($purpose === 'order') {
                CouponService::revalidate($o);
            }
            $amount = (float)$o['pay_amount']; // 金额以服务端订单为准
        }
        $g = Db::name('gateway')->where('code', $gateway)->find();
        if (!$g || !(int)$g['enabled'] || $gateway === 'balance') {
            throw new \Exception('该支付方式未启用');
        }
        if ((float)$g['min_amount'] > 0 && $amount < (float)$g['min_amount']) {
            throw new \Exception('该支付方式最低支付 ' . money($g['min_amount']));
        }
        if ((float)$g['max_amount'] > 0 && $amount > (float)$g['max_amount']) {
            throw new \Exception('该支付方式单笔最高 ' . money($g['max_amount']));
        }
        if ($amount <= 0) {
            throw new \Exception('支付金额无效');
        }
        $fee = 0;
        if ($g && (float)$g['fee_rate'] > 0) {
            $fee = round($amount * (float)$g['fee_rate'] / 100, 2);
        }
        $id = Db::name('payment')->insertGetId([
            'trade_no' => order_no('P'),
            'user_id' => $user['id'],
            'amount' => round($amount + $fee, 2),
            'fee' => $fee,
            'gateway' => $gateway,
            'channel' => $channel,
            'purpose' => $purpose,
            'order_id' => (int)$orderId,
            'status' => 0,
            'create_time' => time(),
        ]);

        return Db::name('payment')->find($id);
    }

    /** 余额支付订单 */
    public static function payWithBalance(array $order)
    {
        $order = Db::name('order')->find($order['id']);
        if (!$order || $order['status'] !== 'unpaid') {
            throw new \Exception('订单状态不允许支付');
        }
        if ($order['type'] === 'recharge') {
            throw new \Exception('充值订单请选择在线支付方式');
        }
        $bg = Db::name('gateway')->where('code', 'balance')->find();
        if ($bg && !(int)$bg['enabled']) {
            throw new \Exception('余额支付未启用');
        }
        CouponService::revalidate($order);
        $amount = (float)$order['pay_amount'];
        Db::startTrans();
        try {
            $ok = Db::name('user')->where('id', $order['user_id'])->where('balance', '>=', $amount)->setDec('balance', $amount);
            if (!$ok && $amount > 0) {
                throw new \Exception('余额不足');
            }
            $aff = Db::name('order')->where('id', $order['id'])->where('status', 'unpaid')->update(['status' => 'paid', 'gateway' => 'balance', 'paid_time' => time()]);
            if (!$aff) {
                throw new \Exception('订单已支付或已取消');
            }
            $bal = Db::name('user')->where('id', $order['user_id'])->value('balance');
            if ($amount > 0) {
                Db::name('balance_log')->insert(['user_id' => $order['user_id'], 'amount' => -$amount, 'balance' => $bal, 'type' => 'pay', 'remark' => '支付订单 ' . $order['order_no'], 'create_time' => time()]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        audit('order.pay', '订单#' . $order['id'], ['gateway' => 'balance', 'amount' => $amount]);
        self::afterPaid(Db::name('order')->find($order['id']));

        return true;
    }

    /**
     * 支付流水确认到账（网关回调 / 人工确认），幂等
     * @return bool 是否本次完成入账
     */
    public static function confirmPayment($tradeNo, $amount, $outTradeNo = '', $raw = '', $by = 'notify')
    {
        $pay = Db::name('payment')->where('trade_no', $tradeNo)->find();
        if (!$pay) {
            throw new \Exception('支付单不存在');
        }
        if ((int)$pay['status'] === 1) {
            return false; // 已处理
        }
        if ($amount !== null && abs((float)$amount - (float)$pay['amount']) > 0.009) {
            audit('payment.amount_mismatch', $tradeNo, ['expect' => $pay['amount'], 'got' => $amount], 'danger', 'system', 0, $by);
            throw new \Exception('支付金额不一致');
        }
        Db::startTrans();
        try {
            $aff = Db::name('payment')->where('id', $pay['id'])->where('status', 0)->update([
                'status' => 1, 'paid_time' => time(), 'out_trade_no' => mb_substr((string)$outTradeNo, 0, 100),
                'notify_raw' => mb_substr(is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE) : (string)$raw, 0, 4000),
            ]);
            if (!$aff) {
                Db::rollback();

                return false;
            }
            $order = null;
            if ($pay['purpose'] === 'recharge' || $pay['order_id'] == 0) {
                self::addBalance($pay['user_id'], (float)$pay['amount'] - (float)$pay['fee'], 'recharge', '在线充值 ' . $pay['trade_no']);
                if ($pay['order_id']) {
                    Db::name('order')->where('id', $pay['order_id'])->where('status', 'unpaid')->update(['status' => 'paid', 'gateway' => $pay['gateway'], 'paid_time' => time(), 'deliver_status' => 'done']);
                }
            } else {
                $aff2 = Db::name('order')->where('id', $pay['order_id'])->where('status', 'unpaid')->update(['status' => 'paid', 'gateway' => $pay['gateway'], 'paid_time' => time()]);
                if ($aff2) {
                    $order = Db::name('order')->find($pay['order_id']);
                } else {
                    // 订单已被其它方式支付或取消：转入余额，避免资金损失
                    self::addBalance($pay['user_id'], (float)$pay['amount'] - (float)$pay['fee'], 'refund', '订单状态异常，支付金额转入余额 ' . $pay['trade_no']);
                }
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        audit('payment.paid', $tradeNo, ['gateway' => $pay['gateway'], 'amount' => $pay['amount'], 'by' => $by], 'info', 'system', 0, $by);
        $user = Db::name('user')->find($pay['user_id']);
        if ($pay['purpose'] === 'recharge' && $user) {
            Notify::user('recharge_paid', $user, ['amount' => money($pay['amount'] - $pay['fee']), 'balance' => money($user['balance'])]);
        }
        if ($order) {
            self::afterPaid($order);
        }

        return true;
    }

    /**
     * 余额变动（原子：行锁 + 前后余额），返回变动后余额
     * @param string $operator system / user / admin:用户名
     * @param bool $allowNegative 是否允许扣成负数
     */
    public static function addBalance($userId, $amount, $type, $remark, $operator = 'system', $allowNegative = true, $orderId = 0)
    {
        $amount = round((float)$amount, 2);
        $own = false;
        try {
            Db::startTrans();
            $own = true;
        } catch (\Exception $e) {
        }
        try {
            $u = Db::name('user')->where('id', $userId)->lock(true)->find();
            if (!$u) {
                throw new \RuntimeException('用户不存在');
            }
            $before = round((float)$u['balance'], 2);
            $after = round($before + $amount, 2);
            if (!$allowNegative && $after < 0) {
                throw new \RuntimeException('余额不足：当前 ' . money($before) . '，扣除后将为负数');
            }
            Db::name('user')->where('id', $userId)->update(['balance' => $after]);
            Db::name('balance_log')->insert([
                'user_id' => $userId,
                'amount' => $amount,
                'balance_before' => $before,
                'balance' => $after,
                'type' => $type,
                'remark' => mb_substr((string)$remark, 0, 250),
                'operator' => mb_substr((string)$operator, 0, 60),
                'ip' => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string)request()->ip(), 0, 60),
                'order_id' => (int)$orderId,
                'create_time' => time(),
            ]);
            if ($own) {
                Db::commit();
            }

            return $after;
        } catch (\Exception $e) {
            if ($own) {
                Db::rollback();
            }
            throw $e;
        }
    }

    /** 付款后：通知 + 交付 */
    public static function afterPaid(array $order)
    {
        try {
            InventoryService::commit($order);
            CouponService::consume($order);
        } catch (\Exception $e) {
            audit('order.after_paid_error', '订单#' . $order['id'], $e->getMessage(), 'warn', 'system', 0, 'order');
        }
        $user = Db::name('user')->find($order['user_id']);
        if ($user) {
            Notify::user('order_paid', $user, ['order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => money($order['pay_amount'])]);
        }
        self::deliver($order);
    }

    /**
     * 订单取消/超时/退款/删除后的统一收尾：退回库存、释放优惠码次数
     */
    public static function onReleased(array $order, $reason = '')
    {
        try {
            InventoryService::release($order, $reason);
            CouponService::release($order, $reason);
        } catch (\Exception $e) {
            audit('order.release_error', '订单#' . $order['id'], $e->getMessage(), 'warn', 'system', 0, 'order');
        }
    }

    /** 交付（可在后台“重新交付”） */
    public static function deliver(array $order)
    {
        $data = json_arr($order['data']);
        $user = Db::name('user')->find($order['user_id']);
        $msg = '';
        $status = 'done';
        try {
            switch ($order['type']) {
                case 'host_new':
                    $sid = ProvisionService::createFromOrder($order, $user, $data);
                    $svc = Db::name('service')->find($sid);
                    $status = $svc['status'] === 'active' ? 'done' : ($svc['status'] === 'failed' ? 'failed' : 'pending');
                    $msg = '服务 #' . $sid . ' ' . ($svc['last_error'] ?: $svc['status']);
                    break;
                case 'host_renew':
                    ProvisionService::renew((int)$data['service_id'], $data['cycle']);
                    $msg = '已续费';
                    break;
                case 'host_upgrade':
                    $msg = ProvisionService::upgrade((int)$data['service_id'], (int)$data['new_product_id'], $data['cycle']);
                    if (strpos($msg, '人工') !== false) {
                        $status = 'pending';
                    }
                    break;
                case 'dns_package':
                    if (!Db::name('user_package')->where('order_id', $order['id'])->count() || !empty($data['user_package_id'])) {
                        DnsService::grantPackage($user, (int)$data['package_id'], isset($data['user_package_id']) ? (int)$data['user_package_id'] : 0, $order['id']);
                    }
                    $msg = '套餐已生效';
                    break;
                case 'dns_sub_new':
                    $sub = Db::name('dns_subdomain')->where('order_id', $order['id'])->find();
                    if (!$sub) {
                        $sub = DnsService::registerSub($user, (int)$data['domain_id'], $data['prefix'], true, $order['id']);
                    }
                    $msg = '子域名 ' . $sub['fqdn'] . ' ' . $sub['status'];
                    MarketService::settle($order, (int)$data['domain_id'], $sub['id']);
                    break;
                case 'dns_sub_renew':
                    DnsService::renewSub((int)$data['sub_id']);
                    $msg = '已续期';
                    $s0 = Db::name('dns_subdomain')->find((int)$data['sub_id']);
                    if ($s0) {
                        MarketService::settle($order, (int)$s0['domain_id'], $s0['id']);
                    }
                    break;
                case 'tier':
                    TierService::grant($user, (int)$data['tier_id']);
                    $msg = '会员已升级';
                    break;
                case 'recharge':
                    $msg = '已充值';
                    break;
                default:
                    $status = 'failed';
                    $msg = '未知订单类型';
            }
        } catch (\Exception $e) {
            $status = 'failed';
            $msg = $e->getMessage();
            Notify::admin('service_failed', ['service_id' => '订单' . $order['order_no'], 'product' => $order['title'], 'error' => $msg]);
        }
        Db::name('order')->where('id', $order['id'])->update(['deliver_status' => $status, 'deliver_msg' => mb_substr($msg, 0, 490)]);
        audit('order.deliver', '订单#' . $order['id'], ['status' => $status, 'msg' => $msg], $status === 'failed' ? 'warn' : 'info', 'system', 0, 'deliver');

        return $status;
    }

    /** 发起在线支付，返回网关动作 */
    public static function startGateway(array $pay, $subject, $isMobile)
    {
        $g = PayFactory::make($pay['gateway']);
        $ctx = [
            'subject' => $subject,
            'notify_url' => url_to('index/pay/notify/gw/' . $pay['gateway'], [], true),
            'return_url' => url_to('index/pay/returned/gw/' . $pay['gateway'], ['trade_no' => $pay['trade_no']], true),
            'client_ip' => client_ip(),
            'is_mobile' => $isMobile,
            'channel' => $pay['channel'],
        ];
        $r = $g->create($pay, $ctx);
        if (!empty($r['extra'])) {
            Db::name('payment')->where('id', $pay['id'])->update(['remark' => mb_substr(json_encode($r['extra'], JSON_UNESCAPED_UNICODE), 0, 250)]);
        }

        return $r;
    }
}
