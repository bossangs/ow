<?php

namespace app\admin\controller;

use app\common\service\DnsService;
use think\Db;

class Record extends OpsBase
{
    protected $table = 'dns_record';
    protected $title = '解析记录';
    protected $searchFields = array('sub_id', 'name', 'type', 'value', 'status');
    protected function fields()
    {
        return array('sub_id' => array('子域名ID', 'type' => 'number', 'list' => true), 'name' => array('名称', 'type' => 'text', 'list' => true), 'type' => array('类型', 'type' => 'text', 'list' => true), 'value' => array('记录值', 'type' => 'text', 'list' => true), 'line' => array('线路', 'type' => 'text', 'list' => true), 'ttl' => array('TTL', 'type' => 'number', 'list' => true), 'status' => array('状态', 'type' => 'text', 'list' => true, 'badge' => 'record'), 'remote_id' => array('远端ID', 'type' => 'text', 'list' => true));
    }
    public function delete()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');

        return $this->perform(function () use ($id) {
            $r = $this->row('dns_record', $id);
            DnsService::deleteRecord(array('id' => (int)$r['user_id']), $id, true);

            return '解析记录已删除';
        });
    }
}
