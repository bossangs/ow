<?php
/**
 * WHMCS Addon for pairing IDC NAT products with an IDCDNS control panel.
 * PHP 7.0+.
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function idcnatbridge_config()
{
    return array(
        'name' => 'IDC NAT Bridge',
        'description' => '一次配置 IDCDNS 面板；产品开通时自动发现并选择 NAT 节点，无需新增 WHMCS Server 记录。',
        'version' => '2.1.0',
        'author' => 'IDCDNS',
        'fields' => array(
            'panel_url' => array(
                'FriendlyName' => 'IDCDNS 面板 API 地址',
                'Type' => 'text',
                'Size' => '60',
                'Default' => '',
                'Description' => '填写面板站点根地址，例如 https://panel.example.com；必须启用有效 HTTPS。',
            ),
            'bridge_key' => array(
                'FriendlyName' => '一次性配对密钥',
                'Type' => 'password',
                'Size' => '64',
                'Default' => '',
                'Description' => '在 IDCDNS 后台「NAT 节点 → WHMCS 自动对接」生成。请妥善保管；轮换会撤销旧密钥。',
            ),
        ),
    );
}

function idcnatbridge_activate()
{
    return array('status' => 'success', 'description' => 'Addon 已启用。请在配置页填写面板根地址与桥接密钥。');
}

function idcnatbridge_deactivate()
{
    // Keep settings so temporary deactivation does not silently break existing services.
    return array('status' => 'success', 'description' => 'Addon 已停用；现有配置保留，服务管理操作需重新启用后才能继续。');
}

function idcnatbridge_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function idcnatbridge_output($vars)
{
    $moduleFile = defined('ROOTDIR') ? ROOTDIR . '/modules/servers/idcnat/idcnat.php' : '';
    if ($moduleFile === '' || !is_file($moduleFile)) {
        echo '<div class="alert alert-danger">未找到 modules/servers/idcnat/idcnat.php，请确认安装包解压到了 WHMCS 根目录。</div>';
        return;
    }
    require_once $moduleFile;

    $panel = array(
        'panel_url' => isset($vars['panel_url']) ? (string) $vars['panel_url'] : '',
        'bridge_key' => isset($vars['bridge_key']) ? (string) $vars['bridge_key'] : '',
    );
    echo '<h2>IDC NAT Bridge</h2>';
    echo '<p>此连接供所有选择 IDC NAT provisioning module 的产品共用；不需要在「服务器」页面新增 Server，也不需要逐台添加 NAT 节点。</p>';
    if (trim($panel['panel_url']) === '' || trim($panel['bridge_key']) === '') {
        echo '<div class="alert alert-warning">请先到「系统设置 → Addon Modules」配置面板 API 地址与桥接密钥，然后刷新此页。</div>';
        return;
    }

    try {
        $data = idcnat_panelApi($panel, 'GET', 'whmcsInfo');
        $nodes = isset($data['nodes']) && is_array($data['nodes']) ? $data['nodes'] : array();
        $available = 0;
        foreach ($nodes as $node) {
            if (isset($node['state']) && $node['state'] === 'online' && isset($node['available']) && (int) $node['available'] > 0) {
                $available++;
            }
        }
        echo '<div class="alert alert-success">面板连接成功：发现 ' . count($nodes) . ' 台 NAT 节点，其中 ' . $available . ' 台在线且有可用容量。</div>';
        if (!$nodes) {
            echo '<p>尚未发现已注册节点。先在 IDCDNS 面板生成节点安装命令并在 NAT 主机上运行一次，注册后刷新此页即可。</p>';
            return;
        }
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>节点</th><th>状态</th><th>公网 IP</th><th>容量（已用/总量）</th><th>可用</th></tr></thead><tbody>';
        foreach ($nodes as $node) {
            $used = isset($node['used']) ? (int) $node['used'] : 0;
            $capacity = isset($node['capacity']) ? (int) $node['capacity'] : 0;
            $free = isset($node['available']) ? (int) $node['available'] : max(0, $capacity - $used);
            echo '<tr><td>' . idcnatbridge_h(isset($node['name']) ? $node['name'] : '') . '</td>';
            echo '<td>' . idcnatbridge_h(isset($node['state']) ? $node['state'] : 'unknown') . '</td>';
            echo '<td>' . idcnatbridge_h(isset($node['public_ip']) ? $node['public_ip'] : '') . '</td>';
            echo '<td>' . $used . ' / ' . $capacity . '</td><td>' . $free . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">面板连接失败：' . idcnatbridge_h(idcnat_safeMessage($e->getMessage())) . '</div>';
        echo '<p>请检查面板地址、有效 HTTPS 证书、桥接密钥，以及 WHMCS 服务器到面板的网络连通性。</p>';
    }
}
