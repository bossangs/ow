<?php

namespace app\admin\controller;

use app\common\service\ProvisionService;
use think\Db;

class Service extends OpsBase
{
    protected $table = 'service';
    protected $title = '云主机服务';
    protected $searchFields = array('user_id', 'product_id', 'hostname', 'remote_id', 'status', 'ip', 'last_error');
    protected function fields()
    {
        return array('user_id' => array('用户ID', 'type' => 'number', 'list' => true), 'product_id' => array('商品ID', 'type' => 'number', 'list' => true), 'hostname' => array('主机名', 'type' => 'text', 'list' => true), 'billing_cycle' => array('周期', 'type' => 'text', 'list' => true), 'price' => array('价格', 'type' => 'number', 'list' => true), 'status' => array('状态', 'type' => 'text', 'list' => true, 'badge' => 'service'), 'ip' => array('IP', 'type' => 'text', 'list' => true), 'due_time' => array('到期时间', 'type' => 'number', 'list' => true), 'last_error' => array('最后错误', 'type' => 'text', 'list' => true), 'password' => array('密码', 'type' => 'password', 'list' => false));
    }
    public function action()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $act = (string)$this->request->post('action');
        $params = $this->request->post();
        unset($params['id'],$params['action']);

        return $this->perform(function () use ($id, $act, $params) {
            ProvisionService::action($id, $act, $params, true);

            return '服务操作已完成';
        });
    }
    public function sync()
    {
        $id = (int)$this->request->param('id');

        return $this->perform(function () use ($id) {
            ProvisionService::sync($id);

            return '同步完成';
        });
    }
    public function provision()
    {
        $id = (int)$this->request->param('id');

        return $this->perform(function () use ($id) {
            ProvisionService::provision($id);

            return '已提交重试开通';
        });
    }
}
