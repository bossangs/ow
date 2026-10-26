<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\service\NatNodeService;
use think\Db;

/**
 * NAT 节点对接接口：下载安装脚本、节点注册、心跳。PHP 7.0 兼容。
 */
class Nat extends Base
{
    protected $noCsrf = ['script', 'register', 'heartbeat'];

    private function body()
    {
        $d = json_decode((string) file_get_contents('php://input'), true);
        return is_array($d) ? $d : [];
    }

    /** 按 ID + 一次性令牌找节点；令牌为空、不匹配或已过期都返回 null */
    private function nodeByToken($id, $token)
    {
        $n = $id > 0 ? Db::name('nat_node')->find($id) : null;
        if (!$n || $token === '' || (string) $n['reg_token'] === '' || !hash_equals((string) $n['reg_token'], $token) || (int) $n['reg_expire'] < time()) {
            return null;
        }
        return $n;
    }

    public function script()
    {
        $n = $this->nodeByToken((int) $this->request->get('node', 0), (string) $this->request->get('token', ''));
        $headers = ['Cache-Control' => 'no-store, no-cache, must-revalidate', 'X-Content-Type-Options' => 'nosniff'];
        if (!$n) {
            return \think\Response::create("echo '[idcnat] 安装链接无效或已过期，请在后台重新生成令牌' >&2; exit 1\n", 'text', 401, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        try {
            $script = NatNodeService::renderScript($n);
        } catch (\Exception $e) {
            return \think\Response::create("echo '[idcnat] 节点配置无效：请在后台检查节点设置' >&2; exit 1\n", 'text', 400, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return \think\Response::create($script, 'text', 200, $headers + ['Content-Type' => 'text/plain; charset=utf-8', 'X-IDCNAT-SHA256' => hash('sha256', $script)]);
    }

    public function register()
    {
        $d = $this->body();
        $n = $this->nodeByToken((int) (isset($d['node_id']) ? $d['node_id'] : 0), (string) (isset($d['reg_token']) ? $d['reg_token'] : ''));
        if (!$n) {
            return $this->fail('注册令牌无效或已过期', 403);
        }
        if (empty($d['agent_url']) || empty($d['agent_token']) || !is_string($d['agent_url']) || !is_string($d['agent_token'])) {
            return $this->fail('缺少 Agent 地址或令牌', 422);
        }
        try {
            $upId = NatNodeService::register($n, $d);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 422);
        }
        audit('nat.node.register', 'NAT节点#' . $n['id'], ['agent_url' => $d['agent_url'], 'upstream_id' => $upId], 'info', 'system', 0, 'NAT 节点');
        return $this->ok('节点注册成功', ['node_id' => (int) $n['id'], 'upstream_id' => $upId]);
    }

    public function heartbeat()
    {
        $d = $this->body();
        $id = (int) (isset($d['node_id']) ? $d['node_id'] : 0);
        $ts = (string) (isset($d['ts']) ? $d['ts'] : '');
        $sig = (string) (isset($d['sign']) ? $d['sign'] : '');
        $n = $id > 0 ? Db::name('nat_node')->find($id) : null;
        $agentToken = $n ? NatNodeService::agentToken(NatNodeService::options($n)) : '';
        if (!$n || $ts === '' || abs(time() - (int) $ts) > 300 || $agentToken === '' || !hash_equals(hash_hmac('sha256', (string) $id . '|' . $ts, $agentToken), $sig)) {
            return $this->fail('心跳签名无效', 403);
        }
        $stats = [];
        foreach (['instances_total', 'instances_running', 'ports_used', 'load1', 'mem_used_mb', 'version'] as $k) {
            if (isset($d[$k]) && is_scalar($d[$k])) {
                $stats[$k] = $d[$k];
            }
        }
        $ip4 = isset($d['public_ipv4']) && is_string($d['public_ipv4']) && $d['public_ipv4'] !== '' ? substr($d['public_ipv4'], 0, 64) : $n['public_ip'];
        Db::name('nat_node')->where('id', $id)->update(['status' => 1, 'last_heartbeat' => time(), 'public_ip' => $ip4, 'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE)]);
        return $this->ok('心跳已更新');
    }
}
