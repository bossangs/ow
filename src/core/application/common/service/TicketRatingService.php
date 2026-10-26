<?php

namespace app\common\service;

use app\common\lib\Notify;
use think\Db;

/** 工单满意度评分：评分归一化为 1-5，兼容星级和三档模式。 */
class TicketRatingService
{
    public static function mode()
    {
        $m = (string)setting('ticket_rating_scale', 'stars');

        return $m === 'simple' ? 'simple' : 'stars';
    }

    public static function score($value)
    {
        $v = is_numeric($value) ? (int)$value : 0;
        if (self::mode() === 'simple') {
            $map = array('好评' => 5, '中评' => 3, '差评' => 1, 'good' => 5, 'medium' => 3, 'bad' => 1);
            if (isset($map[(string)$value])) {
                return $map[(string)$value];
            }
        }

        return $v >= 1 && $v <= 5 ? $v : 0;
    }

    public static function labels()
    {
        return self::mode() === 'simple' ? array(5 => '好评', 3 => '中评', 1 => '差评') : array(1 => '1 星', 2 => '2 星', 3 => '3 星', 4 => '4 星', 5 => '5 星');
    }

    protected static function ticket($id)
    {
        $t = Db::name('ticket')->find((int)$id);
        if (!$t) {
            throw new \Exception('工单不存在');
        }

        return $t;
    }

    public static function rate($ticketId, $userId, $value, $comment = '')
    {
        $t = self::ticket($ticketId);
        if ((int)$t['user_id'] !== (int)$userId) {
            throw new \Exception('只有工单发起人可以评价');
        }
        if (!in_array((string)$t['status'], array('resolved', 'closed'), true)) {
            throw new \Exception('工单解决或关闭后才能评价');
        }
        $now = time();
        $old = Db::name('ticket_rating')->where('ticket_id', (int)$ticketId)->find();
        $window = max(0, (int)setting('ticket_rating_edit_days', '3')) * 86400;
        if ($old && $window > 0 && (int)$old['create_time'] + $window < $now) {
            throw new \Exception('评价修改期限已过');
        }
        $score = self::score($value);
        if (!$score) {
            throw new \Exception('评价内容无效');
        }
        $data = array('ticket_id' => (int)$ticketId, 'user_id' => (int)$userId, 'score' => $score, 'comment' => mb_substr(trim((string)$comment), 0, 1000), 'party' => !empty($t['seller_user_id']) ? 'seller' : 'user', 'update_time' => $now);
        if ($old) {
            Db::name('ticket_rating')->where('ticket_id', (int)$ticketId)->update($data);
        } else {
            $data['create_time'] = $now;
            Db::name('ticket_rating')->insert($data);
        }
        if ($score <= 2) {
            Notify::admin('ticket_bad_rating', array('_key' => 'rating:' . (int)$ticketId, 'ticket_id' => $ticketId, 'subject' => isset($t['subject']) ? $t['subject'] : '', 'score' => $score, 'comment' => $data['comment'], 'username' => ''));
        }

        return $score;
    }

    public static function get($ticketId)
    {
        try {
            return Db::name('ticket_rating')->where('ticket_id', (int)$ticketId)->find();
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function report($from = 0, $to = 0, $department = '')
    {
        $q = Db::name('ticket_rating')->alias('r')->join('ticket t', 't.id=r.ticket_id')->field('r.*,t.subject,t.department,t.status,t.user_id,t.seller_user_id,t.last_admin_id');
        if ($from) {
            $q->where('r.create_time', '>=', (int)$from);
        }
        if ($to) {
            $q->where('r.create_time', '<=', (int)$to);
        }
        if ($department !== '') {
            $q->where('t.department', $department);
        }
        $rows = $q->order('r.create_time desc')->select();
        $summary = array('count' => 0, 'sum' => 0, 'avg' => 0, 'levels' => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0));
        $staff = array();
        $seller = array('count' => 0, 'sum' => 0, 'avg' => 0, 'levels' => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0));
        $departments = array();
        foreach ((array)$rows as $r) {
            $s = (int)$r['score'];
            $summary['count']++;
            $summary['sum'] += $s;
            $summary['levels'][$s] = isset($summary['levels'][$s]) ? $summary['levels'][$s] + 1 : 1;
            $d = (string)$r['department'];
            if (!isset($departments[$d])) {
                $departments[$d] = array('count' => 0, 'sum' => 0, 'levels' => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0));
            } $departments[$d]['count']++;
            $departments[$d]['sum'] += $s;
            $departments[$d]['levels'][$s]++;
            if (!empty($r['seller_user_id']) || (isset($r['party']) && $r['party'] === 'seller')) {
                $seller['count']++;
                $seller['sum'] += $s;
                $seller['levels'][$s]++;
            }
            $aid = isset($r['last_admin_id']) ? (int)$r['last_admin_id'] : 0;
            if (!isset($staff[$aid])) {
                $staff[$aid] = array('admin_id' => $aid, 'count' => 0, 'sum' => 0, 'levels' => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0));
            } $staff[$aid]['count']++;
            $staff[$aid]['sum'] += $s;
            $staff[$aid]['levels'][$s]++;
        }
        $summary['avg'] = $summary['count'] ? round($summary['sum'] / $summary['count'], 2) : 0;
        $seller['avg'] = $seller['count'] ? round($seller['sum'] / $seller['count'], 2) : 0;
        foreach ($staff as &$x) {
            $x['avg'] = $x['count'] ? round($x['sum'] / $x['count'], 2) : 0;
        } foreach ($departments as &$x) {
            $x['avg'] = $x['count'] ? round($x['sum'] / $x['count'], 2) : 0;
        }

        return array('rows' => $rows, 'summary' => $summary, 'staff' => $staff, 'departments' => $departments, 'seller' => $seller);
    }
}
