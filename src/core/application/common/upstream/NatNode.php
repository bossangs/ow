<?php

namespace app\common\upstream;

use app\common\lib\HttpClient;

class NatNode extends UpstreamDriver
{
    public static function info()
    {
        return array('code' => 'natnode', 'name' => 'NAT 小鸡节点（自建 Incus/LXD）', 'fields' => array(array('key' => 'agent_url', 'label' => 'Agent 地址', 'type' => 'text', 'required' => true), array('key' => 'agent_token', 'label' => 'Agent Token', 'type' => 'password', 'required' => true, 'secret' => true), array('key' => 'public_ip', 'label' => '公网 IP', 'type' => 'text'), array('key' => 'verify_ssl', 'label' => '验证 SSL', 'type' => 'select', 'options' => array('1' => '是', '0' => '否')), array('key' => 'timeout', 'label' => '超时', 'type' => 'number')), 'actions' => array('on' => '开机', 'off' => '关机', 'reboot' => '重启', 'reinstall' => '重装系统', 'reset_password' => '重置密码'), 'doc' => '');
    }
    public function __construct(array $config)
    {
        if (isset($config['agent_url']) && !isset($config['url'])) {
            $config['url'] = $config['agent_url'];
        } parent::__construct($config);
    }
    private function req($method, $path, $body = null)
    {
        $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string)time();
        $nonce = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
        $sig = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . strtoupper($method) . "\n" . $path . "\n" . hash('sha256', $raw), isset($this->config['agent_token']) ? $this->config['agent_token'] : '');
        $r = HttpClient::request($method, $this->url($path), array('Content-Type' => 'application/json', 'X-IDC-Ts' => $ts, 'X-IDC-Nonce' => $nonce, 'X-IDC-Sign' => $sig), $body === null ? null : $raw, $this->timeout);
        if ($r['error']) {
            throw new UpstreamException('NAT 节点连接失败');
        } $d = HttpClient::json($r);
        if (!is_array($d) || $r['status'] >= 400 || isset($d['code']) && $d['code'] != 0) {
            throw new UpstreamException(is_array($d) && isset($d['msg']) ? $d['msg'] : 'NAT 节点请求失败');
        }

        return isset($d['data']) ? $d['data'] : $d;
    }
    public function info2()
    {
        return $this->req('GET', '/v1/info');
    }
    public function testConnection()
    {
        return array('ok' => true, 'message' => 'NAT 节点连接成功', 'node' => $this->info2());
    }
    public function getProducts()
    {
        return array();
    }
    public function createService(array $p)
    {
        $o = isset($p['options']) && is_array($p['options']) ? $p['options'] : array();
        $name = 'vm' . strtolower(substr(bin2hex(function_exists('random_bytes') ? random_bytes(6) : openssl_random_pseudo_bytes(6)), 0, 12));
        $d = $this->req('POST', '/v1/instances', array('name' => $name, 'image' => isset($p['os']) ? $p['os'] : 'debian12', 'cpu' => isset($o['cpu']) ? $o['cpu'] : 1, 'memory_mb' => isset($o['memory_mb']) ? $o['memory_mb'] : 512, 'disk_gb' => isset($o['disk_gb']) ? $o['disk_gb'] : 10, 'swap_mb' => isset($o['swap_mb']) ? $o['swap_mb'] : 0, 'bandwidth_mbps' => isset($o['bandwidth_mbps']) ? $o['bandwidth_mbps'] : 0, 'nat_ports' => isset($o['nat_ports']) ? $o['nat_ports'] : 0, 'ipv6' => !empty($o['ipv6']), 'password' => isset($p['password']) ? $p['password'] : '', 'hostname' => isset($p['hostname']) ? $p['hostname'] : '', 'billingcycle' => isset($p['billingcycle']) ? $p['billingcycle'] : ''));

        return array('service_id' => $name, 'order_id' => '', 'status' => 'active', 'raw' => $d);
    }
    public function getService($id)
    {
        $d = $this->req('GET', '/v1/instances/' . rawurlencode($id));
        $ip = isset($d['public_ipv4']) ? $d['public_ipv4'] : (isset($this->config['public_ip']) ? $this->config['public_ip'] : '');
        $a = isset($d['port_start']) ? $d['port_start'] : 0;
        $b = isset($d['port_end']) ? $d['port_end'] : $a;

        return array('status' => $this->status(isset($d['status']) ? $d['status'] : ''), 'ip' => $ip, 'ips' => $ip ? array($ip) : array(), 'username' => 'root', 'password' => '', 'hostname' => isset($d['name']) ? $d['name'] : '', 'os' => isset($d['image']) ? $d['image'] : '', 'due_date' => '', 'raw' => $d, 'ssh_port' => isset($d['ssh_port']) ? $d['ssh_port'] : $a, 'port_range' => $a . '-' . $b, 'ipv4_internal' => isset($d['ipv4_internal']) ? $d['ipv4_internal'] : '', 'ipv6' => isset($d['ipv6']) ? $d['ipv6'] : '', 'ssh' => 'ssh root@' . $ip . ' -p ' . (isset($d['ssh_port']) ? $d['ssh_port'] : $a));
    }
    public function renewService($id, $cycle)
    {
        return true;
    }
    public function suspend($id, $reason = '')
    {
        $this->req('POST', '/v1/instances/' . rawurlencode($id) . '/action', array('action' => 'suspend'));

        return true;
    }
    public function unsuspend($id)
    {
        $this->req('POST', '/v1/instances/' . rawurlencode($id) . '/action', array('action' => 'unsuspend'));

        return true;
    }
    public function terminate($id)
    {
        $this->req('DELETE', '/v1/instances/' . rawurlencode($id));

        return true;
    }
    public function action($id, $action, array $params = array())
    {
        $m = array('on' => 'start', 'off' => 'stop', 'reboot' => 'restart', 'reinstall' => 'reinstall', 'reset_password' => 'reset_password');
        $a = isset($m[$action]) ? $m[$action] : $action;
        $params['action'] = $a;

        return $this->req('POST', '/v1/instances/' . rawurlencode($id) . '/action', $params);
    }
}
