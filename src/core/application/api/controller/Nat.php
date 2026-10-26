<?php

namespace app\api\controller;

use app\common\controller\Base;
use app\common\nat\NatScript;
use think\Db;

class Nat extends Base
{
    protected $noCsrf = ['script', 'register', 'heartbeat'];
    private function body()
    {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw, true);

        return is_array($d) ? $d : [];
    }
    public function script()
    {
        $id = (int)$this->request->get('node', 0);
        $token = (string)$this->request->get('token', '');
        $n = $id ? Db::name('nat_node')->find($id) : null;
        if (!$n || !$token || !hash_equals((string)$n['reg_token'], $token) || (int)$n['reg_expire'] < time()) {
            return \think\Response::create('Unauthorized', 'text', 401);
        }
        $o = json_decode((string)$n['options'], true);
        if (!is_array($o)) {
            $o = [];
        } $o['node_id'] = (string)$n['id'];
        $o['reg_token'] = $n['reg_token'];
        $o['panel_url'] = rtrim(site_url(), '/');
        try {
            return \think\Response::create(NatScript::render($o), 'text', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (\Exception $e) {
            return \think\Response::create($e->getMessage(), 'text', 400);
        }
    }
    public function register()
    {
        $d = $this->body();
        $id = (int)(isset($d['node_id']) ? $d['node_id'] : 0);
        $reg = (string)(isset($d['reg_token']) ? $d['reg_token'] : '');
        $n = $id ? Db::name('nat_node')->find($id) : null;
        if (!$n || !$reg || !hash_equals((string)$n['reg_token'], $reg) || (int)$n['reg_expire'] < time()) {
            return $this->fail('注册令牌无效', 403);
        }
        if (empty($d['agent_url']) || empty($d['agent_token'])) {
            return $this->fail('缺少 Agent 地址或令牌', 422);
        }
        $allowed = ['agent_url', 'agent_token', 'public_ipv4', 'public_ipv6', 'backend', 'hostname', 'cpu_cores', 'mem_mb', 'disk_gb', 'port_start', 'port_end', 'max_instances', 'images', 'agent_version'];
        $o = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $d)) {
                $o[$k] = $d[$k];
            }
        } $o['agent_url'] = $d['agent_url'];
        Db::name('nat_node')->where('id', $id)->update(['name' => isset($d['hostname']) ? (string)$d['hostname'] : $n['name'], 'options' => json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 1, 'public_ip' => isset($d['public_ipv4']) ? $d['public_ipv4'] : '', 'public_ipv6' => isset($d['public_ipv6']) ? $d['public_ipv6'] : '', 'last_heartbeat' => time(), 'registered_time' => time()]);

        return $this->ok('节点注册成功', ['node_id' => $id]);
    }
    public function heartbeat()
    {
        $d = $this->body();
        $id = (int)(isset($d['node_id']) ? $d['node_id'] : 0);
        $ts = (string)(isset($d['ts']) ? $d['ts'] : '');
        $sig = (string)(isset($d['sign']) ? $d['sign'] : '');
        $n = $id ? Db::name('nat_node')->find($id) : null;
        $opts = json_decode((string)$n['options'], true);
        if (!is_array($opts)) {
            $opts = [];
        } $agentToken = isset($opts['agent_token']) ? (string)$opts['agent_token'] : '';
        if (!$n || !$ts || abs(time() - (int)$ts) > 300 || $agentToken === '' || !hash_equals(hash_hmac('sha256', (string)$id . '|' . $ts, $agentToken), $sig)) {
            return $this->fail('心跳签名无效', 403);
        }
        $stats = [];
        foreach (['instances_total', 'instances_running', 'ports_used', 'load1', 'mem_used_mb', 'version'] as $k) {
            if (isset($d[$k])) {
                $stats[$k] = $d[$k];
            }
        } Db::name('nat_node')->where('id', $id)->update(['status' => 1, 'last_heartbeat' => time(), 'public_ip' => isset($d['public_ipv4']) ? $d['public_ipv4'] : $n['public_ip'], 'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE)]);

        return $this->ok('心跳已更新');
    }
}
