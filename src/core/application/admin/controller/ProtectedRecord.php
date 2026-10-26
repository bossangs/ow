<?php

namespace app\admin\controller;

use think\Db;

/**
 * 受保护记录：用户在市场发布域名时自动快照的原有解析，买家不能注册、修改或删除同名记录
 * 管理员也可手动添加需要保护的主机名（如 @、www、mail）
 */
class ProtectedRecord extends Crud
{
    protected $table = 'dns_protected';
    protected $title = '受保护记录';
    protected $canEdit = false;
    protected $searchFields = ['name', 'value', 'type'];

    protected function fields()
    {
        return [
            'domain_id' => ['根域名', 'type' => 'select', 'required' => true, 'options' => $this->optionsFrom('dns_domain', 'domain')],
            'name' => ['主机记录', 'type' => 'text', 'required' => true, 'help' => '如 @、www、mail；买家不能注册或修改同名记录'],
            'type' => ['类型', 'type' => 'text', 'help' => '可留空（保护该主机名下所有类型）'],
            'value' => ['记录值', 'type' => 'text', 'help' => '仅作备注'],
            'remote_id' => ['服务商记录 ID', 'type' => 'text', 'readonly' => true],
            'create_time' => ['时间', 'type' => 'text', 'readonly' => true],
        ];
    }

    protected function beforeSave(array $d, $id)
    {
        $d['name'] = strtolower(trim($d['name']));
        $d['type'] = strtoupper(trim($d['type']));
        if (!preg_match('/^(@|\*|[a-z0-9_*]([a-z0-9_.-]*[a-z0-9_])?)$/', $d['name'])) {
            throw new \Exception('主机记录格式不正确');
        }
        $d['create_time'] = time();

        return $d;
    }

    protected function decorateList(array $list)
    {
        $domains = Db::name('dns_domain')->where('id', 'in', array_column($list, 'domain_id') ?: [0])->column('domain', 'id');
        foreach ($list as &$r) {
            $r['domain_id_text'] = isset($domains[$r['domain_id']]) ? $domains[$r['domain_id']] : '#' . $r['domain_id'];
            $r['remote_id_text'] = $r['remote_id'] !== '' ? $r['remote_id'] : '手动添加';
        }
        unset($r);

        return $list;
    }
}
