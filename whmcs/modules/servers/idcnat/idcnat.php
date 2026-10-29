<?php
/**
 * WHMCS provisioning module for the IDCDNS NAT control-panel bridge.
 * Compatible with PHP 7.0+ and the WHMCS provisioning module API.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function idcnat_MetaData()
{
    return array(
        'DisplayName' => 'IDC NAT（面板自动发现）',
        'APIVersion' => '1.1',
        'RequiresServer' => false,
        'DefaultNonSSLPort' => 80,
        'DefaultSSLPort' => 443,
    );
}

function idcnat_ConfigOptions()
{
    return array(
        'default_image' => array(
            'FriendlyName' => '默认系统镜像',
            'Type' => 'dropdown',
            'Options' => array(
                'debian12' => 'Debian 12',
                'debian11' => 'Debian 11',
                'ubuntu2404' => 'Ubuntu 24.04',
                'ubuntu2204' => 'Ubuntu 22.04',
                'ubuntu2004' => 'Ubuntu 20.04',
                'alpine320' => 'Alpine 3.20',
            ),
            'Default' => 'debian12',
            'Description' => '可由产品可配置选项中的 Image 或 OS 覆盖。',
        ),
        'default_cpu' => array(
            'FriendlyName' => '默认 CPU 核数',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '1',
        ),
        'default_memory_mb' => array(
            'FriendlyName' => '默认内存（MB）',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '512',
        ),
        'default_disk_gb' => array(
            'FriendlyName' => '默认磁盘（GB）',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '10',
        ),
        'default_nat_ports' => array(
            'FriendlyName' => '默认额外 NAT 端口数',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '20',
            'Description' => 'SSH 映射端口由 Agent 单独分配，不计入此数。',
        ),
        'default_bandwidth_mbps' => array(
            'FriendlyName' => '默认带宽（Mbps）',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => '0 表示不向 Agent 单独设置带宽限制。',
        ),
    );
}

/** Build the IDCDNS control-panel base URL from standard WHMCS server fields. */
function idcnat_baseUrl($params)
{
    $host = '';
    if (!empty($params['serverhostname'])) {
        $host = trim((string) $params['serverhostname']);
    } elseif (!empty($params['serverip'])) {
        $host = trim((string) $params['serverip']);
    }
    if ($host === '') {
        throw new Exception('请填写 IDCDNS 控制面板主机名或 IP。');
    }
    if (preg_match('/[\x00-\x20\x7f]/', $host)) {
        throw new Exception('控制面板主机地址包含无效字符。');
    }

    if (preg_match('#^https?://#i', $host)) {
        $parts = parse_url($host);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new Exception('控制面板 URL 格式无效。');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new Exception('控制面板地址只能是 http(s)://主机[:端口]，不能带路径、账号或查询参数。');
        }
        $authority = $parts['host'];
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                throw new Exception('控制面板端口必须是 1–65535 之间的数字。');
            }
            $authority .= ':' . $port;
        } else {
            $port = isset($params['serverport']) && (int) $params['serverport'] > 0 ? (int) $params['serverport'] : ($scheme === 'https' ? 443 : 80);
            if ($port < 1 || $port > 65535) {
                throw new Exception('控制面板端口必须是 1–65535 之间的数字。');
            }
            $authority .= ':' . $port;
        }
        return $scheme . '://' . $authority;
    }

    if (strpos($host, '/') !== false || strpos($host, '@') !== false || preg_match('/\s/', $host)) {
        throw new Exception('控制面板主机名或 IP 格式无效。');
    }
    $scheme = !empty($params['serversecure']) ? 'https' : 'http';
    $authority = $host;
    $hasPort = false;
    $hostPort = 0;
    if (substr($host, 0, 1) === '[') {
        $hasPort = (bool) preg_match('/\]:\d+$/', $host);
        if ($hasPort && preg_match('/:(\d+)$/', $host, $portMatch)) {
            $hostPort = (int) $portMatch[1];
        }
    } elseif (substr_count($host, ':') > 1) {
        $authority = '[' . trim($host, '[]') . ']';
    } else {
        $hasPort = (bool) preg_match('/:\d+$/', $host);
        if ($hasPort && preg_match('/:(\d+)$/', $host, $portMatch)) {
            $hostPort = (int) $portMatch[1];
        }
    }
    if ($hasPort && ($hostPort < 1 || $hostPort > 65535)) {
        throw new Exception('控制面板端口必须是 1–65535 之间的数字。');
    }
    if (!$hasPort) {
        $port = isset($params['serverport']) && (int) $params['serverport'] > 0 ? (int) $params['serverport'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new Exception('控制面板端口必须是 1–65535 之间的数字。');
        }
        $authority .= ':' . $port;
    }
    return $scheme . '://' . $authority;
}

