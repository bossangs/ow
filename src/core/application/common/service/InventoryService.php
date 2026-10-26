<?php

namespace app\common\service;

use app\common\lib\Notify;
use think\Db;

/**
 * 库存管理
 *
 * 可售对象：
 *   product  云主机/NAT 产品：stock（-1 不限）- sold - reserved；
 *            NAT 产品另受“在线节点容量”限制：每个节点 min(max_instances, 端口段 / 每实例端口数) - 已用实例；
 *            nat_stock_cap ≥ 0 时作为手动上限。
 *   package  DNS 套餐：stock（-1 不限）- sold - reserved
 *   domain   可分发域名（含市场上架域名）：stock（0 不限）- 已注册子域名数 - reserved
 *
 * 流程：下单 reserve() 预留 → 付款 commit() 计入已售 → 取消/超时/退款 release() 退回。
 * 所有变更都在事务 + 行锁中完成，避免超卖。
 */
class InventoryService
{
    /** 订单对应的库存对象 [type, id]，不涉及库存返回 null */
    public static function itemOf(array $order)
    {
        $d = is_array($order['data']) ? $order['data'] : (json_decode((string)$order['data'], true) ?: []);
        if ($order['type'] === 'host_new' && !empty($d['product_id'])) {
            return ['product', (int)$d['product_id']];
        }
        if ($order['type'] === 'dns_package' && !empty($d['package_id']) && empty($d['user_package_id'])) {
            return ['package', (int)$d['package_id']];
        }
        if ($order['type'] === 'dns_sub_new' && !empty($d['domain_id'])) {
            return ['domain', (int)$d['domain_id']];
        }

        return null;
    }

    protected static function table($type)
    {
        $map = ['product' => 'product', 'package' => 'dns_package', 'domain' => 'dns_domain'];

        return $map[$type];
    }

    public static function typeName($type)
    {
        $map = ['product' => '产品', 'package' => 'DNS 套餐', 'domain' => '域名'];

        return isset($map[$type]) ? $map[$type] : $type;
    }

    /** 当前预留数量（domain 表的 reserved 字段是保留前缀，所以统一从 inventory_hold 统计） */
    protected static function holds($type, $id)
    {
        return (int)Db::name('inventory_hold')->where('item_type', $type)->where('item_id', $id)
            ->where('status', 'reserved')->sum('qty');
    }

    /** NAT 节点容量：返回 [总容量, 已用]，非 NAT 产品返回 null */
    public static function natCapacity(array $product)
    {
        if (!$product['upstream_id']) {
            return null;
        }
        $up = Db::name('upstream')->find($product['upstream_id']);
        if (!$up || $up['driver'] !== 'natnode') {
            return null;
        }
        $opt = json_decode((string)$product['upstream_options'], true) ?: [];
        $perInst = max(1, (int)(isset($opt['port_count']) ? $opt['port_count'] : setting('nat_ports_per_instance', '20')));
        $cap = 0;
        foreach (Db::name('nat_node')->where('upstream_id', $up['id'])->where('status', 1)->select() as $n) {
            $o = json_decode((string)$n['options'], true) ?: [];
            $max = isset($o['max_instances']) ? (int)$o['max_instances'] : 100;
            $ps = isset($o['port_start']) ? (int)$o['port_start'] : 0;
            $pe = isset($o['port_end']) ? (int)$o['port_end'] : 0;
            if ($pe > $ps) {
                $max = min($max, (int)floor(($pe - $ps + 1) / $perInst));
            }
            $cap += max(0, $max);
        }
        $used = Db::name('service')->where('upstream_id', $up['id'])
            ->where('status', 'in', ['pending', 'active', 'suspended'])->count();

        return [$cap, $used];
    }

