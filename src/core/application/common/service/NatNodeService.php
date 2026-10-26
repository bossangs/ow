<?php
namespace app\common\service;

use app\common\nat\NatScript;
use think\Db;

/**
 * NAT 节点：令牌、安装命令、状态、容量、注册后关联上游。
 * 后台「NAT 节点」「上游接口」和节点注册 API 共用。PHP 7.0 兼容。
 */
class NatNodeService
{
    /** 一次性注册令牌（48 位十六进制） */
    public static function newToken()
    {
        return bin2hex(random_bytes(24));
    }

    /** 令牌有效期（小时），后台设置 nat_reg_token_hours，默认 24 */
    public static function tokenHours($hours = null)
    {
        $h = $hours === null || $hours === '' ? (int) setting('nat_reg_token_hours', '24') : (int) $hours;
        return max(1, min(720, $h ?: 24));
    }

    public static function options(array $node)
    {
        $o = json_decode((string) $node['options'], true);
        return is_array($o) ? $o : [];
    }

    /** 表单默认值：脚本默认值 + 面板地址取站点地址 */
    public static function formDefaults()
    {
        $d = array_intersect_key(NatScript::defaults(), array_flip(NatScript::OPTION_KEYS));
        $d['panel_url'] = rtrim(site_url(), '/');
        $d['images'] = 'debian12,debian11,ubuntu2404,ubuntu2204,alpine320';
        $d['enable_ipv6'] = 'auto';
        $ranges = [];
        $nets = [];
        foreach (Db::name('nat_node')->column('options') as $raw) {
            $o = json_decode((string) $raw, true);
            if (!is_array($o)) {
                continue;
            }
            if (isset($o['port_start'], $o['port_end'])) {
                $ranges[] = [(int) $o['port_start'], (int) $o['port_end']];
            }
            if (!empty($o['bridge_v4']) && preg_match('#^(\d+\.\d+)\.#', (string) $o['bridge_v4'], $m)) {
                $nets[$m[1]] = true;
            }
        }
        // 端口段：依次尝试 20000-30000、30001-40000 …，取第一个不与已有节点重叠的
        foreach ([[20000, 30000], [30001, 40000], [40001, 50000], [50001, 60000], [10000, 19999]] as $c) {
            $hit = false;
            foreach ($ranges as $r) {
                if ($c[0] <= $r[1] && $r[0] <= $c[1]) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $d['port_start'] = $c[0];
                $d['port_end'] = $c[1];
                break;
            }
        }
        // 内网段：10.88.0.1/16 起，取第一个未被其他节点使用的 10.x
        for ($x = 88; $x <= 254; $x++) {
            if (empty($nets['10.' . $x])) {
                $d['bridge_v4'] = '10.' . $x . '.0.1/16';
                break;
            }
        }
        return $d;
    }

    /** 默认节点名：节点-1、节点-2 …（跳过已存在的名称） */
    public static function defaultName()
    {
        $names = array_flip(Db::name('nat_node')->column('name'));
        $i = (int) Db::name('nat_node')->count() + 1;
        while (isset($names['节点-' . $i])) {
            $i++;
        }
        return '节点-' . $i;
    }

    /** 可关联的 NAT 上游（driver=natnode），排除已被其他节点占用的 */
    public static function freeUpstreams($exceptNodeId = 0)
    {
        $used = Db::name('nat_node')->where('upstream_id', '>', 0)->where('id', '<>', (int) $exceptNodeId)->column('upstream_id');
        $out = [];
        foreach (Db::name('upstream')->where('driver', 'natnode')->order('id asc')->field('id,name')->select() as $u) {
            if (!in_array($u['id'], $used)) {
                $out[(int) $u['id']] = $u['name'] . ' (#' . $u['id'] . ')';
            }
        }
        return $out;
    }

    /** 新建节点并生成令牌，返回节点 ID */
    public static function create($name, array $opts, $upstreamId = 0, $hours = null)
    {
        $upstreamId = (int) $upstreamId;
        if ($upstreamId) {
            $up = Db::name('upstream')->find($upstreamId);
            if (!$up || $up['driver'] !== 'natnode') {
                throw new \Exception('关联的上游不存在或不是 NAT 节点类型');
            }
            if (Db::name('nat_node')->where('upstream_id', $upstreamId)->count()) {
                throw new \Exception('该上游已经关联了其他节点');
            }
        }
        return (int) Db::name('nat_node')->insertGetId([
            'name' => $name,
            'upstream_id' => $upstreamId,
            'reg_token' => self::newToken(),
            'reg_expire' => time() + self::tokenHours($hours) * 3600,
            'options' => json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 0,
            'create_time' => time(),
        ]);
    }

