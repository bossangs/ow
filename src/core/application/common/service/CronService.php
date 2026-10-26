<?php

namespace app\common\service;

use think\Db;
use app\common\lib\Notify;

/**
 * 计划任务：建议每 5 分钟执行一次
 *  - 宝塔/Linux: php /网站根目录/cron.php
 *  - 无命令行的虚拟主机: 主机面板“定时访问 URL”或 cron-job.org 等外部监控访问
 *    https://域名/index.php?s=/api/cron/run&key=后台设置的密钥（每次限时，超时任务下次继续）
 *  - 都没有时可在后台开启“访问触发”（默认关闭）
 */
class CronService
{
    /** 任务表：名称 => [方法, 说明] */
    public static function tasks()
    {
        return [
            'notify_queue' => ['notifyQueue', '发送通知队列（邮件/Telegram）'],
            'ticket_overdue' => ['ticketOverdue', '工单超时未处理催单'],
            'ticket_autoclose' => ['ticketAutoclose', '工单自动关闭与提前提醒'],
            'cancel_unpaid' => ['cancelUnpaid', '超时未付订单自动取消并释放库存/优惠码'],
            'retry_provision' => ['retryProvision', '重试开通失败的云主机'],
            'stock_check' => ['stockCheck', '库存检查与提醒'],
            'service_due' => ['serviceDue', '云主机到期提醒/暂停/终止'],
            'sub_expire' => ['subExpire', '子域名/套餐到期处理'],
            'cancel_requests' => ['cancelRequests', '执行到期取消申请'],
            'sync_services' => ['syncServices', '同步上游状态'],
            'market_health' => ['marketHealth', '市场域名凭据健康检查'],
            'nat_nodes' => ['natNodes', 'NAT 节点在线检查'],
            'cleanup' => ['cleanup', '清理过期数据'],
        ];
    }

    /**
     * 执行计划任务
     * 每次调用有时间预算（默认 25 秒，适合虚拟主机的 URL 定时任务），超时的任务留到下次继续；
     * 通知队列每次都优先执行。
     * @param int $budget 秒；0 = 用设置 cron_time_budget
     * @param array|null $only 只执行指定任务
     */
    public static function run($budget = 0, $only = null)
    {
        $budget = $budget > 0 ? $budget : max(5, (int)setting('cron_time_budget', '25'));
        $start = microtime(true);
        $log = [];
        if ((int)setting('schema_version', '1') < 4 && is_file(CORE_PATH . 'install/upgrade.php')) {
            require_once CORE_PATH . 'install/upgrade.php';
            $log['schema_upgrade'] = idc_schema_upgrade();
        }
        $tasks = self::tasks();
        $names = array_keys($tasks);
        // 轮转：从上次中断的任务开始，保证每个任务最终都会被执行
        $next = (string)setting('cron_next_task', '');
        $pos = array_search($next, $names, true);
        if ($pos !== false && $pos > 0) {
            $names = array_merge(['notify_queue'], array_slice($names, $pos), array_slice($names, 1, $pos - 1));
            $names = array_values(array_unique($names));
        }
        if ($only) {
            $names = array_values(array_intersect($names, (array)$only));
        }
        $stoppedAt = '';
        foreach ($names as $name) {
            if (microtime(true) - $start > $budget && $name !== 'notify_queue') {
                $stoppedAt = $name;
                $log[$name] = 'deferred';
                break;
            }
            $t0 = microtime(true);
            try {
                $method = $tasks[$name][0];
                $log[$name] = self::$method();
            } catch (\Exception $e) {
                $log[$name] = 'error: ' . mb_substr($e->getMessage(), 0, 200);
            }
            $log[$name . '_ms'] = (int)round((microtime(true) - $t0) * 1000);
        }
        setting_save([
            'cron_last_run' => (string)time(),
            'cron_next_task' => $stoppedAt,
            'cron_last_result' => json_encode($log, JSON_UNESCAPED_UNICODE),
        ]);

        return $log;
    }

    /** 通知队列（受剩余时间限制，单次最多 cron_notify_batch 条） */
    public static function notifyQueue()
    {
        list($sent, $failed, $retry) = Notify::processQueue((int)setting('cron_notify_batch', '30'));

        return ['sent' => $sent, 'failed' => $failed, 'retry' => $retry];
    }

    public static function ticketOverdue()
    {
        return TicketOverdueService::run(50);
    }

    public static function ticketAutoclose()
    {
        $days = max(1, (int) setting('ticket_auto_close_days', '7'));
        $warn = max(0, (int) setting('ticket_autoclose_warn_days', '1'));
        $now = time();
        $n = 0;
        $rows = Db::name('ticket')->where('status', 'in', ['waiting_user', 'resolved'])->where('update_time', '<', $now - $days * 86400)->limit(50)->select();
        foreach ($rows as $t) {
            Db::name('ticket')->where('id', $t['id'])->update(['status' => 'closed', 'update_time' => $now, 'waiting_since' => 0]);
            TicketService::entry($t['id'], 0, 0, 'system', '系统已自动关闭此工单。', false, true);
            $u = Db::name('user')->find($t['user_id']);
            if ($u) {
                Notify::user('ticket_status', $u, ['_key' => 'autoclose:' . $t['id'] . ':' . date('Ymd'), 'ticket_id' => $t['id'], 'subject' => $t['subject'], 'status' => 'closed']);
            }
            $n++;
        }
        if ($warn > 0) {
            $cut = $now - max(0, $days - $warn) * 86400;
            foreach (Db::name('ticket')->where('status', 'in', ['waiting_user', 'resolved'])->where('update_time', '<=', $cut)->where('update_time', '>', $cut - 86400)->limit(50)->select() as $t) {
                $u = Db::name('user')->find($t['user_id']);
                if ($u) {
                    Notify::user('ticket_autoclose_warn', $u, ['_key' => 'autowarn:' . $t['id'] . ':' . date('Ymd'), 'ticket_id' => $t['id'], 'subject' => $t['subject']]);
                }
            }
        }

        return $n;
    }

