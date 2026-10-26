<?php

namespace app\admin\controller;

class CancelRequest extends OpsBase
{
    protected $title = '取消申请';
    public function index()
    {
        $q = \think\Db::name('cancel_request')->order('id desc');
        list($list, $total, $page, $size) = $this->paged($q);

        return $this->fetch('cancelrequest/index', compact('list', 'total', 'page', 'size'));
    }
    public function handle()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $approve = (string)$this->request->post('decision') === 'approve';
        $note = (string)$this->request->post('note');
        $amount = (float)$this->request->post('refund_amount', 0);

        return $this->perform(function () use ($id, $approve, $note, $amount) {
            \app\common\service\OrderAdminService::handleRequest($id, $approve, $note, array('refund_amount' => $amount), $this->admin['username']);

            return $approve ? '取消申请已批准' : '取消申请已驳回';
        });
    }
}
