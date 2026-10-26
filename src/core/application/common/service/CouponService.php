<?php

namespace app\common\service;

use think\Db;

/**
 * 优惠码
 *
 * 规则：类型（percent 按比例减 / fixed 立减）、适用场景（新购/续费/升级）、
 * 适用对象（云主机 host、NAT 小鸡 nat、子域名 dns_sub、DNS 套餐 dns_package、市场域名 market）及指定产品/分组/套餐/域名、
 * 最低金额、封顶、总次数、每人次数、仅新用户、仅首单、会员等级、起止时间、启用开关。
 *
 * 生命周期：下单 reserve（行锁计数）→ 支付前 revalidate → 付款 consume → 取消/超时/退款 release。
 * 优惠在会员折扣之后计算。
 */
class CouponService
{
    public static function itemTypes()
    {
        return ['host' => '云主机', 'nat' => 'NAT 小鸡', 'dns_sub' => '子域名', 'dns_package' => 'DNS 套餐', 'market' => '市场域名'];
    }

    public static function scenes()
    {
        return ['new' => '新购', 'renew' => '续费', 'upgrade' => '升级'];
    }

    protected static function ids($s)
    {
        return array_values(array_filter(array_map('intval', preg_split('/[\s,，]+/', (string)$s))));
    }

    /**
     * 解析订单上下文
     * @return array|null scene, item_type, product_id, group_id, package_id, domain_id
     */
    public static function context($type, array $data)
    {
        $c = ['scene' => '', 'item_type' => '', 'product_id' => 0, 'group_id' => 0, 'package_id' => 0, 'domain_id' => 0];
        if (in_array($type, ['host_new', 'host_renew', 'host_upgrade'], true)) {
            $c['scene'] = $type === 'host_new' ? 'new' : ($type === 'host_renew' ? 'renew' : 'upgrade');
            $pid = 0;
            if ($type === 'host_upgrade') {
                $pid = (int)(isset($data['new_product_id']) ? $data['new_product_id'] : (isset($data['product_id']) ? $data['product_id'] : 0));
            } elseif ($type === 'host_new') {
                $pid = (int)(isset($data['product_id']) ? $data['product_id'] : 0);
            }
            if (!$pid && !empty($data['service_id'])) {
                $pid = (int)Db::name('service')->where('id', (int)$data['service_id'])->value('product_id');
            }
            $p = $pid ? Db::name('product')->find($pid) : null;
            if (!$p) {
                return null;
            }
            $up = $p['upstream_id'] ? Db::name('upstream')->where('id', $p['upstream_id'])->value('driver') : '';
            $c['item_type'] = $up === 'natnode' ? 'nat' : 'host';
            $c['product_id'] = (int)$p['id'];
            $c['group_id'] = (int)$p['group_id'];

            return $c;
        }
        if ($type === 'dns_sub_new' || $type === 'dns_sub_renew') {
            $c['scene'] = $type === 'dns_sub_new' ? 'new' : 'renew';
            $did = (int)(isset($data['domain_id']) ? $data['domain_id'] : 0);
            if (!$did && !empty($data['sub_id'])) {
                $did = (int)Db::name('dns_subdomain')->where('id', (int)$data['sub_id'])->value('domain_id');
            }
            $d = $did ? Db::name('dns_domain')->find($did) : null;
            if (!$d) {
                return null;
            }
            $c['item_type'] = (int)$d['user_id'] > 0 ? 'market' : 'dns_sub';
            $c['domain_id'] = (int)$d['id'];

            return $c;
        }
        if ($type === 'dns_package') {
            $c['scene'] = empty($data['user_package_id']) ? 'new' : 'renew';
            $c['item_type'] = 'dns_package';
            $c['package_id'] = (int)(isset($data['package_id']) ? $data['package_id'] : 0);

            return $c;
        }

        return null; // 充值、会员升级不支持优惠码
    }

