<?php

namespace app\admin\controller;

class Tier extends Crud
{
    protected $table = 'user_tier';
    protected $title = '会员等级';
    protected $searchFields = ['name', 'level', 'price', 'period_days', 'discount', 'dns_max_subdomains', 'dns_max_records', 'dns_types', 'dns_smart_line', 'dns_proxy', 'commission_rate', 'dns_review_exempt', 'is_default', 'purchasable', 'sort', 'status', 'description'];
    protected function columns()
    {
        return [
            'name' => ['名称', 'type' => 'text'],
            'level' => ['等级', 'type' => 'number'],
            'price' => ['价格', 'type' => 'number'],
            'period_days' => ['周期天数', 'type' => 'number'],
            'discount' => ['折扣', 'type' => 'number'],
            'dns_max_subdomains' => ['子域名配额', 'type' => 'number'],
            'dns_max_records' => ['记录配额', 'type' => 'number'],
            'dns_types' => ['记录类型', 'type' => 'text'],
            'dns_smart_line' => ['智能线路', 'type' => 'switch'],
            'dns_proxy' => ['代理', 'type' => 'switch'],
            'commission_rate' => ['佣金率', 'type' => 'number'],
            'dns_review_exempt' => ['免审', 'type' => 'switch'],
            'is_default' => ['默认', 'type' => 'switch'],
            'purchasable' => ['可购买', 'type' => 'switch'],
            'sort' => ['排序', 'type' => 'number'],
            'status' => ['状态', 'type' => 'switch'],
            'description' => ['说明', 'type' => 'textarea'],
        ];
    }
}
