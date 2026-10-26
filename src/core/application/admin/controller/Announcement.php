<?php

namespace app\admin\controller;

class Announcement extends OpsBase
{
    protected $table = 'announcement';
    protected $title = '公告';
    protected $searchFields = array('title', 'content');
    protected function fields()
    {
        return array('title' => array('标题', 'type' => 'text', 'list' => true, 'required' => true), 'content' => array('内容', 'type' => 'textarea', 'list' => false, 'required' => true), 'status' => array('状态', 'type' => 'switch', 'list' => true), 'sort' => array('排序', 'type' => 'number', 'list' => true));
    }
}
