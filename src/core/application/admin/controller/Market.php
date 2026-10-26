<?php

namespace app\admin\controller;

use think\Db;
use app\common\service\MarketService;

class Market extends \app\common\controller\AdminBase
{
    protected $title = '域名市场审核';
    public function index()
    {
        $q = Db::name('dns_domain')->where('user_id', '>', 0)->order('id desc');
        $status = trim((string)$this->request->get('status', ''));
        if ($status !== '') {
            $q->where('listing_status', $status);
        }
        list($list, $total, $page, $size) = $this->paginate($q, 20);

        return $this->fetch('common/crud_list', ['fields' => $this->fields(), 'list' => $list, 'total' => $total, 'page' => $page, 'size' => $size, 'ctrl' => 'market', 'search' => true, 'rowActions' => [['label' => '审核', 'url' => function ($r) {
            return url_to('admin/market/review', ['id' => $r['id']]);
        }]], 'topActions' => []]);
    }
    protected function fields()
    {
        return ['domain' => ['域名', 'type' => 'text', 'list' => true], 'user_id' => ['卖家 ID', 'type' => 'number', 'list' => true], 'provider_id' => ['服务商 ID', 'type' => 'number', 'list' => true], 'listing_status' => ['状态', 'type' => 'text', 'list' => true, 'badge' => 'listing'], 'verified' => ['所有权验证', 'type' => 'switch', 'list' => true], 'price' => ['售价', 'type' => 'number', 'list' => true], 'commission_rate' => ['佣金覆盖 %', 'type' => 'number', 'list' => true], 'sold' => ['已售', 'type' => 'number', 'list' => true], 'income' => ['收入', 'type' => 'number', 'list' => true]];
    }
    public function review()
    {
        $id = (int)$this->request->param('id', 0);
        $row = Db::name('dns_domain')->find($id);
        if (!$row) {
            $this->abort('域名不存在', 404);
        }
        if ($this->request->isPost()) {
            try {
                $action = $this->request->post('action', 'approve');
                $reason = trim((string)$this->request->post('reason', ''));
                if ($action === 'approve') {
                    MarketService::review($id, true, $reason);
                } elseif ($action === 'reject') {
                    MarketService::review($id, false, $reason);
                } else {
                    MarketService::setStatus($id, $action, $reason);
                }
                $this->flash('操作成功');

                return $this->redirectTo('admin/market/index');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('market/review', [
            'pageTitle' => '审核域名：' . $row['domain'],
            'row' => $row,
            'seller' => Db::name('user')->field('id,username,email')->find($row['user_id']),
            'provider' => Db::name('dns_provider')->field('id,name,driver')->find($row['provider_id']),
            'protected' => Db::name('dns_protected')->where('domain_id', $id)->order('name asc,type asc')->select(),
        ]);
    }
}
