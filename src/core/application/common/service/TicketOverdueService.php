<?php

namespace app\common\service;

use app\common\lib\Notify;
use think\Db;

class TicketOverdueService
{
    protected static function hours($ticket)
    {
        $hours = max(1, (int)setting('ticket_overdue_hours', '6'));
        $priority = json_decode((string)setting('ticket_overdue_priority_hours', '{}'), true);
        $department = json_decode((string)setting('ticket_overdue_department_hours', '{}'), true);
        if (is_array($priority) && isset($priority[$ticket['priority']])) {
            $hours = max(1, (int)$priority[$ticket['priority']]);
        }
        if (is_array($department) && isset($department[$ticket['department']])) {
            $hours = max(1, (int)$department[$ticket['department']]);
        }

        return $hours;
    }

    public static function run($limit = 50)
    {
        $now = time();
        $sent = 0;
        try {
            $rows = Db::name('ticket')->where('status', 'in', array('open', 'processing'))->where('waiting_since', '>', 0)->order('waiting_since asc')->limit((int)$limit)->select();
        } catch (\Exception $e) {
            return 'skip';
        }
        foreach ((array)$rows as $ticket) {
            $since = (int)$ticket['waiting_since'];
            if ($now < $since + self::hours($ticket) * 3600) {
                continue;
            }
            $last = (int)$ticket['reminded_at'];
            $repeat = max(0, (int)setting('ticket_overdue_repeat_hours', '0')) * 3600;
            if ($last > 0 && (!$repeat || $now < $last + $repeat)) {
                continue;
            }
            $changed = Db::name('ticket')->where('id', (int)$ticket['id'])->where('reminded_at', $last)->update(array('reminded_at' => $now, 'remind_count' => (int)$ticket['remind_count'] + 1));
            if (!$changed) {
                continue;
            }
            $minutes = (int)floor(($now - $since) / 60);
            $vars = array('_key' => 'overdue:' . (int)$ticket['id'] . ':' . $now, 'ticket_id' => $ticket['id'], 'subject' => $ticket['subject'], 'department' => $ticket['department'], 'priority' => $ticket['priority'], 'wait_hours' => floor($minutes / 60), 'wait_minutes' => $minutes % 60, 'admin_url' => url_to('admin/ticket/detail', array('id' => $ticket['id']), true));
            $sellerId = (int)$ticket['seller_user_id'];
            $sellerEscalate = max(0, (int)setting('ticket_seller_escalate_hours', '6')) * 3600;
            if ($sellerId > 0 && ($last === 0 || ($sellerEscalate > 0 && $now >= $since + self::hours($ticket) * 3600 + $sellerEscalate))) {
                $seller = Db::name('user')->find($sellerId);
                if ($seller && $last === 0) {
                    Notify::seller('ticket_seller_overdue', $seller, $vars);
                } else {
                    Notify::admin('ticket_seller_overdue', $vars);
                }
            } else {
                Notify::admin('ticket_overdue', $vars);
            }
            audit('ticket.overdue_reminder', '工单#' . (int)$ticket['id'], $vars, 'warn', 'system', 0, 'cron');
            $sent++;
        }

        return $sent;
    }
}
