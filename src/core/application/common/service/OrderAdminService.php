<?php

namespace app\common\service;

use think\Db;

/**
 * 后台订单管理：编辑、删除（可选连带终止服务/删除解析/冲回佣金/退款）、手动开通/重新开通、暂停/恢复/终止
 * 所有操作写审计日志；DNS 删除只删本系统创建的记录，域名上架前已有的原有解析受保护永不删除
 */
class OrderAdminService
{
    public static $statuses = ['unpaid' => '待支付', 'paid' => '已支付', 'cancelled' => '已取消', 'refunded' => '已退款'];

    /** 订单关联对象 */
    public static function related(array $order)
    {
        $data = json_arr($order['data']);
        $r = ['services' => [], 'subs' => [], 'records' => 0, 'sales' => [], 'packages' => [], 'payments' => []];
        $sids = Db::name('service')->where('order_id', $order['id'])->column('id');
        if (in_array($order['type'], ['host_renew', 'host_upgrade'], true) && !empty($data['service_id'])) {
            $sids[] = (int)$data['service_id'];
        }
        if ($sids) {
            $r['services'] = Db::name('service')->where('id', 'in', array_unique($sids))->select();
        }
        $subIds = Db::name('dns_subdomain')->where('order_id', $order['id'])->column('id');
        if ($order['type'] === 'dns_sub_renew' && !empty($data['sub_id'])) {
            $subIds[] = (int)$data['sub_id'];
        }
        if ($subIds) {
            $r['subs'] = Db::name('dns_subdomain')->where('id', 'in', array_unique($subIds))->select();
            $r['records'] = Db::name('dns_record')->where('sub_id', 'in', array_unique($subIds))->count();
        }
        $r['sales'] = Db::name('market_sale')->where('order_id', $order['id'])->select();
        $r['packages'] = Db::name('user_package')->where('order_id', $order['id'])->select();
        $r['payments'] = Db::name('payment')->where('order_id', $order['id'])->order('id desc')->select();

        return $r;
    }

