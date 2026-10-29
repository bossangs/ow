<?php
/**
 * WHMCS provisioning module for IDCDNS NAT Agent.
 * Compatible with PHP 7.0+ and the WHMCS provisioning module API.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function idcnat_MetaData()
{
    return array(
        'DisplayName' => 'IDC NAT 节点',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => 9180,
        'DefaultSSLPort' => 9180,
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

/** Create a server API URL from the standard WHMCS server fields. */
function idcnat_baseUrl($params)
{
    $host = '';
    if (!empty($params['serverhostname'])) {
        $host = trim((string) $params['serverhostname']);
    } elseif (!empty($params['serverip'])) {
        $host = trim((string) $params['serverip']);
    }
    if ($host === '') {
        throw new Exception('请在 WHMCS 服务器设置中填写 NAT Agent 主机名或 IP。');
    }
    if (preg_match('/[\x00-\x20\x7f]/', $host)) {
        throw new Exception('WHMCS 服务器主机地址包含无效字符。');
    }

    if (preg_match('#^https?://#i', $host)) {
        $parts = parse_url($host);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new Exception('WHMCS 服务器主机地址格式无效。');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new Exception('主机地址只能是 http(s)://主机[:端口]，不能包含路径、账号或查询参数。');
        }
        $authority = $parts['host'];
        if (isset($parts['port'])) {
            if ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535) {
                throw new Exception('NAT Agent 端口必须是 1–65535 之间的数字。');
            }
            $authority .= ':' . (int) $parts['port'];
        } else {
            $port = isset($params['serverport']) && (int) $params['serverport'] > 0 ? (int) $params['serverport'] : 9180;
            if ($port < 1 || $port > 65535) {
                throw new Exception('NAT Agent 端口必须是 1–65535 之间的数字。');
            }
            $authority .= ':' . $port;
        }
        return $scheme . '://' . $authority;
    }

    if (strpos($host, '/') !== false || strpos($host, '@') !== false || preg_match('/\s/', $host)) {
        throw new Exception('WHMCS 服务器主机名或 IP 格式无效。');
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
        throw new Exception('NAT Agent 端口必须是 1–65535 之间的数字。');
    }
    if (!$hasPort) {
        $port = isset($params['serverport']) && (int) $params['serverport'] > 0 ? (int) $params['serverport'] : 9180;
        if ($port < 1 || $port > 65535) {
            throw new Exception('NAT Agent 端口必须是 1–65535 之间的数字。');
        }
        $authority .= ':' . $port;
    }
    return $scheme . '://' . $authority;
}

function idcnat_safeMessage($message)
{
    $message = preg_replace('/(agent[_ -]?token|token|password|secret|api[_ -]?key)\s*[:=]\s*[^,;\s]+/i', '$1=***', (string) $message);
    $message = preg_replace('/[\r\n\x00-\x1f]+/', ' ', $message);
    return substr(trim($message), 0, 240);
}

/** Signed JSON request using the exact IDCNAT Agent HMAC protocol. */
function idcnat_api($params, $method, $path, $body = null, $allow404 = false)
{
    if (!function_exists('curl_init')) {
        throw new Exception('WHMCS PHP 未启用 cURL 扩展。');
    }
    $token = isset($params['serverpassword']) ? trim((string) $params['serverpassword']) : '';
    if ($token === '') {
        throw new Exception('请在 WHMCS 服务器设置的 Password 字段填写该节点的 Agent Token。');
    }
    if (!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, '?') !== false || strpos($path, "\n") !== false) {
        throw new Exception('内部请求路径无效。');
    }

    $raw = '';
    if ($body !== null) {
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            throw new Exception('NAT 请求参数无法编码。');
        }
    }
    try {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        throw new Exception('当前 PHP 环境无法生成安全随机数。');
    }
    $signatureInput = $timestamp . "\n" . $nonce . "\n" . strtoupper($method) . "\n" . $path . "\n" . hash('sha256', $raw);
    $signature = hash_hmac('sha256', $signatureInput, $token);
    $url = idcnat_baseUrl($params) . $path;
    $headers = array(
        'Accept: application/json',
        'X-IDC-Ts: ' . $timestamp,
        'X-IDC-Nonce: ' . $nonce,
        'X-IDC-Sign: ' . $signature,
    );
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    }
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('无法连接 NAT Agent。请检查服务器地址、端口、防火墙及 SSL 配置。' . ($curlError ? ' (' . idcnat_safeMessage($curlError) . ')' : ''));
    }
    $decoded = json_decode($response, true);
    if ($status === 404 && $allow404) {
        return null;
    }
    if ($status === 401) {
        throw new Exception('NAT Agent 鉴权失败，请检查 WHMCS 服务器 Password 中的 Agent Token。');
    }
    if ($status === 404) {
        throw new Exception('NAT Agent 接口或实例不存在，请检查模块和服务配置。');
    }
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && isset($decoded['msg']) ? idcnat_safeMessage($decoded['msg']) : '';
        throw new Exception($message !== '' ? 'NAT Agent 请求失败：' . $message : 'NAT Agent 请求失败（HTTP ' . $status . '）。');
    }
    if (!is_array($decoded) || !isset($decoded['code']) || (int) $decoded['code'] !== 0) {
        $message = is_array($decoded) && isset($decoded['msg']) ? idcnat_safeMessage($decoded['msg']) : '';
        throw new Exception($message !== '' ? 'NAT Agent 返回错误：' . $message : 'NAT Agent 返回的数据格式无效。');
    }
    return isset($decoded['data']) ? $decoded['data'] : array();
}

