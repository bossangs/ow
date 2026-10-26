<?php

namespace app\admin\controller;

use app\common\service\CronService;
use think\Db;

class Operations extends OpsBase
{
    public function index()
    {
        return $this->fetch('operations', array('cronUrl' => url_to('api/cron/run', array(), true), 'last' => Db::name('audit_log')->where('action', 'like', '%cron%')->order('id desc')->find()));
    } public function run()
    {
        $this->requirePost();

        return $this->perform(function () {
            CronService::run();

            return '计划任务已执行';
        });
    }
}