    /** 快速添加：全部用默认值创建节点，返回节点 ID */
    public static function quickCreate($upstreamId = 0)
    {
        return self::create(self::defaultName(), NatScript::normalize(self::formDefaults()), $upstreamId);
    }

    /** 重新生成令牌：旧令牌立即失效 */
    public static function regenerate($id, $hours = null)
    {
        $token = self::newToken();
        $exp = time() + self::tokenHours($hours) * 3600;
        Db::name('nat_node')->where('id', (int) $id)->update(['reg_token' => $token, 'reg_expire' => $exp]);
        return [$token, $exp];
    }

    /** 节点状态：pending 待安装 / expired 令牌过期 / online 在线 / offline 离线 */
    public static function state(array $n)
    {
        $timeout = max(120, (int) setting('nat_node_timeout', '180'));
        if ((int) $n['registered_time'] <= 0 && (int) $n['status'] === 0) {
            if ($n['reg_token'] === '' || (int) $n['reg_expire'] < time()) {
                return ['code' => 'expired', 'label' => '令牌已过期', 'badge' => 'secondary'];
            }
            return ['code' => 'pending', 'label' => '待安装', 'badge' => 'warning'];
        }
        if ((int) $n['status'] === 1 && (int) $n['last_heartbeat'] >= time() - $timeout) {
            return ['code' => 'online', 'label' => '在线', 'badge' => 'success'];
        }
        return ['code' => 'offline', 'label' => '离线', 'badge' => 'danger'];
    }

    /** 容量：[已用实例, 可开实例上限, 端口范围文本] */
    public static function capacity(array $n)
    {
        $o = self::options($n);
        $stats = json_decode((string) $n['stats'], true);
        $stats = is_array($stats) ? $stats : [];
        $max = isset($o['max_instances']) ? (int) $o['max_instances'] : 100;
        $ps = isset($o['port_start']) ? (int) $o['port_start'] : 0;
        $pe = isset($o['port_end']) ? (int) $o['port_end'] : 0;
        $per = max(1, (int) setting('nat_ports_per_instance', '20'));
        if ($pe > $ps) {
            $max = min($max, (int) floor(($pe - $ps + 1) / $per));
        }
        $used = isset($stats['instances_total']) ? (int) $stats['instances_total'] : 0;
        return [$used, max(0, $max), $ps && $pe ? $ps . '-' . $pe : '-'];
    }

    /**
     * 安装命令信息。令牌已作废/过期时 valid=false，不给出命令
     */
    public static function commandInfo(array $n)
    {
        $o = self::options($n);
        $panel = isset($o['panel_url']) && preg_match('#^https?://#', (string) $o['panel_url']) ? rtrim($o['panel_url'], '/') : rtrim(site_url(), '/');
        $valid = $n['reg_token'] !== '' && (int) $n['reg_expire'] >= time();
        $info = [
            'valid' => $valid,
            'registered' => (int) $n['registered_time'] > 0,
            'expire' => (int) $n['reg_expire'],
            'agent_port' => isset($o['agent_port']) ? (int) $o['agent_port'] : 9180,
            'port_range' => (isset($o['port_start']) ? (int) $o['port_start'] : 20000) . '-' . (isset($o['port_end']) ? (int) $o['port_end'] : 30000),
            'script_url' => '', 'sha256' => '', 'commands' => [], 'error' => '',
        ];
        if (!$valid) {
            return $info;
        }
        try {
            $script = self::renderScript($n);
            $info['sha256'] = hash('sha256', $script);
            $info['script_url'] = NatScript::scriptUrl($panel, $n['id'], $n['reg_token']);
            $info['commands'] = explode("\n\n", NatScript::oneLiner($info['script_url'], $info['sha256']));
        } catch (\Exception $e) {
            $info['valid'] = false;
            $info['error'] = $e->getMessage();
        }
        return $info;
    }

