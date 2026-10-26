<?php

namespace app\common\service;

use think\Db;
use app\common\lib\Notify;

/**
 * 到期生命周期：续费提醒 → 逾期自动暂停 → 宽限期后自动终止/释放；未付款订单超时自动取消；宽限期内续费自动恢复
 * 全局默认在「系统设置 → 到期策略」，可被 商品（云主机/NAT）、域名（子域名）、DNS 套餐 的 lifecycle JSON 覆盖（留空=继承）
 * 计划任务批量、幂等执行；DNS 只操作本系统创建的记录，域名原有解析受保护
 */
class LifecycleService
{
    /** 全局默认 */
    public static function defaults()
    {
        return [
            'remind_enable' => setting('lc_remind_enable', '1') === '1',
            'remind_days' => self::days(setting('lc_remind_days', '7,3,1')),
            'remind_site' => setting('lc_remind_site', '1') === '1',
            'suspend_enable' => setting('lc_suspend_enable', '1') === '1',
            'suspend_days' => max(0, (int)setting('lc_suspend_days', '1')),
            'terminate_enable' => setting('lc_terminate_enable', '1') === '1',
            'terminate_days' => max(1, (int)setting('lc_terminate_days', '7')),
            'dns_mode' => setting('lc_dns_suspend_mode', 'pause') === 'remove' ? 'remove' : 'pause',
            'unpaid_enable' => setting('lc_unpaid_cancel_enable', '1') === '1',
            'unpaid_hours' => max(1, (int)setting('lc_unpaid_cancel_hours', '24')),
            'auto_unsuspend' => setting('lc_auto_unsuspend', '1') === '1',
        ];
    }

    public static function days($s)
    {
        $out = [];
        foreach (preg_split('/[,\s，]+/', (string)$s) as $d) {
            if (ctype_digit(trim($d)) && (int)$d > 0 && (int)$d <= 365) {
                $out[] = (int)$d;
            }
        }
        $out = array_values(array_unique($out));
        rsort($out);

        return $out;
    }

    /** 可覆盖的字段（后台商品/域名/套餐编辑页使用） */
    public static function overrideFields()
    {
        return [
            'remind_enable' => ['到期提醒', 'bool'],
            'remind_days' => ['提前提醒天数（逗号分隔）', 'days'],
            'suspend_enable' => ['逾期自动暂停', 'bool'],
            'suspend_days' => ['逾期多少天暂停', 'int'],
            'terminate_enable' => ['自动终止/释放', 'bool'],
            'terminate_days' => ['逾期多少天终止/释放', 'int'],
            'dns_mode' => ['子域名暂停方式（pause 暂停保留 / remove 删除记录）', 'mode'],
        ];
    }

    /** 合并覆盖：$json 中值为 '' 或不存在则继承 */
    public static function policy($json = '')
    {
        $p = self::defaults();
        $o = is_array($json) ? $json : json_arr($json);
        foreach (self::overrideFields() as $k => $f) {
            if (!isset($o[$k]) || $o[$k] === '' || $o[$k] === null) {
                continue;
            }
            switch ($f[1]) {
                case 'bool':
                    $p[$k] = (string)$o[$k] === '1' || $o[$k] === true;
                    break;
                case 'days':
                    $p[$k] = self::days($o[$k]);
                    break;
                case 'int':
                    $p[$k] = max(0, (int)$o[$k]);
                    break;
                case 'mode':
                    $p[$k] = $o[$k] === 'remove' ? 'remove' : 'pause';
                    break;
            }
        }

        return $p;
    }

    /** 站内通知 */
    public static function siteNotice($userId, $title, $content)
    {
        Db::name('user_notice')->insert(['user_id' => $userId, 'title' => mb_substr($title, 0, 190), 'content' => mb_substr($content, 0, 2000), 'is_read' => 0, 'create_time' => time()]);
    }

    /** 需要发送的提醒阶段：返回天数或 0 */
    protected static function stageDue(array $days, $due, $lastRemind, $now)
    {
        foreach (array_reverse($days) as $d) { // 从小到大：取已到达的最小阶段
            $mark = $due - $d * 86400;
            if ($now >= $mark && $now < $due) {
                return (int)$lastRemind < $mark ? $d : 0;
            }
        }

        return 0;
    }

