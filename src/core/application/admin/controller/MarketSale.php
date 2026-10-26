<?php

namespace app\admin\controller;

use think\Db;

/**
 * 域名市场销售与佣金记录（只读，由系统在买家付款后自动生成）
 */
class MarketSale extends Crud
{
    protected $table = 'market_sale';
    protected $title = '销售与佣金';
    protected $canAdd = false;
    protected $canEdit = false;
    protected $canDelete = false;
    protected $searchFields = ['order_id', 'domain_id', 'seller_id', 'buyer_id'];

    protected function fields()
    {
        return [
            'domain_id' => ['根域名', 'type' => 'number'],
            'sub_id' => ['子域名', 'type' => 'number'],
            'seller_id' => ['卖家', 'type' => 'number'],
            'buyer_id' => ['买家', 'type' => 'number'],
            'order_id' => ['订单号', 'type' => 'number'],
            'gross_amount' => ['标价', 'type' => 'number'],
            'paid_amount' => ['买家实付', 'type' => 'number'],
            'base_amount' => ['计佣基数', 'type' => 'number'],
            'bearer' => ['优惠承担方', 'type' => 'select', 'options' => ['platform' => '平台', 'seller' => '卖家']],
            'commission' => ['平台佣金', 'type' => 'number'],
            'income' => ['卖家收入', 'type' => 'number'],
            'create_time' => ['时间', 'type' => 'text'],
        ];
    }

    protected function decorateList(array $list)
    {
        $domainIds = array_unique(array_column($list, 'domain_id'));
        $subIds = array_unique(array_column($list, 'sub_id'));
        $userIds = array_unique(array_merge(array_column($list, 'seller_id'), array_column($list, 'buyer_id')));
        $domains = $domainIds ? Db::name('dns_domain')->where('id', 'in', $domainIds)->column('domain', 'id') : [];
        $subs = $subIds ? Db::name('dns_subdomain')->where('id', 'in', $subIds)->column('fqdn', 'id') : [];
        $users = $userIds ? Db::name('user')->where('id', 'in', $userIds)->column('username', 'id') : [];
        $orders = Db::name('order')->where('id', 'in', array_column($list, 'order_id') ?: [0])->column('order_no', 'id');
        foreach ($list as &$r) {
            $r['domain_id_text'] = isset($domains[$r['domain_id']]) ? $domains[$r['domain_id']] : '#' . $r['domain_id'];
            $r['sub_id_text'] = isset($subs[$r['sub_id']]) ? $subs[$r['sub_id']] : ($r['sub_id'] ? '#' . $r['sub_id'] : '-');
            $r['seller_id_text'] = (isset($users[$r['seller_id']]) ? $users[$r['seller_id']] : '已删除') . ' #' . $r['seller_id'];
            $r['buyer_id_text'] = (isset($users[$r['buyer_id']]) ? $users[$r['buyer_id']] : '已删除') . ' #' . $r['buyer_id'];
            $r['order_id_text'] = isset($orders[$r['order_id']]) ? $orders[$r['order_id']] : '#' . $r['order_id'];
            foreach (['gross_amount', 'paid_amount', 'base_amount', 'commission', 'income'] as $m) {
                $r[$m . '_text'] = money($r[$m]);
            }
        }
        unset($r);

        return $list;
    }

    protected function topActions()
    {
        $sum = Db::name('market_sale')->field('COUNT(*) n, SUM(paid_amount) paid, SUM(commission) c, SUM(income) i')->find();

        return [[
            'label' => sprintf('合计 %d 笔 · 实付 %s · 佣金 %s · 卖家收入 %s', $sum['n'], money($sum['paid'] ?: 0), money($sum['c'] ?: 0), money($sum['i'] ?: 0)),
            'url' => url_to('admin/market_sale/index'),
        ]];
    }
}
