<?php

namespace app\admin\controller;

use think\Db;

class Dashboard extends OpsBase
{
    public function index()
    {
        $today = strtotime(date('Y-m-d'));
        $month = strtotime(date('Y-m-01'));
        $stats = array('today_income' => Db::name('order')->where('status', 'paid')->where('paid_time', '>=', $today)->sum('pay_amount'), 'month_income' => Db::name('order')->where('status', 'paid')->where('paid_time', '>=', $month)->sum('pay_amount'), 'orders' => Db::name('order')->where('create_time', '>=', $today)->count(), 'unpaid' => Db::name('order')->where('status', 'unpaid')->count(), 'failed' => Db::name('service')->where('status', 'failed')->count(), 'cancel' => Db::name('cancel_request')->where('status', 'pending')->count(), 'tickets' => Db::name('ticket')->where('status', 'in', array('open', 'replied'))->count());

        return $this->fetch('dashboard', array('stats' => $stats, 'recent' => Db::name('order')->order('id desc')->limit(10)->select()));
    }
}
