<?php

namespace app\common\upstream;

use app\common\lib\HttpClient;

abstract class UpstreamDriver
{
    protected $config;
    protected $timeout = 20;
    public function __construct(array $config)
    {
        $this->config = $config;
        if (isset($config['timeout'])) {
            $this->timeout = (int)$config['timeout'];
        } HttpClient::$verifySsl = !isset($config['verify_ssl']) || $config['verify_ssl'] !== false;
    }
    public static function info()
    {
        return array('code' => 'base', 'name' => 'Upstream', 'fields' => array(), 'actions' => array(), 'doc' => '');
    }
    abstract public function testConnection();
    abstract public function getProducts();
    abstract public function createService(array $p);
    abstract public function getService($id);
    abstract public function renewService($id, $cycle);
    abstract public function suspend($id, $reason = '');
    abstract public function unsuspend($id);
    abstract public function terminate($id);
    abstract public function action($id, $action, array $params = array());
    protected function url($path)
    {
        return rtrim($this->config['url'], '/') . '/' . ltrim($path, '/');
    }
    protected function jsonRequest($method, $url, $body = array(), $headers = array())
    {
        $r = HttpClient::request($method, $url, $headers, $body ? json_encode($body) : null, $this->timeout);
        if ($r['error']) {
            throw new UpstreamException('上游连接失败');
        } $d = HttpClient::json($r);
        if (!is_array($d)) {
            throw new UpstreamException('上游返回格式错误');
        } if ($r['status'] >= 400) {
            throw new UpstreamException($this->safeError($d, '上游请求失败'));
        }

        return $d;
    }
    protected function safeError($d, $fallback)
    {
        $m = '';
        if (isset($d['message'])) {
            $m = $d['message'];
        } elseif (isset($d['error'])) {
            $m = is_string($d['error']) ? $d['error'] : '';
        } elseif (isset($d['msg'])) {
            $m = $d['msg'];
        } $m = preg_replace('/(secret|password|token|api[_ -]?key)\s*[:=]\s*[^,; ]+/i', '$1=***', (string)$m);

        return $m ? $m : $fallback;
    }
    protected function status($s)
    {
        $s = strtolower((string)$s);
        if (in_array($s, array('active', 'running', 'on', 'unsuspend', '正常', '已开通'))) {
            return 'active';
        } if (in_array($s, array('pending', 'processing', '待开通'))) {
            return 'pending';
        } if (in_array($s, array('suspended', 'suspend', 'off', '已暂停'))) {
            return 'suspended';
        } if (in_array($s, array('terminated', 'terminate', '已删除'))) {
            return 'terminated';
        } if (in_array($s, array('cancelled', 'canceled'))) {
            return 'cancelled';
        }

        return 'unknown';
    }
    protected function product($x)
    {
        $pr = array();
        foreach (array('monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially') as $c) {
            $v = null;
            if (isset($x['pricing'][$c])) {
                $v = (float)$x['pricing'][$c];
            } elseif (isset($x[$c])) {
                $v = (float)$x[$c];
            } $pr[$c] = $v;
        }

        return array('pid' => (string)(isset($x['pid']) ? $x['pid'] : (isset($x['id']) ? $x['id'] : '')), 'name' => (string)(isset($x['name']) ? $x['name'] : ''), 'group' => (string)(isset($x['group']) ? $x['group'] : ''), 'description' => (string)(isset($x['description']) ? $x['description'] : ''), 'pricing' => $pr, 'stock' => isset($x['stock']) ? (int)$x['stock'] : -1, 'options' => isset($x['options']) && is_array($x['options']) ? $x['options'] : array(), 'raw' => $x);
    }
}
