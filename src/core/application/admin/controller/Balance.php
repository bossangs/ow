<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\service\BalanceService;
use think\Db;

class Balance extends AdminBase
{
    protected $title = '余额调整';
    public function index()
    {
        $q = Db::name('balance_log');
        $kw = trim((string)$this->request->get('kw', ''));
        if ($kw !== '') {
            $q->where('user_id|type|remark|operator', 'like', '%' . $kw . '%');
        }$type = $this->request->get('type', '');
        if ($type !== '') {
            $q->where('type', $type);
        }list($list, $total, $page, $size) = $this->paginate($q->order('id desc'), 20);

        return $this->fetch('balance/index', compact('list', 'total', 'page', 'size', 'kw', 'type'));
    }
    public function adjust()
    {
        if (!$this->request->isPost()) {
            return $this->fetch('balance/adjust');
        }try {
            $uid = (int)$this->request->post('user_id');
            $after = BalanceService::adjust($this->admin, $uid, $this->request->post('amount'), $this->request->post('reason'), ['allow_negative' => $this->request->post('allow_negative'), 'notify' => $this->request->post('notify'), 'admin_password' => $this->request->post('admin_password', ''), 'admin_2fa_code' => (string)$this->request->post('admin_2fa_code', '')]);
            $this->flash('调整成功，余额：' . money($after));
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->back();
    }
    public function batch()
    {
        if (!$this->request->isPost()) {
            return $this->fetch('balance/batch');
        }try {
            $r = BalanceService::batch($this->admin, $this->request->post('targets'), $this->request->post('amount'), $this->request->post('reason'), ['allow_negative' => $this->request->post('allow_negative'), 'notify' => $this->request->post('notify'), 'admin_password' => $this->request->post('admin_password', ''), 'admin_2fa_code' => (string)$this->request->post('admin_2fa_code', '')]);
            $this->flash('成功 ' . $this->countOk($r['ok']) . ' 个，失败 ' . count($r['fail']) . ' 个');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->back();
    }
    protected function countOk($a)
    {
        return count($a);
    }
}
