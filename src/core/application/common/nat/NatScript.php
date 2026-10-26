<?php
namespace app\common\nat;
/**
 * NAT 节点一键安装脚本：参数校验、渲染、安装命令。PHP 7.0 兼容。
 */
class NatScript
{
    /** 安装脚本支持预下载的镜像（与 install.sh.tpl 里的白名单一致） */
    const IMAGES = array(
        'debian12' => 'Debian 12', 'debian13' => 'Debian 13', 'debian11' => 'Debian 11',
        'ubuntu2404' => 'Ubuntu 24.04', 'ubuntu2204' => 'Ubuntu 22.04', 'ubuntu2004' => 'Ubuntu 20.04',
        'alpine321' => 'Alpine 3.21', 'alpine320' => 'Alpine 3.20', 'rocky9' => 'Rocky Linux 9', 'alma9' => 'AlmaLinux 9',
    );
    const BACKENDS = array('auto' => '自动（优先 Incus）', 'incus' => 'Incus', 'lxd' => 'LXD（snap 安装）');
    const STORAGES = array('dir' => 'dir（目录，最简单）', 'btrfs' => 'btrfs', 'zfs' => 'zfs', 'lvm' => 'lvm');
    const IPV6 = array('auto' => '自动（节点有公网 IPv6 就启用）', '1' => '启用', '0' => '不启用');
    const FORWARDS = array('proxy' => 'proxy 设备（推荐）', 'iptables' => 'iptables DNAT');

    /** 后台表单里可配置、会写进脚本的选项 */
    const OPTION_KEYS = array('panel_url', 'agent_port', 'backend', 'storage_driver', 'storage_size_gb', 'pool_path',
        'port_start', 'port_end', 'bridge_v4', 'enable_ipv6', 'images', 'forward_mode', 'max_instances', 'tls');