    /**
     * 剩余可售数量；-1 表示不限
     */
    public static function available($type, $id)
    {
        $row = Db::name(self::table($type))->find($id);
        if (!$row) {
            return 0;
        }
        $left = null; // null = 不限
        $holds = self::holds($type, $id);
        if ($type === 'product') {
            if ((int)$row['stock'] >= 0) {
                $left = (int)$row['stock'] - (int)$row['sold'] - $holds;
            }
            $nat = self::natCapacity($row);
            if ($nat !== null) {
                // 已付款未开通的订单也算占用（已经 commit 但还没生成实例）
                $pendingPaid = 0;
                $paidOrders = Db::name('inventory_hold')->where('item_type', 'product')->where('item_id', $id)
                    ->where('status', 'committed')->where('update_time', '>', time() - 86400 * 3)->column('order_id');
                if ($paidOrders) {
                    $withService = Db::name('service')->where('order_id', 'in', $paidOrders)->column('order_id');
                    $pendingPaid = count(array_diff($paidOrders, $withService));
                }
                $natLeft = $nat[0] - $nat[1] - $holds - $pendingPaid;
                if ((int)$row['nat_stock_cap'] >= 0) {
                    $natLeft = min($natLeft, (int)$row['nat_stock_cap'] - (int)$row['sold'] - $holds);
                }
                $left = $left === null ? $natLeft : min($left, $natLeft);
            }
        } elseif ($type === 'package') {
            if ((int)$row['stock'] >= 0) {
                $left = (int)$row['stock'] - (int)$row['sold'] - $holds;
            }
        } else {
            if ((int)$row['stock'] > 0) {
                $left = (int)$row['stock'] - Db::name('dns_subdomain')->where('domain_id', $id)->count() - $holds;
            }
        }

        return $left === null ? -1 : max(0, $left);
    }

    /** 是否售罄 */
    public static function soldOut($type, $id)
    {
        $a = self::available($type, $id);

        return $a !== -1 && $a <= 0;
    }

    /** 用户已购数量（含待支付预留） */
    public static function userCount($type, $id, $userId)
    {
        $holds = (int)Db::name('inventory_hold')->where('item_type', $type)->where('item_id', $id)
            ->where('user_id', $userId)->where('status', 'reserved')->sum('qty');
        if ($type === 'product') {
            $n = Db::name('service')->where('product_id', $id)->where('user_id', $userId)
                ->where('status', 'in', ['pending', 'active', 'suspended'])->count();
        } elseif ($type === 'package') {
            $n = Db::name('user_package')->where('package_id', $id)->where('user_id', $userId)
                ->where('expire_time', '>', time())->count();
        } else {
            $n = Db::name('dns_subdomain')->where('domain_id', $id)->where('user_id', $userId)->count();
        }

        return $n + $holds;
    }

    /** 每用户限购 */
    protected static function perUserLimit($type, array $row)
    {
        if ($type === 'domain') {
            return (int)$row['max_per_user'];
        }

        return (int)$row['per_user_limit'];
    }

    /** 下单前检查（不加锁，用于展示与早期提示） */
    public static function check($type, $id, $userId, $qty = 1)
    {
        $row = Db::name(self::table($type))->find($id);
        if (!$row) {
            throw new \RuntimeException(self::typeName($type) . '不存在');
        }
        $a = self::available($type, $id);
        if ($a !== -1 && $a < $qty) {
            throw new \RuntimeException('已售罄');
        }
        $lim = self::perUserLimit($type, $row);
        if ($lim > 0 && self::userCount($type, $id, $userId) + $qty > $lim) {
            throw new \RuntimeException('每个账户最多购买 ' . $lim . ' 个');
        }

        return true;
    }

    /**
     * 订单创建时预留（须在订单创建事务内调用）
     */
    public static function reserve(array $order, $qty = 1)
    {
        $item = self::itemOf($order);
        if (!$item) {
            return false;
        }
        list($type, $id) = $item;
        // 锁住对象行，串行化同一商品的并发下单
        $row = Db::name(self::table($type))->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new \RuntimeException(self::typeName($type) . '不存在');
        }
        self::check($type, $id, $order['user_id'], $qty);
        Db::name('inventory_hold')->insert([
            'order_id' => $order['id'], 'user_id' => $order['user_id'], 'item_type' => $type, 'item_id' => $id,
            'qty' => $qty, 'status' => 'reserved', 'create_time' => time(), 'update_time' => time(),
        ]);
        if ($type !== 'domain') {
            Db::name(self::table($type))->where('id', $id)->setInc('reserved', $qty);
        }

