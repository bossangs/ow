<?php

namespace app\common\nat;

class NatScript
{
    public static function defaults()
    {
        return array('panel_url' => '', 'node_id' => '', 'reg_token' => '', 'agent_port' => 9180, 'backend' => 'auto', 'storage_driver' => 'dir', 'storage_size_gb' => 20, 'pool_path' => '', 'port_start' => 20000, 'port_end' => 30000, 'bridge_v4' => '10.88.0.1/16', 'enable_ipv6' => 0, 'images' => 'debian12,ubuntu2404,alpine320', 'forward_mode' => 'proxy', 'max_instances' => 100, 'tls' => 0);
    }
    private static function val($v, $key)
    {
        if (is_int($v) || is_float($v)) {
            $v = (string)$v;
        }
        if (!is_string($v) || preg_match('/["\'\r\n$]/', $v)) {
            throw new \InvalidArgumentException('参数无效: ' . $key);
        }

        return $v;
    }
    public static function render(array $vars)
    {
        $v = array_merge(self::defaults(), $vars);
        foreach ($v as $k => $x) {
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
        if (!in_array($v['backend'], array('auto', 'incus', 'lxd'), true) || !in_array($v['storage_driver'], array('dir', 'btrfs', 'zfs', 'lvm'), true) || !in_array($v['forward_mode'], array('proxy', 'iptables'), true)) {
            throw new \InvalidArgumentException('枚举参数无效');
        }
        $path = __DIR__ . '/install.sh.tpl';
        $s = file_get_contents($path);
        $map = array('__PANEL_URL__' => $v['panel_url'], '__NODE_ID__' => $v['node_id'], '__REG_TOKEN__' => $v['reg_token'], '__AGENT_PORT__' => $v['agent_port'], '__BACKEND__' => $v['backend'], '__STORAGE_DRIVER__' => $v['storage_driver'], '__STORAGE_SIZE_GB__' => $v['storage_size_gb'], '__POOL_PATH__' => $v['pool_path'], '__PORT_START__' => $v['port_start'], '__PORT_END__' => $v['port_end'], '__BRIDGE_V4__' => $v['bridge_v4'], '__ENABLE_IPV6__' => $v['enable_ipv6'], '__IMAGES__' => $v['images'], '__FORWARD_MODE__' => $v['forward_mode'], '__MAX_INSTANCES__' => $v['max_instances'], '__TLS__' => $v['tls'], '__AGENT_PY_B64__' => base64_encode(file_get_contents(__DIR__ . '/agent/idcnat_agent.py')));

        return str_replace(array_keys($map), array_values($map), $s);
    }
    public static function oneLiner($scriptUrl)
    {
        self::val($scriptUrl, 'scriptUrl');

        return "bash <(curl -fsSL '" . $scriptUrl . "')\n\ncurl -fsSL '" . $scriptUrl . "' -o idcnat.sh && bash idcnat.sh";
    }
}
