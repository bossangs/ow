<?php

namespace app\admin\controller;

class NatNode extends Crud
{
    /** 修改密钥/接口配置、审批等敏感操作需要二次验证（已开启两步验证的管理员） */
    protected $sudoActions = ['edit:post', 'delete'];
    protected $table = 'nat_node';
    protected $title = 'NAT节点';
    protected $searchFields = ['name', 'upstream_id', 'reg_token', 'reg_expire', 'options', 'status', 'public_ip', 'public_ipv6', 'last_heartbeat'];
    protected function columns()
    {
        return [
            'name' => ['名称', 'type' => 'text'],
            'upstream_id' => ['上游ID', 'type' => 'number'],
            'reg_token' => ['注册令牌', 'type' => 'password'],
            'reg_expire' => ['令牌过期', 'type' => 'number'],
            'options' => ['配置', 'type' => 'textarea'],
            'status' => ['状态', 'type' => 'number'],
            'public_ip' => ['IPv4', 'type' => 'text'],
            'public_ipv6' => ['IPv6', 'type' => 'text'],
            'last_heartbeat' => ['最后心跳', 'type' => 'number'],
        ];
    }
}
