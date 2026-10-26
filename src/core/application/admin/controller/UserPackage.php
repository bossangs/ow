<?php

namespace app\admin\controller;

class UserPackage extends OpsBase
{
    protected $table = 'user_package';
    protected $title = '用户套餐';
    protected $searchFields = array('user_id', 'package_id', 'name', 'types');
    protected function fields()
    {
        return array(
            'user_id' => array('用户ID', 'type' => 'number', 'list' => true),
            'package_id' => array('套餐ID', 'type' => 'number', 'list' => true),
            'name' => array('名称', 'type' => 'text', 'list' => true),
            'sub_quota' => array('子域名配额', 'type' => 'number', 'list' => true),
            'record_quota' => array('记录配额', 'type' => 'number', 'list' => true),
            'types' => array('记录类型', 'type' => 'text', 'list' => true),
            'proxy' => array('代理', 'type' => 'switch', 'list' => true),
            'expire_time' => array('到期时间', 'type' => 'number', 'list' => true),
        );
    }
}
