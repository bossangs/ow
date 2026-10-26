<?php

namespace app\admin\controller;

use app\common\service\DnsService;
use think\Db;

class Subdomain extends OpsBase
{
    protected $table = 'dns_subdomain';
    protected $title = '子域名';
    protected $searchFields = array('user_id', 'domain_id', 'fqdn', 'status', 'prefix');
    protected function fields()
    {
        return array('user_id' => array('用户ID', 'type' => 'number', 'list' => true), 'domain_id' => array('根域名ID', 'type' => 'number', 'list' => true), 'prefix' => array('前缀', 'type' => 'text', 'list' => true), 'fqdn' => array('完整域名', 'type' => 'text', 'list' => true), 'status' => array('状态', 'type' => 'text', 'list' => true, 'badge' => 'subdomain'), 'expire_time' => array('到期时间', 'type' => 'number', 'list' => true), 'reject_reason' => array('驳回原因', 'type' => 'text', 'list' => true));
    }
    public function action()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $act = (string)$this->request->post('action');
        $reason = (string)$this->request->post('reason');

        return $this->perform(function () use ($id, $act, $reason) {
            if ($act === 'suspend') {
                DnsService::suspendSub($id, $reason);
            } elseif ($act === 'resume') {
                DnsService::resumeSub($id);
            } elseif ($act === 'release') {
                DnsService::releaseSub($id, $reason ?: 'admin');
            } elseif (in_array($act, array('approve', 'reject'))) {
                Db::name('dns_subdomain')->where('id', $id)->update(array('status' => $act === 'approve' ? 'active' : 'rejected', 'reject_reason' => $reason));
            } else {
                throw new \Exception('不支持的操作');
            }

            return '子域名操作完成';
        });
    }
}
