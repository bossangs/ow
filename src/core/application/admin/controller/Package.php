<?php

namespace app\admin\controller;

class Package extends Crud
{
    protected $table = 'dns_package';
    protected $title = 'DNS套餐';
    protected $searchFields = ['name', 'price', 'days', 'sub_quota', 'record_quota', 'types', 'smart_line', 'proxy', 'status', 'sort', 'description'];
    protected function columns()
    {
        return [
            'name' => ['名称', 'type' => 'text'],
            'price' => ['价格', 'type' => 'number'],
            'days' => ['天数', 'type' => 'number'],
            'sub_quota' => ['子域名配额', 'type' => 'number'],
            'record_quota' => ['记录配额', 'type' => 'number'],
            'types' => ['记录类型', 'type' => 'text'],
            'smart_line' => ['智能线路', 'type' => 'switch'],
            'proxy' => ['代理', 'type' => 'switch'],
            'status' => ['状态', 'type' => 'switch'],
            'sort' => ['排序', 'type' => 'number'],
            'description' => ['说明', 'type' => 'textarea'],
            'stock' => ['库存', 'type' => 'number'], 'sold' => ['已售', 'type' => 'number', 'readonly' => true], 'reserved' => ['预留', 'type' => 'number', 'readonly' => true], 'per_user_limit' => ['每用户限购', 'type' => 'number'], 'stock_alert' => ['低库存阈值', 'type' => 'number'], 'stock_state' => ['库存状态', 'type' => 'text', 'readonly' => true],
        ];
    }
}
