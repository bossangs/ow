<?php

namespace app\admin\controller;

use think\Db;

class Audit extends OpsBase
{
    protected $title = '审计日志';
    public function index()
    {
        $q = Db::name('audit_log')->order('id desc');
        foreach (array('action', 'level', 'actor_name') as $k) {
            $v = trim((string)$this->request->get($k, ''));
            if ($v !== '') {
                $q->where($k, 'like', '%' . $v . '%');
            }
        }list($list, $total, $page, $size) = $this->paged($q);

        return $this->fetch('audit/index', compact('list', 'total', 'page', 'size'));
    } public function detail()
    {
        return $this->fetch('audit/detail', array('row' => $this->row('audit_log', $this->request->param('id'))));
    }
}