/** Resolve the panel URL/key from the Addon config, with server fields as compatibility fallback. */
function idcnat_panelParams($params)
{
    if (!empty($params['serverpassword']) && (!empty($params['serverhostname']) || !empty($params['serverip']))) {
        return $params;
    }
    $panelUrl = isset($params['panel_url']) ? trim((string) $params['panel_url']) : '';
    $bridgeKey = isset($params['bridge_key']) ? trim((string) $params['bridge_key']) : '';
    if (($panelUrl === '' || $bridgeKey === '') && class_exists('WHMCS\\Database\\Capsule')) {
        try {
            $rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'idcnatbridge')
                ->whereIn('setting', array('panel_url', 'bridge_key'))
                ->get(array('setting', 'value'));
            foreach ($rows as $row) {
                if ($row->setting === 'panel_url' && $panelUrl === '') $panelUrl = (string) $row->value;
                if ($row->setting === 'bridge_key' && $bridgeKey === '') $bridgeKey = (string) $row->value;
            }
        } catch (Exception $e) {
            // The provisioning result below will report that the Addon has not been configured.
        }
    }
    if ($panelUrl !== '') $params['serverhostname'] = $panelUrl;
    if ($bridgeKey !== '') $params['serverpassword'] = $bridgeKey;
    if (!isset($params['serversecure'])) $params['serversecure'] = true;
    if (!isset($params['serverport']) || (int) $params['serverport'] < 1) $params['serverport'] = 443;
    return $params;
}

function idcnat_safeMessage($message)
{
    $message = preg_replace('/(agent[_ -]?token|token|password|secret|api[_ -]?key|authorization)\s*[:=]\s*[^,;\s]+/i', '$1=***', (string) $message);
    $message = preg_replace('/[\r\n\x00-\x1f]+/', ' ', $message);
    return substr(trim($message), 0, 240);
}

/** Authenticated JSON request to the IDCDNS WHMCS bridge. */
function idcnat_panelApi($params, $method, $route, $body = null, $query = array())
{
    $params = idcnat_panelParams($params);
    if (!function_exists('curl_init')) {
        throw new Exception('WHMCS PHP 未启用 cURL 扩展。');
    }
    $token = isset($params['serverpassword']) ? trim((string) $params['serverpassword']) : '';
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        throw new Exception('请先在 WHMCS「Addon Modules」配置面板 URL 和 IDCDNS 后台生成的 64 位桥接密钥。');
    }
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', (string) $route)) {
        throw new Exception('内部 API 路由无效。');
    }
    $raw = '';
    if ($body !== null) {
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            throw new Exception('请求参数无法编码。');
        }
    }
    $url = idcnat_baseUrl($params) . '/index.php?s=' . rawurlencode('/api/nat/' . $route);
    if ($query) {
        $url .= '&' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $headers = array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    );
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    }
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        throw new Exception('无法连接 IDCDNS 控制面板，请检查地址、端口、防火墙及 SSL 配置。' . ($curlError ? ' (' . idcnat_safeMessage($curlError) . ')' : ''));
    }
    $decoded = json_decode($response, true);
    if ($status === 401) {
        throw new Exception('面板桥接密钥无效或已轮换，请更新 WHMCS Addon 配置。');
    }
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && isset($decoded['msg']) ? idcnat_safeMessage($decoded['msg']) : '';
        throw new Exception($message !== '' ? '面板请求失败：' . $message : '面板请求失败（HTTP ' . $status . '）。');
    }
    if (!is_array($decoded) || !isset($decoded['code']) || (int) $decoded['code'] !== 0) {
        $message = is_array($decoded) && isset($decoded['msg']) ? idcnat_safeMessage($decoded['msg']) : '';
        throw new Exception($message !== '' ? '面板返回错误：' . $message : '面板返回的数据格式无效。');
    }
    return isset($decoded['data']) ? $decoded['data'] : array();
}

function idcnat_externalId($params)
{
    $serviceId = isset($params['serviceid']) ? (string) $params['serviceid'] : '';
    if (!preg_match('/^[1-9][0-9]{0,19}$/', $serviceId)) {
        throw new Exception('WHMCS 服务 ID 缺失或无效。');
    }
    return $serviceId;
}

function idcnat_normalizeKey($key)
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
}