    /* ======================= 云主机 / NAT ======================= */

    public static function services()
    {
        $now = time();
        $stat = ['remind' => 0, 'suspend' => 0, 'terminate' => 0, 'error' => 0];
        $policies = [];
        $pol = function ($pid) use (&$policies) {
            if (!isset($policies[$pid])) {
                $policies[$pid] = self::policy((string)Db::name('product')->where('id', $pid)->value('lifecycle'));
            }

            return $policies[$pid];
        };
        // 提醒（最多看 366 天内到期的 200 条）
        foreach (Db::name('service')->where('status', 'active')->where('due_time', '>', $now)->where('due_time', '<', $now + 366 * 86400)->order('due_time asc')->limit(200)->select() as $s) {
            $p = $pol($s['product_id']);
            if (!$p['remind_enable'] || !$p['remind_days'] || (int)$s['due_time'] > $now + max($p['remind_days']) * 86400) {
                continue;
            }
            $d = self::stageDue($p['remind_days'], (int)$s['due_time'], (int)$s['remind_time'], $now);
            if (!$d) {
                continue;
            }
            $u = Db::name('user')->find($s['user_id']);
            $name = $s['hostname'] ?: ('#' . $s['id']);
            if ($u) {
                Notify::user('service_due', $u, ['hostname' => $name, 'ip' => $s['ip'], 'due_date' => date('Y-m-d H:i', $s['due_time'])]);
                if ($p['remind_site']) {
                    self::siteNotice($u['id'], '云主机即将到期', '您的云主机 ' . $name . ' 将于 ' . date('Y-m-d H:i', $s['due_time']) . ' 到期（剩余约 ' . $d . ' 天），请及时续费，逾期将自动暂停。');
                }
            }
            Db::name('service')->where('id', $s['id'])->update(['remind_time' => $now]);
            audit('lifecycle.remind', '服务#' . $s['id'], ['stage' => $d], 'info', 'system', 0, 'cron');
            $stat['remind']++;
        }
        // 逾期暂停
        foreach (Db::name('service')->where('status', 'active')->where('due_time', '>', 0)->where('due_time', '<', $now)->order('due_time asc')->limit(20)->select() as $s) {
            $p = $pol($s['product_id']);
            if (!$p['suspend_enable'] || (int)$s['due_time'] + $p['suspend_days'] * 86400 > $now) {
                continue;
            }
            try {
                if ($s['remote_id'] !== '') {
                    ProvisionService::action($s['id'], 'suspend', ['reason' => 'overdue'], true);
                } else {
                    Db::name('service')->where('id', $s['id'])->where('status', 'active')->update(['status' => 'suspended', 'suspend_reason' => 'overdue', 'update_time' => $now]);
                }
                $u = Db::name('user')->find($s['user_id']);
                if ($u) {
                    Notify::user('service_suspend', $u, ['hostname' => $s['hostname'] ?: ('#' . $s['id']), 'reason' => '逾期未续费']);
                    self::siteNotice($u['id'], '云主机已暂停', '云主机 ' . ($s['hostname'] ?: ('#' . $s['id'])) . ' 已逾期未续费，已自动暂停。' . ($p['terminate_enable'] ? '请在 ' . date('Y-m-d', (int)$s['due_time'] + $p['terminate_days'] * 86400) . ' 前续费，续费后自动恢复，否则将被删除。' : '续费后自动恢复。'));
                }
                audit('lifecycle.suspend', '服务#' . $s['id'], ['due' => date('Y-m-d H:i', $s['due_time'])], 'warn', 'system', 0, 'cron');
                $stat['suspend']++;
            } catch (\Exception $e) {
                Db::name('service')->where('id', $s['id'])->update(['last_error' => mb_substr('自动暂停失败：' . $e->getMessage(), 0, 490)]);
                $stat['error']++;
            }
        }
        // 宽限期后终止（只处理因逾期暂停的）
        foreach (Db::name('service')->where('status', 'suspended')->where('suspend_reason', 'overdue')->where('due_time', '>', 0)->where('due_time', '<', $now - 86400)->order('due_time asc')->limit(10)->select() as $s) {
            $p = $pol($s['product_id']);
            if (!$p['terminate_enable'] || (int)$s['due_time'] + $p['terminate_days'] * 86400 > $now) {
                continue;
            }
            try {
                if ($s['remote_id'] !== '') {
                    ProvisionService::action($s['id'], 'terminate', [], true);
                } else {
                    Db::name('service')->where('id', $s['id'])->update(['status' => 'terminated', 'update_time' => $now]);
                }
                $u = Db::name('user')->find($s['user_id']);
                if ($u) {
                    self::siteNotice($u['id'], '云主机已删除', '云主机 ' . ($s['hostname'] ?: ('#' . $s['id'])) . ' 逾期 ' . $p['terminate_days'] . ' 天未续费，已自动终止。');
                }
                audit('lifecycle.terminate', '服务#' . $s['id'], ['due' => date('Y-m-d H:i', $s['due_time'])], 'danger', 'system', 0, 'cron');
                $stat['terminate']++;
            } catch (\Exception $e) {
                Db::name('service')->where('id', $s['id'])->update(['last_error' => mb_substr('自动终止失败：' . $e->getMessage(), 0, 490)]);
                $stat['error']++;
            }
        }

        return $stat;
    }