    /** 渲染节点安装脚本（脚本下载接口与后台 SHA-256 共用，保证一致） */
    public static function renderScript(array $n)
    {
        $o = array_intersect_key(self::options($n), array_flip(NatScript::OPTION_KEYS));
        if (empty($o['panel_url']) || !preg_match('#^https?://#', (string) $o['panel_url'])) {
            $o['panel_url'] = rtrim(site_url(), '/');
        }
        $o['node_id'] = (string) $n['id'];
        $o['reg_token'] = (string) $n['reg_token'];
        return NatScript::render($o);
    }

    /** 心跳签名用的 Agent Token（兼容旧版明文保存） */
    public static function agentToken(array $opts)
    {
        if (!empty($opts['agent_token_enc'])) {
            return decrypt_str((string) $opts['agent_token_enc']);
        }
        return isset($opts['agent_token']) ? (string) $opts['agent_token'] : '';
    }

    /**
     * 节点注册：合并上报信息（保留后台配置）、作废注册令牌、自动创建/更新关联上游
     */
    public static function register(array $n, array $d)
    {
        $agentUrl = trim((string) $d['agent_url']);
        if (!preg_match('#^https?://(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.\-]+):\d{1,5}/?$#', $agentUrl)) {
            throw new \Exception('Agent 地址无效');
        }
        $agentToken = (string) $d['agent_token'];
        if (!preg_match('/^[A-Za-z0-9]{16,128}$/', $agentToken)) {
            throw new \Exception('Agent 令牌格式无效');
        }
        $o = self::options($n);
        unset($o['agent_token']);
        $reported = [];
        foreach (['public_ipv4', 'public_ipv6', 'backend', 'hostname', 'cpu_cores', 'mem_mb', 'disk_gb', 'images', 'agent_version'] as $k) {
            if (array_key_exists($k, $d) && (is_scalar($d[$k]) || is_array($d[$k]))) {
                $reported[$k] = is_array($d[$k]) ? implode(',', array_map('strval', $d[$k])) : $d[$k];
            }
        }
        $o['agent_url'] = $agentUrl;
        $o['agent_token_enc'] = encrypt_str($agentToken);
        $o['reported'] = $reported;
        $ip4 = isset($d['public_ipv4']) ? substr((string) $d['public_ipv4'], 0, 64) : '';
        $ip6 = isset($d['public_ipv6']) ? substr((string) $d['public_ipv6'], 0, 64) : '';
        $name = (string) $n['name'] !== '' ? $n['name'] : (isset($d['hostname']) ? substr((string) $d['hostname'], 0, 100) : 'NAT 节点');

        Db::startTrans();
        try {
            $upId = (int) $n['upstream_id'];
            $up = $upId ? Db::name('upstream')->find($upId) : null;
            if ($up && $up['driver'] === 'natnode') {
                $cfg = decrypt_json($up['config']);
            } else {
                $cfg = ['verify_ssl' => '0', 'timeout' => '15'];
                $upId = 0;
            }
            $cfg['agent_url'] = $agentUrl;
            $cfg['agent_token'] = $agentToken;
            $cfg['public_ip'] = $ip4 !== '' ? $ip4 : $ip6;
            if (!empty($o['tls'])) {
                $cfg['verify_ssl'] = '0'; // 安装脚本生成的是自签证书
            }
            if ($upId) {
                Db::name('upstream')->where('id', $upId)->update(['config' => encrypt_json($cfg), 'last_test_msg' => '节点已注册 ' . date('Y-m-d H:i')]);
            } else {
                $upId = (int) Db::name('upstream')->insertGetId([
                    'name' => 'NAT 节点：' . $name, 'driver' => 'natnode', 'config' => encrypt_json($cfg), 'status' => 1,
                    'remark' => '节点 #' . $n['id'] . ' 注册时自动创建', 'last_test_msg' => '节点已注册 ' . date('Y-m-d H:i'), 'create_time' => time(),
                ]);
            }
            Db::name('nat_node')->where('id', $n['id'])->update([
                'name' => $name, 'upstream_id' => $upId,
                'options' => json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'reg_token' => '', 'reg_expire' => 0, // 一次性：注册成功即作废
                'status' => 1, 'public_ip' => $ip4, 'public_ipv6' => $ip6,
                'last_heartbeat' => time(), 'registered_time' => time(),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        return $upId;
    }

    /** 上游编辑页用：与该上游关联的节点 */
    public static function nodeOfUpstream($upstreamId)
    {
        return $upstreamId ? Db::name('nat_node')->where('upstream_id', (int) $upstreamId)->find() : null;
    }
}