function idcnat_configurableValue($params, $keys, $default)
{
    if (empty($params['configoptions']) || !is_array($params['configoptions'])) {
        return $default;
    }
    $wanted = array();
    foreach ($keys as $key) {
        $wanted[] = idcnat_normalizeKey($key);
    }
    foreach ($params['configoptions'] as $name => $value) {
        if (in_array(idcnat_normalizeKey($name), $wanted, true) && !is_array($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }
    return $default;
}

function idcnat_productSettings($params)
{
    $defaults = array(
        'image' => isset($params['configoption1']) && trim((string) $params['configoption1']) !== '' ? trim((string) $params['configoption1']) : 'debian12',
        'cpu' => isset($params['configoption2']) && trim((string) $params['configoption2']) !== '' ? trim((string) $params['configoption2']) : '1',
        'memory_mb' => isset($params['configoption3']) && trim((string) $params['configoption3']) !== '' ? trim((string) $params['configoption3']) : '512',
        'disk_gb' => isset($params['configoption4']) && trim((string) $params['configoption4']) !== '' ? trim((string) $params['configoption4']) : '10',
        'nat_ports' => isset($params['configoption5']) && trim((string) $params['configoption5']) !== '' ? trim((string) $params['configoption5']) : '20',
        'bandwidth_mbps' => isset($params['configoption6']) && trim((string) $params['configoption6']) !== '' ? trim((string) $params['configoption6']) : '0',
    );
    $values = array(
        'image' => idcnat_configurableValue($params, array('Image', 'OS', 'System Image'), $defaults['image']),
        'cpu' => idcnat_configurableValue($params, array('CPU', 'CPU Cores', 'Cores'), $defaults['cpu']),
        'memory_mb' => idcnat_configurableValue($params, array('Memory', 'Memory MB', 'RAM', 'RAM MB'), $defaults['memory_mb']),
        'disk_gb' => idcnat_configurableValue($params, array('Disk', 'Disk GB', 'Storage', 'Storage GB'), $defaults['disk_gb']),
        'nat_ports' => idcnat_configurableValue($params, array('NAT Ports', 'Ports', 'Extra NAT Ports'), $defaults['nat_ports']),
        'bandwidth_mbps' => idcnat_configurableValue($params, array('Bandwidth', 'Bandwidth Mbps'), $defaults['bandwidth_mbps']),
    );
    $limits = array(
        'cpu' => array(1, 128, 'CPU 核数'),
        'memory_mb' => array(64, 1048576, '内存 MB'),
        'disk_gb' => array(1, 100000, '磁盘 GB'),
        'nat_ports' => array(0, 10000, 'NAT 端口数'),
        'bandwidth_mbps' => array(0, 1000000, '带宽 Mbps'),
    );
    foreach ($limits as $key => $rule) {
        if (!preg_match('/^\d+$/', (string) $values[$key]) || (int) $values[$key] < $rule[0] || (int) $values[$key] > $rule[1]) {
            throw new Exception($rule[2] . ' 必须是 ' . $rule[0] . '–' . $rule[1] . ' 之间的整数。');
        }
        $values[$key] = (int) $values[$key];
    }
    if (!preg_match('/^[a-z0-9]{2,24}$/', (string) $values['image'])) {
        throw new Exception('系统镜像代码无效。');
    }
    return $values;
}

function idcnat_normalizeInstance($data)
{
    if (!is_array($data)) {
        return array();
    }
    $instance = isset($data['raw']) && is_array($data['raw']) ? $data['raw'] : array();
    $instance['name'] = isset($data['hostname']) ? $data['hostname'] : (isset($instance['name']) ? $instance['name'] : '');
    $instance['status'] = isset($data['status']) ? $data['status'] : (isset($instance['status']) ? $instance['status'] : 'unknown');
    $instance['image'] = isset($data['os']) ? $data['os'] : (isset($instance['image']) ? $instance['image'] : '');
    $instance['public_ipv4'] = isset($data['ip']) ? $data['ip'] : (isset($instance['public_ipv4']) ? $instance['public_ipv4'] : '');
    foreach (array('ssh_port', 'ipv4_internal', 'ipv6') as $key) {
        if (isset($data[$key])) {
            $instance[$key] = $data[$key];
        }
    }
    if (isset($data['port_range']) && preg_match('/^(\d+)-(\d+)$/', (string) $data['port_range'], $m)) {
        $instance['port_start'] = (int) $m[1];
        $instance['port_end'] = (int) $m[2];
    }
    return $instance;
}

function idcnat_runAction($params, $action, $body = array())
{
    try {
        $externalId = idcnat_externalId($params);
        unset($body['external_id']);
        $body['action'] = $action;
        idcnat_panelApi($params, 'POST', 'whmcsAction', $body, array('external_id' => $externalId));
        return 'success';
    } catch (Exception $e) {
        return idcnat_safeMessage($e->getMessage());
    }
}

function idcnat_TestConnection($params)
{
    try {
        $data = idcnat_panelApi($params, 'GET', 'whmcsInfo');
        $nodes = isset($data['nodes']) && is_array($data['nodes']) ? $data['nodes'] : array();
        $online = 0;
        foreach ($nodes as $node) {
            if (isset($node['state']) && $node['state'] === 'online' && (!isset($node['available']) || (int) $node['available'] > 0)) {
                $online++;
            }
        }
        $message = '面板已连接，自动发现 ' . count($nodes) . ' 台 NAT 节点；当前可开通 ' . $online . ' 台。';
        return array('success' => true, 'error' => '', 'description' => $message);
    } catch (Exception $e) {
        return array('success' => false, 'error' => idcnat_safeMessage($e->getMessage()));
    }
}

function idcnat_CreateAccount($params)
{
    try {
        $externalId = idcnat_externalId($params);
        $settings = idcnat_productSettings($params);
        $password = isset($params['password']) ? (string) $params['password'] : '';
        if (strlen($password) < 8 || strlen($password) > 128 || preg_match('/[\r\n]/', $password)) {
            throw new Exception('WHMCS 服务密码无效：密码需为 8–128 个字符且不能包含换行。');
        }
        $domain = isset($params['domain']) ? trim((string) $params['domain']) : '';
        if ($domain === '' || !preg_match('/^[a-zA-Z0-9.-]{1,63}$/', $domain)) {
            $domain = 'whmcs-' . $externalId;
        }
        $payload = array_merge($settings, array(
            'external_id' => $externalId,
            'password' => $password,
            'hostname' => $domain,
            'billingcycle' => isset($params['billingcycle']) ? (string) $params['billingcycle'] : '',
        ));
        idcnat_panelApi($params, 'POST', 'whmcsCreate', $payload);
        return 'success';
    } catch (Exception $e) {
        return idcnat_safeMessage($e->getMessage());
    }
}

function idcnat_SuspendAccount($params)
{
    return idcnat_runAction($params, 'suspend');
}

function idcnat_UnsuspendAccount($params)
{
    return idcnat_runAction($params, 'unsuspend');
}

function idcnat_TerminateAccount($params)
{
    try {
        idcnat_panelApi($params, 'POST', 'whmcsTerminate', array('external_id' => idcnat_externalId($params)));
        return 'success';
    } catch (Exception $e) {
        return idcnat_safeMessage($e->getMessage());
    }
}

function idcnat_ChangePassword($params)
{
    $password = isset($params['password']) ? (string) $params['password'] : '';
    if (strlen($password) < 8 || strlen($password) > 128 || preg_match('/[\r\n]/', $password)) {
        return '新密码需为 8–128 个字符且不能包含换行。';
    }
    return idcnat_runAction($params, 'reset_password', array('password' => $password));
}

function idcnat_ClientArea($params)
{
    $instance = array();
    $errorMessage = '';
    $nodeName = '';
    try {
        $externalId = idcnat_externalId($params);
        $data = idcnat_panelApi($params, 'GET', 'whmcsGet', null, array('external_id' => $externalId));
        $instance = idcnat_normalizeInstance(isset($data['instance']) ? $data['instance'] : array());
        $nodeName = isset($data['node_name']) ? (string) $data['node_name'] : '';
    } catch (Exception $e) {
        $errorMessage = idcnat_safeMessage($e->getMessage());
    }
    return array(
        'templatefile' => 'clientarea',
        'vars' => array(
            'natInstance' => $instance,
            'natError' => $errorMessage,
            'natNodeName' => $nodeName,
            'natPublicIp' => isset($instance['public_ipv4']) ? $instance['public_ipv4'] : '',
        ),
    );
}

function idcnat_ClientAreaCustomButtonArray()
{
    return array(
        '开机' => 'start',
        '关机' => 'stop',
        '重启' => 'restart',
    );
}

function idcnat_AdminCustomButtonArray()
{
    return array(
        '开机' => 'start',
        '关机' => 'stop',
        '重启' => 'restart',
    );
}

function idcnat_start($params)
{
    return idcnat_runAction($params, 'start');
}

function idcnat_stop($params)
{
    return idcnat_runAction($params, 'stop');
}

function idcnat_restart($params)
{
    return idcnat_runAction($params, 'restart');
}