    /* ======================= 子域名 ======================= */

    public static function subdomains()
    {
        $now = time();
        $stat = ['remind' => 0, 'suspend' => 0, 'release' => 0, 'error' => 0];
        $policies = [];
        $pol = function ($did) use (&$policies) {
            if (!isset($policies[$did])) {
                $d = Db::name('dns_domain')->field('lifecycle,renew_before_days')->find($did);
                $o = $d ? json_arr($d['lifecycle']) : [];
                if ($d && (!isset($o['remind_days']) || $o['remind_days'] === '') && (int)$d['renew_before_days'] > 0 && setting('lc_remind_days', '') === '') {
                    $o['remind_days'] = (string)$d['renew_before_days'];
                }
                $policies[$did] = self::policy($o);
            }

            return $policies[$did];
        };
        foreach (Db::name('dns_subdomain')->where('status', 'active')->where('expire_time', '>', $now)->where('expire_time', '<', $now + 366 * 86400)->order('expire_time asc')->limit(200)->select() as $s) {
            $p = $pol($s['domain_id']);
            if (!$p['remind_enable'] || !$p['remind_days'] || (int)$s['expire_time'] > $now + max($p['remind_days']) * 86400) {
                continue;
            }
            $d = self::stageDue($p['remind_days'], (int)$s['expire_time'], (int)$s['remind_time'], $now);
            if (!$d) {
                continue;
            }
            $u = Db::name('user')->find($s['user_id']);
            if ($u) {
                Notify::user('dns_expire', $u, ['fqdn' => $s['fqdn'], 'expire_date' => date('Y-m-d H:i', $s['expire_time'])]);
                if ($p['remind_site']) {
                    self::siteNotice($u['id'], '子域名即将到期', '子域名 ' . $s['fqdn'] . ' 将于 ' . date('Y-m-d H:i', $s['expire_time']) . ' 到期（剩余约 ' . $d . ' 天），请及时续期。');
                }
            }
            Db::name('dns_subdomain')->where('id', $s['id'])->update(['remind_time' => $now]);
            audit('lifecycle.remind', $s['fqdn'], ['stage' => $d], 'info', 'system', 0, 'cron');
            $stat['remind']++;
        }
        // 到期暂停：pause=从服务商撤下本系统创建的记录，本地保留以便续期恢复；remove=删除本系统创建的记录
        foreach (Db::name('dns_subdomain')->where('status', 'active')->where('expire_time', '>', 0)->where('expire_time', '<', $now)->order('expire_time asc')->limit(20)->select() as $s) {
            $p = $pol($s['domain_id']);
            if (!$p['suspend_enable'] || (int)$s['expire_time'] + $p['suspend_days'] * 86400 > $now) {
                continue;
            }
            try {
                if ($p['dns_mode'] === 'remove') {
                    foreach (Db::name('dns_record')->where('sub_id', $s['id'])->select() as $r) {
                        DnsService::deleteRecord([], $r['id'], true, true);
                    }
                }
                DnsService::suspendSub($s['id'], '已到期');
                Db::name('dns_subdomain')->where('id', $s['id'])->update(['status' => 'expired']);
                $u = Db::name('user')->find($s['user_id']);
                if ($u) {
                    self::siteNotice($u['id'], '子域名已到期暂停', '子域名 ' . $s['fqdn'] . ' 已到期，解析已' . ($p['dns_mode'] === 'remove' ? '删除' : '暂停') . '。' . ($p['terminate_enable'] ? '请在 ' . date('Y-m-d', (int)$s['expire_time'] + $p['terminate_days'] * 86400) . ' 前续期，否则将被回收。' : ''));
                }
                audit('lifecycle.suspend', $s['fqdn'], ['mode' => $p['dns_mode']], 'warn', 'system', 0, 'cron');
                $stat['suspend']++;
            } catch (\Exception $e) {
                $stat['error']++;
            }
        }
        foreach (Db::name('dns_subdomain')->where('status', 'expired')->where('expire_time', '>', 0)->where('expire_time', '<', $now - 86400)->order('expire_time asc')->limit(20)->select() as $s) {
            $p = $pol($s['domain_id']);
            if (!$p['terminate_enable'] || (int)$s['expire_time'] + $p['terminate_days'] * 86400 > $now) {
                continue;
            }
            DnsService::releaseSub($s['id'], 'expired');
            $u = Db::name('user')->find($s['user_id']);
            if ($u) {
                self::siteNotice($u['id'], '子域名已回收', '子域名 ' . $s['fqdn'] . ' 逾期未续期，已回收。');
            }
            audit('lifecycle.release', $s['fqdn'], '', 'danger', 'system', 0, 'cron');
            $stat['release']++;
        }

        return $stat;
    }

