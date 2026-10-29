<?php
define('WHMCS', true);
class IdcnatTestQuery
{
    private $table;
    public function __construct($table) { $this->table = $table; }
    public function where($column, $value) { return $this; }
    public function whereIn($column, $values) { return $this; }
    public function get($columns)
    {
        if ($this->table !== 'tbladdonmodules') return array();
        return array(
            (object) array('setting' => 'panel_url', 'value' => 'http://127.0.0.1'),
            (object) array('setting' => 'bridge_key', 'value' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'),
        );
    }
}
eval('namespace WHMCS\\Database; class Capsule { public static function table($table) { return new \\IdcnatTestQuery($table); } }');
require __DIR__ . '/../../whmcs/modules/servers/idcnat/idcnat.php';
require __DIR__ . '/../../whmcs/modules/addons/idcnatbridge/idcnatbridge.php';

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
    'serverpassword' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
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
expect($meta['DisplayName'] === 'IDC NAT（面板自动发现）', 'panel bridge metadata');
expect($meta['RequiresServer'] === false, 'WHMCS server record must not be required');
expect(count(idcnat_ConfigOptions()) === 6, 'product defaults');
$addonConfig = idcnatbridge_config();
expect(isset($addonConfig['fields']['panel_url'], $addonConfig['fields']['bridge_key']), 'global Addon pairing fields');

$test = idcnat_TestConnection($params);
expect(!empty($test['success']), 'panel TestConnection failed: ' . (isset($test['error']) ? $test['error'] : ''));
expect(strpos($test['description'], '1 台 NAT 节点') !== false, 'automatic node discovery');

$addonParams = $params;
unset($addonParams['serverhostname'], $addonParams['serverip'], $addonParams['serverpassword']);
$addonParams['panel_url'] = 'http://127.0.0.1';
$addonParams['bridge_key'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$addonTest = idcnat_TestConnection($addonParams);
expect(!empty($addonTest['success']), 'Addon global config TestConnection failed: ' . (isset($addonTest['error']) ? $addonTest['error'] : ''));
$globalParams = $params;
unset($globalParams['serverhostname'], $globalParams['serverip'], $globalParams['serverpassword']);
$globalTest = idcnat_TestConnection($globalParams);
expect(!empty($globalTest['success']), 'automatic read from WHMCS Addon settings failed: ' . (isset($globalTest['error']) ? $globalTest['error'] : ''));

expect(idcnat_CreateAccount($params) === 'success', 'CreateAccount failed');
// The panel bridge maps a WHMCS service ID and makes retries idempotent.
expect(idcnat_CreateAccount($globalParams) === 'success', 'CreateAccount without a WHMCS Server record failed');
expect(idcnat_CreateAccount($globalParams) === 'success', 'idempotent CreateAccount retry failed');

$area = idcnat_ClientArea($globalParams);
expect($area['templatefile'] === 'clientarea', 'client area template');
expect($area['vars']['natInstance']['name'] === 'vm-123', 'client area service details');
expect($area['vars']['natInstance']['image'] === 'ubuntu2404', 'configurable image override');
expect((int) $area['vars']['natInstance']['cpu'] === 4, 'configurable CPU override');
expect((int) $area['vars']['natInstance']['memory_mb'] === 1024, 'configurable memory override');
expect((int) $area['vars']['natInstance']['disk_gb'] === 25, 'configurable disk override');
expect($area['vars']['natNodeName'] === 'mock-node', 'automatically selected node name');
expect($area['vars']['natPublicIp'] === '203.0.113.20', 'client area public IP');

expect(idcnat_SuspendAccount($globalParams) === 'success', 'suspend');
expect(idcnat_UnsuspendAccount($globalParams) === 'success', 'unsuspend');
expect(idcnat_stop($globalParams) === 'success', 'stop button');
expect(idcnat_start($globalParams) === 'success', 'start button');
expect(idcnat_restart($globalParams) === 'success', 'restart button');

$globalParams['password'] = 'NextSecurePass456';
expect(idcnat_ChangePassword($globalParams) === 'success', 'password change');

$badToken = $params;
$badToken['serverpassword'] = str_repeat('0', 64);
$badTest = idcnat_TestConnection($badToken);
expect(empty($badTest['success']), 'bad bridge key must fail');
expect(strpos($badTest['error'], '桥接密钥无效') !== false, 'bad bridge key error is understandable');

$badConfig = $params;
$badConfig['serviceid'] = 124;
$badConfig['configoptions']['Memory MB'] = 'not-a-number';
$badCreate = idcnat_CreateAccount($badConfig);
expect($badCreate !== 'success' && strpos($badCreate, '内存') !== false, 'invalid configurable option rejected');

expect(idcnat_TerminateAccount($globalParams) === 'success', 'terminate');
expect(idcnat_TerminateAccount($globalParams) === 'success', 'idempotent terminate');

$base = idcnat_baseUrl(array('serverhostname' => 'node.example.test', 'serversecure' => true, 'serverport' => 9443));
expect($base === 'https://node.example.test:9443', 'HTTPS server URL');
$ipv6 = idcnat_baseUrl(array('serverhostname' => '2001:db8::1', 'serverport' => 9180));
expect($ipv6 === 'http://[2001:db8::1]:9180', 'IPv6 server URL');

echo "WHMCS panel bridge integration passed\n";
