<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use think\Db;

abstract class OpsBase extends AdminBase
{
    protected function perform($callback, $success = '操作成功')
    {
        try {
            $messages = call_user_func($callback);
            $this->flash(is_array($messages) ? implode("\n", $messages) : ($messages ?: $success));
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->back();
    }
    protected function row($table, $id)
    {
        $row = Db::name($table)->find((int)$id);
        if (!$row) {
            $this->abort('记录不存在', 404);
        }

        return $row;
    }
    protected function paged($query, $size = 20)
    {
        return $this->paginate($query, $size);
    }
    protected function search($query, $fields)
    {
        $kw = trim((string)$this->request->get('kw', ''));
        if ($kw !== '') {
            $query->where(implode('|', $fields), 'like', '%' . $kw . '%');
        }

        return $query;
    }
}