    /**
     * 校验并计算优惠
     * @param float $base 会员折扣后的金额
     * @param int $excludeOrderId 计算次数时排除的订单（复核本单时用）
     * @return array [coupon 行, 优惠金额]
     */
    public static function evaluate(array $user, $code, $type, array $data, $base, $excludeOrderId = 0, $coupon = null)
    {
        if (setting('coupon_enable', '1') !== '1') {
            throw new \RuntimeException('优惠码功能未开启');
        }
        $code = strtoupper(trim((string)$code));
        $c = $coupon ?: Db::name('coupon')->where('code', $code)->find();
        if (!$c || (int)$c['status'] !== 1) {
            throw new \RuntimeException('优惠码不存在或已停用');
        }
        $now = time();
        if ($c['start_time'] && $now < $c['start_time']) {
            throw new \RuntimeException('优惠码尚未开始，开始时间 ' . date('Y-m-d H:i', $c['start_time']));
        }
        if ($c['end_time'] && $now > $c['end_time']) {
            throw new \RuntimeException('优惠码已过期');
        }
        $ctx = self::context($type, $data);
        if (!$ctx) {
            throw new \RuntimeException('该订单不支持使用优惠码');
        }
        $scenes = array_filter(explode(',', $c['scenes']));
        if ($scenes && !in_array($ctx['scene'], $scenes, true)) {
            $names = self::scenes();
            throw new \RuntimeException('该优惠码仅适用于：' . implode('、', array_map(function ($s) use ($names) {
                return isset($names[$s]) ? $names[$s] : $s;
            }, $scenes)));
        }
        $types = array_filter(explode(',', $c['item_types']));
        if ($types && !in_array($ctx['item_type'], $types, true)) {
            throw new \RuntimeException('该优惠码不适用于此商品');
        }
        $checks = [['product_ids', 'product_id'], ['group_ids', 'group_id'], ['package_ids', 'package_id'], ['domain_ids', 'domain_id']];
        foreach ($checks as $ck) {
            $allow = self::ids($c[$ck[0]]);
            if ($allow && $ctx[$ck[1]] && !in_array($ctx[$ck[1]], $allow, true)) {
                throw new \RuntimeException('该优惠码不适用于此商品');
            }
            if ($allow && !$ctx[$ck[1]] && in_array($ck[1], ['product_id', 'package_id', 'domain_id'], true)
                && (($ck[1] === 'product_id' && in_array($ctx['item_type'], ['host', 'nat'], true))
                    || ($ck[1] === 'package_id' && $ctx['item_type'] === 'dns_package')
                    || ($ck[1] === 'domain_id' && in_array($ctx['item_type'], ['dns_sub', 'market'], true)))) {
                throw new \RuntimeException('该优惠码不适用于此商品');
            }
        }
        $tiers = self::ids($c['tier_ids']);
        if ($tiers && !in_array((int)$user['tier_id'], $tiers, true)) {
            throw new \RuntimeException('该优惠码仅限指定会员等级使用');
        }
        if ((int)$c['new_user_only'] && (int)$user['create_time'] < $now - max(1, (int)$c['new_user_days']) * 86400) {
            throw new \RuntimeException('该优惠码仅限注册 ' . (int)$c['new_user_days'] . ' 天内的新用户使用');
        }
        if ((int)$c['first_order_only']) {
            $paid = Db::name('order')->where('user_id', $user['id'])->where('paid_time', '>', 0)
                ->where('type', 'not in', ['recharge'])->where('id', '<>', (int)$excludeOrderId)->count();
            if ($paid > 0) {
                throw new \RuntimeException('该优惠码仅限首单使用');
            }
        }
        $base = round((float)$base, 2);
        if ((float)$c['min_amount'] > 0 && $base < (float)$c['min_amount']) {
            throw new \RuntimeException('订单金额满 ' . money($c['min_amount']) . ' 才可使用');
        }
        // 次数
        $q = Db::name('coupon_use')->where('coupon_id', $c['id'])->where('status', 'in', ['reserved', 'used']);
        if ($excludeOrderId) {
            $q->where('order_id', '<>', (int)$excludeOrderId);
        }
        if ((int)$c['total_limit'] > 0 && (clone $q)->count() >= (int)$c['total_limit']) {
            throw new \RuntimeException('优惠码已被领完');
        }
        if ((int)$c['per_user_limit'] > 0 && (clone $q)->where('user_id', $user['id'])->count() >= (int)$c['per_user_limit']) {
            throw new \RuntimeException('您已使用过该优惠码');
        }
        if ($c['type'] === 'percent') {
            $disc = round($base * min(100, max(0, (float)$c['value'])) / 100, 2);
        } else {
            $disc = round((float)$c['value'], 2);
        }
        if ((float)$c['max_discount'] > 0) {
            $disc = min($disc, (float)$c['max_discount']);
        }
        $disc = max(0, min($disc, $base));
        if ($disc <= 0) {
            throw new \RuntimeException('该订单使用此优惠码没有优惠');
        }

        return [$c, $disc];
    }

    /** 前台试算（不占用次数） */
    public static function preview(array $user, $code, $type, array $data, $amount)
    {
        $amount = round((float)$amount, 2);
        $tierDisc = 0.0;
        $rate = OrderService::discountOf($user);
        if ($rate < 100) {
            $tierDisc = round($amount * (100 - $rate) / 100, 2);
        }
        $base = max(0, round($amount - $tierDisc, 2));
        list($c, $disc) = self::evaluate($user, $code, $type, $data, $base);

        return [
            'code' => $c['code'], 'name' => $c['name'], 'amount' => $amount, 'tier_discount' => $tierDisc,
            'coupon_discount' => $disc, 'pay_amount' => max(0, round($base - $disc, 2)),
        ];
    }