    /** 库存提醒 */
    public static function stockCheck()
    {
        return InventoryService::checkAll();
    }

    /**
     * 访问触发（可选，默认关闭）：没有任何定时任务条件时，由前台访问在响应发送后顺带执行少量任务。
     * 加锁、限时 5 秒、最短间隔 cron_visit_interval（默认 300 秒）。
     */
    public static function visitTrigger()
    {
        if (setting('cron_visit_trigger', '0') !== '1') {
            return;
        }
        $interval = max(60, (int)setting('cron_visit_interval', '300'));
        if ((int)setting('cron_last_run', '0') > time() - $interval) {
            return;
        }
        $dir = DATA_PATH . 'runtime/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $fp = @fopen($dir . 'cron.lock', 'c');
        if (!$fp || !@flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                @fclose($fp);
            }

            return;
        }
        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ignore_user_abort(true);
            self::run(5);
        } catch (\Exception $e) {
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /** 重试开通失败/待开通（最多 20 个，失败 24 小时内） */
    public static function retryProvision()
    {
        if (setting('cron_retry_provision', '1') !== '1') {
            return 'skip';
        }
        $n = 0;
        $rows = Db::name('service')->where('status', 'in', ['pending', 'failed'])->where('remote_id', '')->where('create_time', '>', time() - 86400)->limit(20)->select();
        foreach ($rows as $s) {
            $p = Db::name('product')->find($s['product_id']);
            if ($p && $p['auto_setup'] && $p['upstream_id']) {
                ProvisionService::provision($s['id']);
                $n++;
            }
        }
        // 已有 remote_id 但还没有 IP 的，同步一次
        foreach (Db::name('service')->where('status', 'in', ['active', 'pending'])->where('remote_id', '<>', '')->where('ip', '')->limit(20)->select() as $s) {
            ProvisionService::sync($s['id'], true);
        }

        return $n;
    }

    public static function syncServices()
    {
        $interval = (int)setting('cron_sync_interval', '3600');
        if ($interval <= 0) {
            return 'skip';
        }
        $rows = Db::name('service')->where('status', 'in', ['active', 'suspended'])->where('remote_id', '<>', '')->where('last_sync_time', '<', time() - $interval)->limit(30)->select();
        foreach ($rows as $s) {
            ProvisionService::sync($s['id'], true);
        }

        return count($rows);
    }

    /** 云主机/NAT 到期提醒、逾期暂停、宽限期终止（策略见 LifecycleService） */
    public static function serviceDue()
    {
        return LifecycleService::services();
    }

    /** 子域名到期提醒、到期暂停、宽限期回收；DNS 套餐到期提醒 */
    public static function subExpire()
    {
        return ['subs' => LifecycleService::subdomains(), 'packages' => LifecycleService::packages()];
    }

    /** 超时未支付订单自动取消 */
    public static function cancelUnpaid()
    {
        return LifecycleService::unpaidOrders();
    }

    /** 用户域名市场凭据巡检，限制批量避免拖慢其他任务。 */
    public static function marketHealth()
    {
        if (setting('market_enable', '1') !== '1') {
            return 'skip';
        }

        return MarketService::healthCheck(20);
    }

    /** NAT 节点心跳超时标记离线。 */
    public static function natNodes()
    {
        $timeout = max(120, (int)setting('nat_node_timeout', '180'));
        $cut = time() - $timeout;
        $rows = Db::name('nat_node')->where('status', 1)->where('last_heartbeat', '>', 0)->where('last_heartbeat', '<', $cut)->limit(50)->select();
        foreach ($rows as $n) {
            Db::name('nat_node')->where('id', $n['id'])->update(['status' => 2]);
            audit('nat.node.offline', $n['name'], ['last_heartbeat' => $n['last_heartbeat']], 'warn', 'system', 0, 'cron');
        }

        return count($rows);
    }

    public static function cleanup()
    {
        $days = (int)setting('audit_keep_days', '180');
        $n = 0;
        if ($days > 0) {
            $n = Db::name('audit_log')->where('create_time', '<', time() - $days * 86400)->limit(5000)->delete();
        }
        Db::name('verify_code')->where('expire_time', '<', time() - 86400)->delete();
        Db::name('notify_log')->where('create_time', '<', time() - 90 * 86400)->delete();

        return $n;
    }

    /** 执行已批准且到期的取消申请 */
    public static function cancelRequests()
    {
        return OrderAdminService::cronDueRequests(20);
    }
}
