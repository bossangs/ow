<?php

namespace app\admin\controller;

class ProductGroup extends Crud
{
    protected $table = 'product_group';
    protected $title = '产品分组';
    protected $searchFields = ['name', 'description', 'sort', 'status'];
    protected function columns()
    {
        return [
            'name' => ['名称', 'type' => 'text'],
            'description' => ['说明', 'type' => 'textarea'],
            'sort' => ['排序', 'type' => 'number'],
            'status' => ['状态', 'type' => 'switch'],
        ];
    }
}
