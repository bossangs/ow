<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;

/** /admin 入口：跳转到仪表盘（未登录时 AdminBase 会跳转到登录页） */
class Index extends AdminBase
{
    public function index()
    {
        return $this->redirectTo('admin/dashboard/index');
    }
}