    /**
     * 编辑订单及其关联对象
     * $in: title, amount, discount, pay_amount, status, admin_remark, data(数组：cycle/product_id/prefix/hostname…),
     *      service_due(Y-m-d H:i), service_price, service_cycle, service_status, service_product_id, sub_expire(Y-m-d H:i), sub_status
     */
    public static function update($id, array $in, $adminName = '')
    {
        $o = Db::name('order')->find($id);
        if (!$o) {
            throw new \Exception('订单不存在');
        }
        $up = ['update_time' => time()];
        foreach (['title' => 250, 'admin_remark' => 490] as $k => $len) {
            if (isset($in[$k])) {
                $up[$k] = mb_substr(trim((string)$in[$k]), 0, $len);
            }
        }
        foreach (['amount', 'discount', 'pay_amount'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '') {
                if (!is_numeric($in[$k]) || (float)$in[$k] < 0) {
                    throw new \Exception('金额格式不正确');
                }
                $up[$k] = round((float)$in[$k], 2);
            }
        }
        if (isset($in['status']) && $in['status'] !== '' && $in['status'] !== $o['status']) {
            if (!isset(self::$statuses[$in['status']])) {
                throw new \Exception('订单状态无效');
            }
            $up['status'] = $in['status'];
            if ($in['status'] === 'paid' && !$o['paid_time']) {
                $up['paid_time'] = time();
                $up['gateway'] = $o['gateway'] ?: 'admin';
            }
        }
        if (isset($in['data']) && is_array($in['data'])) {
            $data = json_arr($o['data']);
            foreach ($in['data'] as $k => $v) {
                if (preg_match('/^[a-z_]{1,32}$/', $k)) {
                    $data[$k] = is_numeric($v) && ctype_digit((string)$v) ? (int)$v : (string)$v;
                }
            }
            if (isset($data['cycle']) && !isset(billing_cycles()[$data['cycle']])) {
                throw new \Exception('计费周期无效');
            }
            if (!empty($data['product_id']) && !Db::name('product')->find($data['product_id'])) {
                throw new \Exception('商品不存在');
            }
            $up['data'] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        Db::name('order')->where('id', $id)->update($up);
        $rel = self::related($o);
        // 关联云主机
        if ($rel['services']) {
            $s = $rel['services'][0];
            $su = [];
            if (!empty($in['service_due'])) {
                $t = strtotime($in['service_due']);
                if (!$t) {
                    throw new \Exception('到期时间格式不正确');
                }
                $su['due_time'] = $t;
            }
            if (isset($in['service_price']) && $in['service_price'] !== '') {
                $su['price'] = round((float)$in['service_price'], 2);
            }
            if (!empty($in['service_cycle']) && isset(billing_cycles()[$in['service_cycle']])) {
                $su['billing_cycle'] = $in['service_cycle'];
            }
            if (!empty($in['service_product_id']) && (int)$in['service_product_id'] !== (int)$s['product_id']) {
                $p = Db::name('product')->find($in['service_product_id']);
                if (!$p) {
                    throw new \Exception('商品不存在');
                }
                $su['product_id'] = $p['id'];
            }
            if (!empty($in['service_status']) && in_array($in['service_status'], ['pending', 'active', 'suspended', 'terminated', 'failed'], true)) {
                // 仅修改本地状态；如需同步上游请用 暂停/恢复/终止 按钮
                $su['status'] = $in['service_status'];
            }
            if ($su) {
                $su['update_time'] = time();
                Db::name('service')->where('id', $s['id'])->update($su);
            }
        }
        // 关联子域名
        if ($rel['subs']) {
            $sub = $rel['subs'][0];
            $bu = [];
            if (isset($in['sub_expire']) && $in['sub_expire'] !== '') {
                $t = $in['sub_expire'] === '0' ? 0 : strtotime($in['sub_expire']);
                if ($t === false) {
                    throw new \Exception('到期时间格式不正确');
                }
                $bu['expire_time'] = $t;
            }
            if (!empty($in['sub_status']) && in_array($in['sub_status'], ['active', 'review', 'suspended', 'expired'], true)) {
                $bu['status'] = $in['sub_status'];
            }
            if ($bu) {
                $bu['update_time'] = time();
                Db::name('dns_subdomain')->where('id', $sub['id'])->update($bu);
            }
        }
        audit('order.edit', '订单#' . $id, ['before' => array_intersect_key($o, $up), 'after' => $up, 'extra' => array_intersect_key($in, array_flip(['service_due', 'service_price', 'service_cycle', 'service_status', 'service_product_id', 'sub_expire', 'sub_status']))], 'warn', 'admin', null, $adminName ?: null);

        return Db::name('order')->find($id);
    }

    /**
     * 手动开通 / 重新开通
     * 未支付订单：标记为已支付（网关 admin，不产生支付流水，可选从用户余额扣款）后交付
     * 已支付订单：重新交付；续费/升级类仅在上次交付未成功时允许，避免重复延期
     */
    public static function provision($id, $chargeBalance = false, $adminName = '')
    {
        $o = Db::name('order')->find($id);
        if (!$o) {
            throw new \Exception('订单不存在');
        }
        if ($o['status'] === 'cancelled' || $o['status'] === 'refunded') {
            throw new \Exception('已取消/已退款的订单不能开通，请先把状态改为待支付或已支付');
        }
        if ($o['status'] === 'unpaid') {
            if ($chargeBalance) {
                OrderService::payWithBalance($o); // 内含交付
                audit('order.manual_provision', '订单#' . $id, ['mode' => 'balance'], 'warn', 'admin', null, $adminName ?: null);

                return Db::name('order')->find($id);
            }
            $aff = Db::name('order')->where('id', $id)->where('status', 'unpaid')->update(['status' => 'paid', 'gateway' => 'admin', 'paid_time' => time(), 'update_time' => time()]);
            if (!$aff) {
                throw new \Exception('订单状态已变化，请刷新');
            }
        } else {
            $once = ['host_renew', 'host_upgrade', 'dns_sub_renew', 'tier', 'recharge'];
            if (in_array($o['type'], $once, true) && $o['deliver_status'] === 'done') {
                throw new \Exception('该订单已交付成功，重复交付会再次延期/升级；如确需调整请编辑订单或服务到期时间');
            }
            if ($o['type'] === 'dns_package' && $o['deliver_status'] === 'done') {
                throw new \Exception('套餐已生效，无需重复开通');
            }
        }
        $o = Db::name('order')->find($id);
        // 失败的云主机：直接重试上游开通
        foreach (Db::name('service')->where('order_id', $id)->where('status', 'in', ['failed', 'pending'])->select() as $s) {
            ProvisionService::provision($s['id']);
        }
        OrderService::deliver($o);
        audit('order.manual_provision', '订单#' . $id, ['mode' => $o['gateway'] === 'admin' ? 'free' : 'redeliver'], 'warn', 'admin', null, $adminName ?: null);

        return Db::name('order')->find($id);
    }

    /** 暂停 / 恢复 / 终止 订单关联的服务或子域名 */
    public static function lifecycle($id, $action, $reason = '', $adminName = '')
    {
        if (!in_array($action, ['suspend', 'unsuspend', 'terminate'], true)) {
            throw new \Exception('不支持的操作');
        }
        $o = Db::name('order')->find($id);
        if (!$o) {
            throw new \Exception('订单不存在');
        }
        $rel = self::related($o);
        if (!$rel['services'] && !$rel['subs']) {
            throw new \Exception('该订单没有已开通的云主机或子域名');
        }
        $msgs = [];
        foreach ($rel['services'] as $s) {
            if ($s['remote_id'] === '') {
                $map = ['suspend' => 'suspended', 'unsuspend' => 'active', 'terminate' => 'terminated'];
                Db::name('service')->where('id', $s['id'])->update(['status' => $map[$action], 'update_time' => time()]);
                $msgs[] = '服务#' . $s['id'] . ' 未在上游开通，仅更新本地状态';
                continue;
            }
            ProvisionService::action($s['id'], $action, ['reason' => $reason ?: '管理员操作'], true);
            $msgs[] = '服务#' . $s['id'] . ' 已' . ['suspend' => '暂停', 'unsuspend' => '恢复', 'terminate' => '终止'][$action];
        }
        foreach ($rel['subs'] as $sub) {
            if ($action === 'suspend') {
                DnsService::suspendSub($sub['id'], $reason ?: '管理员暂停');
            } elseif ($action === 'unsuspend') {
                $err = DnsService::resumeSub($sub['id']);
                if ($err) {
                    $msgs[] = $sub['fqdn'] . ' 部分记录恢复失败：' . implode('；', $err);
                }
            } else {
                DnsService::releaseSub($sub['id'], 'admin_terminate');
            }
            $msgs[] = $sub['fqdn'] . ' 已' . ['suspend' => '暂停', 'unsuspend' => '恢复', 'terminate' => '释放'][$action];
        }
        audit('order.' . $action, '订单#' . $id, ['reason' => $reason, 'result' => $msgs], 'warn', 'admin', null, $adminName ?: null);

        return $msgs;
    }

    /**
     * 撤销订单产生的效果（删除与取消共用），幂等：已终止/已释放/已冲回的会跳过
     * $opt: terminate remove_dns reverse_commission refund(=full 兼容) refund_mode(full|partial|none) refund_amount
     */
    protected static function unwind(array $o, array $opt, array &$result)
    {
        $rel = self::related($o);
        if (!empty($opt['terminate'])) {
            foreach ($rel['services'] as $s) {
                if ($o['type'] !== 'host_new' || $s['status'] === 'terminated') {
                    continue; // 续费/升级订单不终止原服务
                }
                try {
                    if ($s['remote_id'] !== '') {
                        ProvisionService::action($s['id'], 'terminate', [], true);
                    } else {
                        Db::name('service')->where('id', $s['id'])->update(['status' => 'terminated', 'update_time' => time()]);
                    }
                    $result[] = '服务#' . $s['id'] . ' 已终止';
                } catch (\Exception $e) {
                    throw new \Exception('终止服务#' . $s['id'] . ' 失败：' . $e->getMessage() . '（订单未变更，可修复后重试）');
                }
            }
        }
        if (!empty($opt['remove_dns'])) {
            foreach ($rel['subs'] as $sub) {
                if ($o['type'] !== 'dns_sub_new') {
                    continue;
                }
                DnsService::releaseSub($sub['id'], 'order_unwind'); // 只删系统创建的记录，受保护的原有解析不会被删除
                $result[] = $sub['fqdn'] . ' 已释放（仅删除本系统创建的解析）';
            }
            foreach ($rel['packages'] as $p) {
                Db::name('user_package')->where('id', $p['id'])->delete();
                $result[] = '用户套餐#' . $p['id'] . ' 已撤销';
            }
        }
        if (!empty($opt['reverse_commission'])) {
            foreach ($rel['sales'] as $sale) {
                $aff = Db::name('market_sale')->where('id', $sale['id'])->delete();
                if ($aff && (float)$sale['income'] > 0) {
                    OrderService::addBalance($sale['seller_id'], -(float)$sale['income'], 'sale_reverse', '订单 ' . $o['order_no'] . ' 撤销，冲回销售收入');
                    Db::name('dns_domain')->where('id', $sale['domain_id'])->dec('income', (float)$sale['income'])->update();
                    $result[] = '已冲回卖家#' . $sale['seller_id'] . ' 收入 ' . money($sale['income']);
                }
            }
        }
        $mode = isset($opt['refund_mode']) ? $opt['refund_mode'] : (!empty($opt['refund']) ? 'full' : 'none');
        $refund = 0.0;
        if ($o['status'] === 'paid' && $mode !== 'none') {
            $refunded = (float)Db::name('balance_log')->where('user_id', $o['user_id'])->where('type', 'refund')->where('remark', 'like', '%' . $o['order_no'] . '%')->sum('amount');
            $max = round((float)$o['pay_amount'] - $refunded, 2);
            $refund = $mode === 'full' ? $max : round((float)(isset($opt['refund_amount']) ? $opt['refund_amount'] : 0), 2);
            if ($refund < 0 || $refund > $max + 0.001) {
                throw new \Exception('退款金额需在 0 - ' . money($max) . ' 之间');
            }
            if ($refund > 0) {
                OrderService::addBalance($o['user_id'], $refund, 'refund', '订单 ' . $o['order_no'] . ' 退款');
                $result[] = '已退款 ' . money($refund) . ' 到用户余额';
            }
        }

        return $refund;
    }

    /** 删除订单（选项同 unwind） */
    public static function delete($id, array $opt, $adminName = '')
    {
        $o = Db::name('order')->find($id);
        if (!$o) {
            throw new \Exception('订单不存在');
        }
        $result = [];
        self::unwind($o, $opt, $result);
        // 未选择终止/释放时保留服务/子域名，仅解除关联
        Db::name('service')->where('order_id', $id)->update(['order_id' => 0]);
        Db::name('dns_subdomain')->where('order_id', $id)->update(['order_id' => 0]);
        OrderService::onReleased($o, '订单删除');
        Db::name('order')->where('id', $id)->delete();
        audit('order.delete', '订单#' . $id . ' ' . $o['order_no'], ['order' => $o, 'opt' => $opt, 'result' => $result], 'danger', 'admin', null, $adminName ?: null);

        return $result;
    }

    /**
     * 管理员取消订单（任意状态），幂等：已取消/已退款直接返回
     * 已支付订单可选：退款（全额/部分/不退）、终止服务/释放子域名、冲回佣金
     */
    public static function cancel($id, array $opt, $adminName = '')
    {
        $o = Db::name('order')->find($id);
        if (!$o) {
            throw new \Exception('订单不存在');
        }
        if (in_array($o['status'], ['cancelled', 'refunded'], true)) {
            return ['订单已是' . self::$statuses[$o['status']] . '状态，无需重复操作'];
        }
        $result = [];
        $refund = self::unwind($o, $opt, $result);
        $status = $o['status'] === 'paid' && $refund > 0 ? 'refunded' : 'cancelled';
        $note = trim((string)(isset($opt['reason']) ? $opt['reason'] : ''));
        Db::name('order')->where('id', $id)->where('status', $o['status'])->update([
            'status' => $status, 'update_time' => time(),
            'admin_remark' => mb_substr(trim($o['admin_remark'] . ' [取消' . ($note !== '' ? '：' . $note : '') . ']'), 0, 490),
        ]);
        Db::name('payment')->where('order_id', $id)->where('status', 0)->update(['status' => 2]);
        OrderService::onReleased($o, $status === 'refunded' ? '订单退款' : '管理员取消');
        audit('order.cancel', '订单#' . $id . ' ' . $o['order_no'], ['opt' => $opt, 'result' => $result, 'status' => $status], 'warn', 'admin', null, $adminName ?: null);

        return $result ?: ['订单已取消'];
    }

    /** 用户取消自己的待支付订单 */
    public static function userCancel(array $user, $id)
    {
        $o = Db::name('order')->where('id', $id)->where('user_id', $user['id'])->find();
        if (!$o) {
            throw new \Exception('订单不存在');
        }
        if ($o['status'] === 'cancelled') {
            return true;
        }
        if ($o['status'] !== 'unpaid') {
            throw new \Exception('只有待支付订单可以直接取消；已开通的服务请提交取消申请');
        }
        Db::name('order')->where('id', $id)->where('status', 'unpaid')->update(['status' => 'cancelled', 'update_time' => time()]);
        Db::name('payment')->where('order_id', $id)->where('status', 0)->update(['status' => 2]);
        OrderService::onReleased($o, '用户取消');
        audit('order.user_cancel', '订单#' . $id . ' ' . $o['order_no'], '', 'info', 'user', $user['id'], $user['username']);

        return true;
    }

    /* ---------- 服务取消申请（用户提交、管理员审核） ---------- */

    /** $type: service|sub  $mode: immediate|end */
    public static function requestCancel(array $user, $type, $targetId, $mode, $reason)
    {
        if (setting('cancel_request_enable', '1') !== '1') {
            throw new \Exception('暂未开放取消申请，请提交工单');
        }
        if (!in_array($type, ['service', 'sub'], true) || !in_array($mode, ['immediate', 'end'], true)) {
            throw new \Exception('参数错误');
        }
        $row = $type === 'service'
            ? Db::name('service')->where('id', $targetId)->where('user_id', $user['id'])->where('status', 'in', ['active', 'suspended'])->find()
            : Db::name('dns_subdomain')->where('id', $targetId)->where('user_id', $user['id'])->where('status', 'in', ['active', 'suspended', 'review'])->find();
        if (!$row) {
            throw new \Exception('服务不存在或当前状态不可取消');
        }
        if (Db::name('cancel_request')->where('type', $type)->where('target_id', $targetId)->where('status', 'in', ['pending', 'approved'])->count()) {
            throw new \Exception('已提交过取消申请，请等待处理');
        }
        $id = Db::name('cancel_request')->insertGetId([
            'user_id' => $user['id'], 'type' => $type, 'target_id' => (int)$targetId, 'mode' => $mode,
            'reason' => mb_substr(trim((string)$reason), 0, 490), 'status' => 'pending', 'create_time' => time(),
        ]);
        audit('cancel.request', ($type === 'service' ? '服务#' : '子域名#') . $targetId, ['mode' => $mode, 'reason' => $reason], 'info', 'user', $user['id'], $user['username']);
        \app\common\lib\Notify::admin('risk_alert', ['title' => '新的取消申请', 'content' => '用户 ' . $user['username'] . ' 申请' . ($mode === 'immediate' ? '立即' : '到期') . '取消 ' . ($type === 'service' ? '云主机#' : '子域名#') . $targetId]);

        return $id;
    }

    /** 用户撤回待处理的申请 */
    public static function withdrawRequest(array $user, $id)
    {
        $aff = Db::name('cancel_request')->where('id', $id)->where('user_id', $user['id'])->where('status', 'in', ['pending', 'approved'])->update(['status' => 'withdrawn', 'handle_time' => time()]);
        if (!$aff) {
            throw new \Exception('申请不存在或已执行');
        }

        return true;
    }

    /**
     * 审核取消申请：approve → 立即执行或等到期由计划任务执行；reject → 驳回
     * $opt: refund_amount（立即取消时退款到余额，0=不退）
     */
    public static function handleRequest($id, $approve, $note = '', array $opt = [], $adminName = '')
    {
        $r = Db::name('cancel_request')->find($id);
        if (!$r || $r['status'] !== 'pending') {
            throw new \Exception('申请不存在或已处理');
        }
        if (!$approve) {
            Db::name('cancel_request')->where('id', $id)->where('status', 'pending')->update(['status' => 'rejected', 'admin_note' => mb_substr($note, 0, 250), 'handle_time' => time()]);
            audit('cancel.reject', '申请#' . $id, ['note' => $note], 'info', 'admin', null, $adminName ?: null);

            return '已驳回';
        }
        Db::name('cancel_request')->where('id', $id)->where('status', 'pending')->update(['status' => 'approved', 'admin_note' => mb_substr($note, 0, 250), 'handle_time' => time()]);
        audit('cancel.approve', '申请#' . $id, ['note' => $note, 'mode' => $r['mode']], 'warn', 'admin', null, $adminName ?: null);
        if ($r['mode'] === 'immediate') {
            return self::executeRequest($id, isset($opt['refund_amount']) ? (float)$opt['refund_amount'] : 0);
        }

        return '已批准，将在到期时自动终止';
    }

    /** 执行已批准的取消（立即或到期），幂等 */
    public static function executeRequest($id, $refund = 0.0)
    {
        $r = Db::name('cancel_request')->find($id);
        if (!$r || $r['status'] !== 'approved') {
            return '申请状态不可执行';
        }
        $aff = Db::name('cancel_request')->where('id', $id)->where('status', 'approved')->update(['status' => 'done', 'done_time' => time()]);
        if (!$aff) {
            return '已执行';
        }
        $msg = '';
        try {
            if ($r['type'] === 'service') {
                $s = Db::name('service')->find($r['target_id']);
                if ($s && $s['status'] !== 'terminated') {
                    if ($s['remote_id'] !== '') {
                        ProvisionService::action($s['id'], 'terminate', [], true);
                    } else {
                        Db::name('service')->where('id', $s['id'])->update(['status' => 'terminated', 'update_time' => time()]);
                    }
                }
                $msg = '云主机#' . $r['target_id'] . ' 已终止';
            } else {
                DnsService::releaseSub($r['target_id'], 'cancel_request');
                $msg = '子域名#' . $r['target_id'] . ' 已释放';
            }
        } catch (\Exception $e) {
            Db::name('cancel_request')->where('id', $id)->update(['status' => 'approved', 'done_time' => 0, 'admin_note' => mb_substr('执行失败：' . $e->getMessage(), 0, 250)]);
            throw $e;
        }
        if ($refund > 0) {
            OrderService::addBalance($r['user_id'], round($refund, 2), 'refund', '取消申请#' . $id . ' 退款');
            $msg .= '，已退款 ' . money($refund);
        }
        audit('cancel.done', '申请#' . $id, ['msg' => $msg], 'warn', 'system', 0, 'cancel');
        $u = Db::name('user')->find($r['user_id']);
        if ($u) {
            \app\common\lib\Notify::user('risk_alert', $u, ['title' => '取消申请已处理', 'content' => $msg]);
        }

        return $msg;
    }

    /** 计划任务：执行到期的“到期取消”申请 */
    public static function cronDueRequests($limit = 20)
    {
        $n = 0;
        foreach (Db::name('cancel_request')->where('status', 'approved')->where('mode', 'end')->limit($limit)->select() as $r) {
            $due = $r['type'] === 'service' ? (int)Db::name('service')->where('id', $r['target_id'])->value('due_time') : (int)Db::name('dns_subdomain')->where('id', $r['target_id'])->value('expire_time');
            if ($due > 0 && $due > time()) {
                continue;
            }
            try {
                self::executeRequest($r['id']);
                $n++;
            } catch (\Exception $e) {
            }
        }

        return $n;
    }
}
