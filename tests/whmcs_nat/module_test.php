<?php
define('WHMCS', true);
require __DIR__ . '/../../whmcs/modules/servers/idcnat/idcnat.php';

function expect($condition, $message)
{
    if (!$condition) {
        throw new Exception($message);
    }
}

$params = array(
    'serverhostname' => 'http://127.0.0.1',
    'serverip' => '127.0.0.1',
    'serverport' => 19184,
    'serversecure' => false,
    'serverpassword' => 'local-test-token',
    'serviceid' => 123,
    'pid' => 1,
    'billingcycle' => 'monthly',
    'password' => 'FirstSecurePass123',
    'configoption1' => 'debian12',
    'configoption2' => '1',
    'configoption3' => '512',
    'configoption4' => '10',
    'configoption5' => '20',
    'configoption6' => '0',
    'configoptions' => array(
        'Image' => 'ubuntu2404',
        'CPU Cores' => '4',
        'Memory MB' => '1024',
        'Disk GB' => '25',
        'NAT Ports' => '5',
        'Bandwidth Mbps' => '100',
    ),
);

$meta = idcnat_MetaData();
expect($meta['DisplayName'] === 'IDC NAT 节点', 'module metadata');
expect(count(idcnat_ConfigOptions()) === 6, 'product defaults');

$test = idcnat_TestConnection($params);
expect(!empty($test['success']), 'signed TestConnection failed: ' . (isset($test['error']) ? $test['error'] : ''));

expect(idcnat_CreateAccount($params) === 'success', 'CreateAccount failed');
// WHMCS retries a create after a timeout: the deterministic service name prevents a duplicate.
expect(idcnat_CreateAccount($params) === 'success', 'idempotent CreateAccount retry failed');

$instance = idcnat_getInstance($params, 'whmcs-123', false);
expect($instance['name'] === 'whmcs-123', 'automatic service ID name');
expect($instance['image'] === 'ubuntu2404', 'configurable image override');
expect((int) $instance['cpu'] === 4, 'configurable CPU override');
expect((int) $instance['memory_mb'] === 1024, 'configurable memory override');
expect((int) $instance['disk_gb'] === 25, 'configurable disk override');
expect((int) $instance['port_end'] - (int) $instance['port_start'] === 5, 'NAT port count');

$area = idcnat_ClientArea($params);
expect($area['templatefile'] === 'clientarea', 'client area template');
expect($area['vars']['natInstance']['name'] === 'whmcs-123', 'client area details');
expect($area['vars']['natPublicIp'] === '203.0.113.20', 'client area public IP');

expect(idcnat_SuspendAccount($params) === 'success', 'suspend');
expect(idcnat_UnsuspendAccount($params) === 'success', 'unsuspend');
expect(idcnat_stop($params) === 'success', 'stop button');
expect(idcnat_start($params) === 'success', 'start button');
expect(idcnat_restart($params) === 'success', 'restart button');

$params['password'] = 'NextSecurePass456';
expect(idcnat_ChangePassword($params) === 'success', 'password change');

$badToken = $params;
$badToken['serverpassword'] = 'not-the-agent-token';
$badTest = idcnat_TestConnection($badToken);
expect(empty($badTest['success']), 'bad token must fail');
expect(strpos($badTest['error'], '鉴权失败') !== false, 'bad token error is understandable');

$badConfig = $params;
$badConfig['serviceid'] = 124;
$badConfig['configoptions']['Memory MB'] = 'not-a-number';
$badCreate = idcnat_CreateAccount($badConfig);
expect($badCreate !== 'success' && strpos($badCreate, '内存') !== false, 'invalid configurable option rejected');

expect(idcnat_TerminateAccount($params) === 'success', 'terminate');
expect(idcnat_TerminateAccount($params) === 'success', 'idempotent terminate');

$base = idcnat_baseUrl(array('serverhostname' => 'node.example.test', 'serversecure' => true, 'serverport' => 9443));
expect($base === 'https://node.example.test:9443', 'HTTPS server URL');
$ipv6 = idcnat_baseUrl(array('serverhostname' => '2001:db8::1', 'serverport' => 9180));
expect($ipv6 === 'http://[2001:db8::1]:9180', 'IPv6 server URL');

echo "WHMCS NAT module integration passed\n";