function idcnat_instanceName($params)
{
    $serviceId = isset($params['serviceid']) ? (string) $params['serviceid'] : '';
    if (!preg_match('/^[1-9][0-9]{0,20}$/', $serviceId)) {
        throw new Exception('WHMCS 服务 ID 缺失或无效，无法生成 NAT 实例名。');
    }
    return 'whmcs-' . $serviceId;
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

function idcnat_getInstance($params, $name, $allow404 = false)
{
    return idcnat_api($params, 'GET', '/v1/instances/' . rawurlencode($name), null, $allow404);
}

function idcnat_runAction($params, $action, $body = array())
{
    try {
        $name = idcnat_instanceName($params);
        idcnat_api($params, 'POST', '/v1/instances/' . rawurlencode($name) . '/action', array_merge($body, array('action' => $action)));
        return 'success';
    } catch (Exception $e) {
        return idcnat_safeMessage($e->getMessage());
    }
}

function idcnat_TestConnection($params)
{
    try {
        $data = idcnat_api($params, 'GET', '/v1/info');
        $node = is_array($data) && !empty($data['hostname']) ? (string) $data['hostname'] : 'NAT Agent';
        return array('success' => true, 'error' => '', 'description' => '连接成功：' . idcnat_safeMessage($node));
    } catch (Exception $e) {
        return array('success' => false, 'error' => idcnat_safeMessage($e->getMessage()));
    }
}

function idcnat_CreateAccount($params)
{
    try {
        $name = idcnat_instanceName($params);
        $existing = idcnat_getInstance($params, $name, true);
        if (is_array($existing)) {
            return 'success';
        }

        $settings = idcnat_productSettings($params);
        $info = idcnat_api($params, 'GET', '/v1/info');
        if (isset($info['images']) && is_array($info['images']) && $info['images'] && !in_array($settings['image'], $info['images'], true)) {
            throw new Exception('所选系统镜像未在该 NAT 节点启用，请检查节点镜像列表。');
        }
        $password = isset($params['password']) ? (string) $params['password'] : '';
        if (strlen($password) < 8 || strlen($password) > 128 || preg_match('/[\r\n]/', $password)) {
            throw new Exception('WHMCS 服务密码无效：密码需为 8–128 个字符且不能包含换行。');
        }
        $payload = array(
            'name' => $name,
            'image' => $settings['image'],
            'cpu' => $settings['cpu'],
            'memory_mb' => $settings['memory_mb'],
            'disk_gb' => $settings['disk_gb'],
            'swap_mb' => 0,
            'bandwidth_mbps' => $settings['bandwidth_mbps'],
            'nat_ports' => $settings['nat_ports'],
            'ipv6' => false,
            'password' => $password,
            'hostname' => $name,
            'billingcycle' => isset($params['billingcycle']) ? (string) $params['billingcycle'] : '',
        );
        try {
            idcnat_api($params, 'POST', '/v1/instances', $payload);
            return 'success';
        } catch (Exception $createError) {
            // WHMCS may retry after a timeout even if the Agent finished the create.
            // Reconcile by the deterministic WHMCS service name before returning an error.
            try {
                $after = idcnat_getInstance($params, $name, true);
                if (is_array($after)) {
                    return 'success';
                }
            } catch (Exception $ignored) {
                // Preserve the original provisioning error.
            }
            throw $createError;
        }
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
        // Verify the configured endpoint before treating a 404 as an already-deleted instance.
        idcnat_api($params, 'GET', '/v1/info');
        $name = idcnat_instanceName($params);
        $existing = idcnat_getInstance($params, $name, true);
        if ($existing === null) {
            return 'success';
        }
        idcnat_api($params, 'DELETE', '/v1/instances/' . rawurlencode($name));
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
    try {
        $name = idcnat_instanceName($params);
        $instance = idcnat_getInstance($params, $name, false);
        if (!is_array($instance)) {
            $instance = array();
        }
    } catch (Exception $e) {
        $errorMessage = idcnat_safeMessage($e->getMessage());
    }
    return array(
        'templatefile' => 'clientarea',
        'vars' => array(
            'natInstance' => $instance,
            'natError' => $errorMessage,
            'natPublicIp' => isset($instance['public_ipv4']) && $instance['public_ipv4'] !== '' ? $instance['public_ipv4'] : (isset($params['serverip']) ? $params['serverip'] : ''),
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