    /**
     * 订单创建时预留（在订单事务内调用；锁住优惠码行防止超发）
     * @return array 更新后的订单
     */
    public static function reserve(array $user, array $order, $code)
    {
        $code = strtoupper(trim((string)$code));
        $c = Db::name('coupon')->where('code', $code)->lock(true)->find();
        $data = json_decode((string)$order['data'], true) ?: [];
        $base = max(0, round((float)$order['amount'] - (float)$order['discount'], 2));
        list($c, $disc) = self::evaluate($user, $code, $order['type'], $data, $base, 0, $c);
        Db::name('coupon_use')->insert([
            'coupon_id' => $c['id'], 'order_id' => $order['id'], 'user_id' => $user['id'], 'discount' => $disc,
            'status' => 'reserved', 'create_time' => time(), 'update_time' => time(),
        ]);
        Db::name('coupon')->where('id', $c['id'])->setInc('reserved_count');
        Db::name('order')->where('id', $order['id'])->update([
            'coupon_id' => $c['id'], 'coupon_code' => $c['code'], 'coupon_discount' => $disc,
            'pay_amount' => max(0, round($base - $disc, 2)),
        ]);
        audit('coupon.reserve', '订单#' . $order['id'], ['code' => $c['code'], 'discount' => $disc], 'info', 'user', $user['id']);

        return Db::name('order')->find($order['id']);
    }

    /** 支付前复核：优惠码被停用/过期则拒绝发起支付 */
    public static function revalidate(array $order)
    {
        if (empty($order['coupon_id']) || $order['status'] !== 'unpaid') {
            return true;
        }
        $c = Db::name('coupon')->find($order['coupon_id']);
        $now = time();
        if (!$c || (int)$c['status'] !== 1 || ($c['end_time'] && $now > $c['end_time'])) {
            throw new \RuntimeException('订单使用的优惠码已失效，请先移除优惠码再支付');
        }

        return true;
    }

    /** 移除未支付订单上的优惠码 */
    public static function removeFromOrder(array $order)
    {
        if (empty($order['coupon_id']) || $order['status'] !== 'unpaid') {
            return $order;
        }
        self::release($order, '用户移除');
        Db::name('order')->where('id', $order['id'])->where('status', 'unpaid')->update([
            'coupon_id' => 0, 'coupon_code' => '', 'coupon_discount' => 0,
            'pay_amount' => max(0, round((float)$order['amount'] - (float)$order['discount'], 2)),
        ]);

        return Db::name('order')->find($order['id']);
    }

    /** 付款后消费 */
    public static function consume(array $order)
    {
        $u = Db::name('coupon_use')->where('order_id', $order['id'])->where('status', 'reserved')->find();
        if (!$u) {
            return false;
        }
        $aff = Db::name('coupon_use')->where('id', $u['id'])->where('status', 'reserved')->update(['status' => 'used', 'update_time' => time()]);
        if ($aff) {
            Db::name('coupon')->where('id', $u['coupon_id'])->update([
                'reserved_count' => Db::raw('GREATEST(reserved_count-1,0)'), 'used_count' => Db::raw('used_count+1'),
            ]);
        }

        return (bool)$aff;
    }

    /** 取消/超时/退款释放次数 */
    public static function release(array $order, $reason = '')
    {
        $u = Db::name('coupon_use')->where('order_id', $order['id'])->where('status', 'in', ['reserved', 'used'])->find();
        if (!$u) {
            return false;
        }
        $aff = Db::name('coupon_use')->where('id', $u['id'])->where('status', $u['status'])->update(['status' => 'released', 'update_time' => time()]);
        if ($aff) {
            $col = $u['status'] === 'reserved' ? 'reserved_count' : 'used_count';
            Db::name('coupon')->where('id', $u['coupon_id'])->update([$col => Db::raw('GREATEST(' . $col . '-1,0)')]);
            audit('coupon.release', '订单#' . $order['id'], ['coupon' => $u['coupon_id'], 'was' => $u['status'], 'reason' => $reason], 'info', 'system', 0, 'coupon');
        }

        return (bool)$aff;
    }

    /** 随机码 */
    public static function randomCode($prefix = '', $len = 8)
    {
        return strtoupper($prefix) . random_str($len, 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
    }

    /** 后台统计 */
    public static function stats($couponId)
    {
        $rows = Db::name('coupon_use')->where('coupon_id', (int)$couponId)->field('status, count(*) n, sum(discount) d')->group('status')->select();
        $out = ['reserved' => [0, 0], 'used' => [0, 0], 'released' => [0, 0]];
        foreach ($rows as $r) {
            $out[$r['status']] = [(int)$r['n'], (float)$r['d']];
        }

        return $out;
    }
}
