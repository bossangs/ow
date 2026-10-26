<?php

namespace app\admin\controller;

class Risk extends Crud
{
    protected $table = 'risk_rule';
    protected $title = '风控规则';
    protected $searchFields = ['type', 'match_type', 'pattern', 'action', 'remark', 'status'];
    protected function columns()
    {
        return [
            'type' => ['类型', 'type' => 'text'],
            'match_type' => ['匹配方式', 'type' => 'text'],
            'pattern' => ['规则', 'type' => 'text'],
            'action' => ['动作', 'type' => 'text'],
            'remark' => ['备注', 'type' => 'text'],
            'status' => ['状态', 'type' => 'switch'],
        ];
    }
}
