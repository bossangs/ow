<?php

namespace app\admin\controller;

use app\common\service\OrderService;
use think\Db;

class Payment extends OpsBase
{
    protected $table = 'payment';
    protected $title = '支付流水';
    protected $searchFields = array('trade_no', 'user_id', 'gateway', 'purpose', 'order_id', 'status', 'remark');
    protected function fields()
    {
        return array('trade_no' => array('交易号', 'type' => 'text', 'list' => true, 'readonly' => true), 'user_id' => array('用户ID', 'type' => 'number', 'list' => true), 'amount' => array('金额', 'type' => 'number', 'list' => true), 'gateway' => array('网关', 'type' => 'text', 'list' => true), 'purpose' => array('用途', 'type' => 'text', 'list' => true), 'order_id' => array('订单ID', 'type' => 'number', 'list' => true), 'status' => array('状态', 'type' => 'number', 'list' => true), 'paid_time' => array('支付时间', 'type' => 'number', 'list' => true), 'remark' => array('备注', 'type' => 'text', 'list' => true));
    }
    public function confirm()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');

        return $this->perform(function () use ($id) {
            $p = $this->row('payment', $id);
            OrderService::confirmPayment($p['trade_no'], $p['amount'], 'admin-manual', '后台人工确认', $this->admin['username']);

            return '已确认到账';
        });
    }
    public function close()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');

        return $this->perform(function () use ($id) {
            Db::name('payment')->where('id', $id)->where('status', 0)->update(array('status' => 2, 'remark' => '后台关闭'));
            audit('payment.close', '支付#' . $id, '', 'warn');

            return '支付流水已关闭';
        });
    }
}
