<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use think\Db;

/**
 * 通用 CRUD 控制器基类
 * 子类可实现 fields()（完整定义）或 columns()（简写：'字段' => '标签' 或完整数组）
 */
abstract class Crud extends AdminBase
{
    protected function columns()
    {
        return [];
    }

    protected function fields()
    {
        $out = [];
        foreach ($this->columns() as $k => $v) {
            $out[$k] = is_array($v) ? $v : [$v, 'type' => 'text', 'list' => true];
        }

        return $out;
    }

    /**
     * 从数据表生成下拉选项 [id => name]
     * @param string $table 表名（不含前缀）
     * @param array  $prepend 预置选项，如 [0 => '无']
     */
    protected function optionsFrom($table, $label = 'name', array $prepend = [], array $where = [])
    {
        $opts = $prepend;
        try {
            $q = Db::name($table)->field('id,' . $label);
            if ($where) {
                $q->where($where);
            }
            foreach ($q->order('id asc')->limit(500)->select() as $r) {
                $opts[$r['id']] = $r[$label] . ' (#' . $r['id'] . ')';
            }
        } catch (\Exception $e) {
            // 表不存在时返回预置选项
        }

        return $opts;
    }

    /** 把 user_id 列替换成「用户名 #ID」显示 */
    protected function userLabels(array $list, $key = 'user_id')
    {
        $ids = [];
        foreach ($list as $r) {
            if (!empty($r[$key])) {
                $ids[] = (int) $r[$key];
            }
        }
        $names = $ids ? Db::name('user')->where('id', 'in', array_unique($ids))->column('username', 'id') : [];
        foreach ($list as &$r) {
            $uid = (int) $r[$key];
            if ($uid === 0) {
                $r[$key . '_text'] = '平台';
            } else {
                $r[$key . '_text'] = (isset($names[$uid]) ? $names[$uid] : '已删除用户') . ' #' . $uid;
            }
        }
        unset($r);

        return $list;
    }
}