    public static function defaults()
    {
        return array('panel_url' => '', 'node_id' => '', 'reg_token' => '', 'agent_port' => 9180, 'backend' => 'auto', 'storage_driver' => 'dir', 'storage_size_gb' => 20, 'pool_path' => '', 'port_start' => 20000, 'port_end' => 30000, 'bridge_v4' => '10.88.0.1/16', 'enable_ipv6' => 'auto', 'images' => 'debian12,ubuntu2404,alpine320', 'forward_mode' => 'proxy', 'max_instances' => 100, 'tls' => 0);
    }
    private static function val($v, $key)
    {
        if (is_bool($v)) {
            $v = $v ? '1' : '0';
        } elseif (is_int($v) || is_float($v)) {
            $v = (string)$v;
        }
        if (!is_string($v) || preg_match('/["\'\r\n$`\\\\]/', $v)) {
            throw new \InvalidArgumentException('参数无效: ' . $key);
        }
        return $v;
    }
    private static function intIn($v, $min, $max, $label)
    {
        $s = trim((string)$v);
        if (!preg_match('/^\d+$/', $s) || (int)$s < $min || (int)$s > $max) {
            throw new \InvalidArgumentException($label . '应为 ' . $min . '-' . $max . ' 之间的整数');
        }
        return (int)$s;
    }
    private static function flag($v)
    {
        return ($v === true || (string)$v === '1' || (string)$v === 'on') ? 1 : 0;
    }
    /**
     * 校验并整理后台提交的节点选项（中文报错），返回只含 OPTION_KEYS 的数组
     * $in['images'] 可以是数组或逗号分隔字符串
     */
    public static function normalize(array $in)
    {
        $d = self::defaults();
        $g = function ($k) use ($in, $d) {
            return array_key_exists($k, $in) ? $in[$k] : $d[$k];
        };
        $o = array();
        $url = rtrim(trim((string)$g('panel_url')), '/');
        if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~\-/]*)?$#', $url)) {
            throw new \InvalidArgumentException('面板地址无效，应为 http(s)://域名，例如 https://dns.example.com');
        }
        $o['panel_url'] = $url;
        $o['agent_port'] = self::intIn($g('agent_port'), 1, 65535, 'Agent 端口');
        $o['backend'] = (string)$g('backend');
        if (!array_key_exists($o['backend'], self::BACKENDS)) {
            throw new \InvalidArgumentException('后端选择无效');
        }
        $o['storage_driver'] = (string)$g('storage_driver');
        if (!array_key_exists($o['storage_driver'], self::STORAGES)) {
            throw new \InvalidArgumentException('存储类型无效');
        }
        $o['storage_size_gb'] = self::intIn($g('storage_size_gb'), 5, 100000, '存储大小');
        $pool = trim((string)$g('pool_path'));
        if ($pool !== '' && !preg_match('#^[A-Za-z0-9_./\-]{1,200}$#', $pool)) {
            throw new \InvalidArgumentException('存储源路径只能包含字母、数字和 _ . / -');
        }
        $o['pool_path'] = $pool;
        $o['port_start'] = self::intIn($g('port_start'), 1024, 65535, '起始端口');
        $o['port_end'] = self::intIn($g('port_end'), 1024, 65535, '结束端口');
        if ($o['port_end'] <= $o['port_start']) {
            throw new \InvalidArgumentException('结束端口必须大于起始端口');
        }
        if ($o['agent_port'] >= $o['port_start'] && $o['agent_port'] <= $o['port_end']) {
            throw new \InvalidArgumentException('Agent 端口不能落在转发端口范围内');
        }
        $cidr = trim((string)$g('bridge_v4'));
        if (!preg_match('#^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})/(\d{1,2})$#', $cidr, $m) || max((int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]) > 255 || (int)$m[5] < 8 || (int)$m[5] > 30) {
            throw new \InvalidArgumentException('内网段格式应为 网关IP/掩码，例如 10.88.0.1/16');
        }
        $o['bridge_v4'] = $cidr;
        $o['enable_ipv6'] = (string)$g('enable_ipv6') === 'auto' ? 'auto' : self::flag($g('enable_ipv6'));
        $imgs = $g('images');
        $imgs = is_array($imgs) ? $imgs : explode(',', (string)$imgs);
        $sel = array();
        foreach ($imgs as $i) {
            $i = trim((string)$i);
            if ($i === '') {
                continue;
            }
            if (!array_key_exists($i, self::IMAGES)) {
                throw new \InvalidArgumentException('不支持的镜像：' . $i);
            }
            $sel[$i] = true;
        }
        if (!$sel) {
            throw new \InvalidArgumentException('请至少选择一个系统镜像');
        }
        $o['images'] = implode(',', array_keys($sel));
        $o['forward_mode'] = (string)$g('forward_mode');
        if (!array_key_exists($o['forward_mode'], self::FORWARDS)) {
            throw new \InvalidArgumentException('转发方式无效');
        }
        $o['max_instances'] = self::intIn($g('max_instances'), 1, 100000, '最大实例数');
        $o['tls'] = self::flag($g('tls'));
        return $o;
    }
    public static function render(array $vars)
    {
        $v = array_intersect_key(array_merge(self::defaults(), $vars), self::defaults());
        foreach ($v as $k => $x) {
            if (is_array($x)) {
                $x = implode(',', $x);
            }
            $v[$k] = self::val($x, $k);
        }
        if (!preg_match('#^https?://[^ ]+$#', $v['panel_url'])) {
            throw new \InvalidArgumentException('panel_url 无效');
        }
        foreach (array('agent_port', 'storage_size_gb', 'port_start', 'port_end', 'max_instances') as $k) {
            if ((int)$v[$k] != (float)$v[$k] || (int)$v[$k] < 1) {
                throw new \InvalidArgumentException($k . ' 无效');
            }
        }
        if (!array_key_exists($v['backend'], self::BACKENDS) || !array_key_exists($v['storage_driver'], self::STORAGES) || !array_key_exists($v['forward_mode'], self::FORWARDS)) {
            throw new \InvalidArgumentException('枚举参数无效');
        }
        if (!preg_match('/^[a-z0-9,]*$/', $v['images']) || !preg_match('/^[A-Za-z0-9]*$/', $v['reg_token']) || !preg_match('/^\d*$/', $v['node_id'])) {
            throw new \InvalidArgumentException('参数无效');
        }
        $s = file_get_contents(__DIR__ . '/install.sh.tpl');
        $map = array('__PANEL_URL__' => $v['panel_url'], '__NODE_ID__' => $v['node_id'], '__REG_TOKEN__' => $v['reg_token'], '__AGENT_PORT__' => $v['agent_port'], '__BACKEND__' => $v['backend'], '__STORAGE_DRIVER__' => $v['storage_driver'], '__STORAGE_SIZE_GB__' => $v['storage_size_gb'], '__POOL_PATH__' => $v['pool_path'], '__PORT_START__' => $v['port_start'], '__PORT_END__' => $v['port_end'], '__BRIDGE_V4__' => $v['bridge_v4'], '__ENABLE_IPV6__' => $v['enable_ipv6'], '__IMAGES__' => $v['images'], '__FORWARD_MODE__' => $v['forward_mode'], '__MAX_INSTANCES__' => $v['max_instances'], '__TLS__' => $v['tls'], '__AGENT_PY_B64__' => base64_encode(file_get_contents(__DIR__ . '/agent/idcnat_agent.py')));
        return str_replace(array_keys($map), array_values($map), $s);
    }
    /** 脚本下载地址（不依赖伪静态） */
    public static function scriptUrl($panelUrl, $nodeId, $token)
    {
        return rtrim($panelUrl, '/') . '/index.php?s=/api/nat/script&node=' . (int)$nodeId . '&token=' . rawurlencode($token);
    }
    /**
     * 两条安装命令（以空行分隔）：
     * 1) 直接执行；2) 先下载，（给了 SHA-256 时）校验通过再执行
     */
    public static function oneLiner($scriptUrl, $sha256 = '')
    {
        self::val($scriptUrl, 'scriptUrl');
        $second = "curl -fsSL '" . $scriptUrl . "' -o idcnat.sh && ";
        if ($sha256 !== '') {
            if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                throw new \InvalidArgumentException('sha256 无效');
            }
            $second .= "echo '" . $sha256 . "  idcnat.sh' | sha256sum -c - && ";
        }
        // 下载失败（令牌过期/已用、网络不通）时 bash <() 会读到空内容并以 0 退出，这里补一个明确的报错
        $fail = "echo \"echo '[idcnat] 下载安装脚本失败：链接无效、已过期或已使用，请在后台重新生成令牌' >&2; exit 1\"";
        return "bash <(curl -fsSL '" . $scriptUrl . "' || " . $fail . ")\n\n" . $second . 'bash idcnat.sh';
    }
}