    /* ======================= DNS 套餐 ======================= */

    public static function packages()
    {
        $now = time();
        $n = 0;
        foreach (Db::name('user_package')->where('expire_time', '>', $now)->where('expire_time', '<', $now + 366 * 86400)->order('expire_time asc')->limit(100)->select() as $up) {
            $p = self::policy((string)Db::name('dns_package')->where('id', $up['package_id'])->value('lifecycle'));
            if (!$p['remind_enable'] || !$p['remind_days']) {
                continue;
            }
            $d = self::stageDue($p['remind_days'], (int)$up['expire_time'], isset($up['remind_time']) ? (int)$up['remind_time'] : 0, $now);
            if (!$d) {
                continue;
            }
            self::siteNotice($up['user_id'], 'DNS 套餐即将到期', '您的 DNS 套餐「' . $up['name'] . '」将于 ' . date('Y-m-d', $up['expire_time']) . ' 到期，到期后额度恢复为会员默认额度。');
            Db::name('user_package')->where('id', $up['id'])->update(['remind_time' => $now]);
            $n++;
        }

        return $n;
    }

    /* ======================= 未付款订单 ======================= */

    public static function unpaidOrders()
    {
        $p = self::defaults();
        if (!$p['unpaid_enable']) {
            return 'skip';
        }
        $n = 0;
        foreach (Db::name('order')->where('status', 'unpaid')->where('create_time', '<', time() - $p['unpaid_hours'] * 3600)->limit(100)->select() as $o) {
            // 有待确认的线下付款时不自动取消
            if (Db::name('payment')->where('order_id', $o['id'])->where('status', 0)->where('gateway', 'manual')->where('remark', '<>', '')->count()) {
                continue;
            }
            $aff = Db::name('order')->where('id', $o['id'])->where('status', 'unpaid')->update(['status' => 'cancelled', 'update_time' => time(), 'admin_remark' => mb_substr(trim($o['admin_remark'] . ' [超时未支付自动取消]'), 0, 490)]);
            if ($aff) {
                Db::name('payment')->where('order_id', $o['id'])->where('status', 0)->update(['status' => 2]);
                OrderService::onReleased($o, '超时未支付');
                audit('lifecycle.order_cancel', '订单#' . $o['id'] . ' ' . $o['order_no'], ['hours' => $p['unpaid_hours']], 'info', 'system', 0, 'cron');
                $n++;
            }
        }

        return $n;
    }
}
