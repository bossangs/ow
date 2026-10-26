<?php

namespace app\admin\controller;

use app\common\service\OrderAdminService;
use think\Db;

class Order extends OpsBase
{
    protected $title = '订单管理';
    public function index()
    {
        $q = Db::name('order')->alias('o')->join('user u', 'u.id=o.user_id', 'LEFT')->field('o.*,u.username,u.email');
        foreach (array('type', 'status', 'user_id') as $key) {
            $value = trim((string)$this->request->get($key, ''));
            if ($value !== '') {
                $q->where('o.' . $key, $value);
            }
        }
        $kw = trim((string)$this->request->get('kw', ''));
        if ($kw !== '') {
            $q->where('o.order_no|o.title|u.username|u.email', 'like', '%' . $kw . '%');
        }
        $q->order('o.id desc');
        list($list, $total, $page, $size) = $this->paged($q);

        return $this->fetch('order/index', compact('list', 'total', 'page', 'size'));
    }
    public function detail()
    {
        $order = $this->row('order', $this->request->param('id'));

        return $this->fetch('order/detail', array('order' => $order, 'related' => OrderAdminService::related($order)));
    }
    public function edit()
    {
        $id = (int)$this->request->param('id');
        $order = $this->row('order', $id);
        if ($this->request->isPost()) {
            $in = $this->request->post();

            return $this->perform(function () use ($id, $in) {
                OrderAdminService::update($id, $in, $this->admin['username']);

                return '订单已保存';
            });
        }

        return $this->fetch('order/edit', array('order' => $order, 'statuses' => OrderAdminService::$statuses));
    }
    public function provision()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $charge = (int)$this->request->post('charge_balance', 0) === 1;

        return $this->perform(function () use ($id, $charge) {
            OrderAdminService::provision($id, $charge, $this->admin['username']);

            return '已提交开通/重新开通';
        });
    }
    public function lifecycle()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $action = (string)$this->request->post('action');
        $reason = (string)$this->request->post('reason');

        return $this->perform(function () use ($id, $action, $reason) {
            return OrderAdminService::lifecycle($id, $action, $reason, $this->admin['username']);
        });
    }
    public function cancel()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $opt = $this->request->post();

        return $this->perform(function () use ($id, $opt) {
            return OrderAdminService::cancel($id, $opt, $this->admin['username']);
        });
    }
    public function delete()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $opt = $this->request->post();

        return $this->perform(function () use ($id, $opt) {
            return OrderAdminService::delete($id, $opt, $this->admin['username']);
        }, '订单已删除');
    }
}