        return true;
    }

    /** 付款后：预留转为已售 */
    public static function commit(array $order)
    {
        $h = Db::name('inventory_hold')->where('order_id', $order['id'])->where('status', 'reserved')->find();
        if (!$h) {
            return false;
        }
        $aff = Db::name('inventory_hold')->where('id', $h['id'])->where('status', 'reserved')
            ->update(['status' => 'committed', 'update_time' => time()]);
        if ($aff && $h['item_type'] !== 'domain') {
            Db::name(self::table($h['item_type']))->where('id', $h['item_id'])
                ->update(['reserved' => Db::raw('GREATEST(reserved-' . (int)$h['qty'] . ',0)'), 'sold' => Db::raw('sold+' . (int)$h['qty'])]);
        }
        self::checkItem($h['item_type'], $h['item_id']);

        return (bool)$aff;
    }

    /**
     * 取消/超时/退款：退回库存
     * 已付款（committed）的退回已售数；未付款（reserved）的退回预留数。
     */
    public static function release(array $order, $reason = '')
    {
        $h = Db::name('inventory_hold')->where('order_id', $order['id'])->where('status', 'in', ['reserved', 'committed'])->find();
        if (!$h) {
            return false;
        }
        $aff = Db::name('inventory_hold')->where('id', $h['id'])->where('status', $h['status'])
            ->update(['status' => 'released', 'update_time' => time()]);
        if ($aff && $h['item_type'] !== 'domain') {
            $col = $h['status'] === 'reserved' ? 'reserved' : 'sold';
            Db::name(self::table($h['item_type']))->where('id', $h['item_id'])
                ->update([$col => Db::raw('GREATEST(' . $col . '-' . (int)$h['qty'] . ',0)')]);
        }
        if ($aff) {
            audit('inventory.release', self::typeName($h['item_type']) . '#' . $h['item_id'], ['order' => $order['order_no'], 'was' => $h['status'], 'reason' => $reason], 'info', 'system', 0, 'inventory');
        }
        self::checkItem($h['item_type'], $h['item_id']);

        return (bool)$aff;
    }

    /** 提醒阈值 */
    protected static function threshold(array $row)
    {
        return (int)$row['stock_alert'] >= 0 ? (int)$row['stock_alert'] : (int)setting('stock_alert_default', '5');
    }

    /**
     * 检查单个对象并按状态变化发送提醒（ok → low → out → 补货）
     * 只在状态变化时通知，天然去重。
     */
    public static function checkItem($type, $id)
    {
        if (setting('stock_alert_enable', '1') !== '1') {
            return null;
        }
        $row = Db::name(self::table($type))->find($id);
        if (!$row || (isset($row['status']) && (int)$row['status'] !== 1)) {
            return null;
        }
        $left = self::available($type, $id);
        if ($left === -1) {
            if ($row['stock_state'] !== '') {
                Db::name(self::table($type))->where('id', $id)->update(['stock_state' => '']);
            }

            return null;
        }
        $th = self::threshold($row);
        $state = $left <= 0 ? 'out' : ($left <= $th ? 'low' : 'ok');
        $prev = (string)$row['stock_state'];
        if ($state === $prev) {
            return $state;
        }
        $aff = Db::name(self::table($type))->where('id', $id)->where('stock_state', $prev)->update(['stock_state' => $state]);
        if (!$aff) {
            return $state;
        }
        $name = $type === 'domain' ? $row['domain'] : $row['name'];
        $vars = [
            'item' => $name, 'item_type' => self::typeName($type), 'stock' => $left, 'threshold' => $th,
            '_key' => $type . $id . '-' . $state . '-' . time(),
        ];
        $event = null;
        if ($state === 'low' && $prev !== 'out') {
            $event = 'stock_low';
        } elseif ($state === 'out') {
            $event = 'stock_out';
        } elseif (($state === 'ok' || $state === 'low') && ($prev === 'out' || ($prev === 'low' && $state === 'ok'))) {
            $event = $prev === 'out' || setting('stock_restock_on_ok', '0') === '1' ? 'stock_restock' : null;
        }
        if ($event) {
            Notify::admin($event, $vars);
            audit('inventory.' . $event, self::typeName($type) . '#' . $id, ['name' => $name, 'left' => $left, 'threshold' => $th, 'from' => $prev, 'to' => $state], $event === 'stock_restock' ? 'info' : 'warn', 'system', 0, 'inventory');
        }

        return $state;
    }

    /** 计划任务：检查全部有限库存对象 */
    public static function checkAll()
    {
        $n = 0;
        foreach (Db::name('product')->where('status', 1)->column('id') as $id) {
            self::checkItem('product', $id);
            $n++;
        }
        foreach (Db::name('dns_package')->where('status', 1)->column('id') as $id) {
            self::checkItem('package', $id);
            $n++;
        }
        foreach (Db::name('dns_domain')->where('status', 1)->where('stock', '>', 0)->column('id') as $id) {
            self::checkItem('domain', $id);
            $n++;
        }

        // 清理超过 1 小时的“已付款未开通”标记不需要；过期未付预留由订单超时取消释放
        return $n;
    }
}
