<?php
$root = dirname(__DIR__, 2);
spl_autoload_register(function ($class) use ($root) {
    if (strpos($class, 'app\\') !== 0) return;
    $file = $root . '/src/core/application/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require_once $file;
});
$base = 'http://127.0.0.1:18995';
$cfg = array(
    'Dnspod'=>array('secret_id'=>'AKIDtest123','secret_key'=>'SKtest456'),
    'Aliyun'=>array('access_key_id'=>'AKIDtest123','access_key_secret'=>'SKtest456'),
    'Volcengine'=>array('access_key_id'=>'AKIDtest123','secret_access_key'=>'SKtest456'),
    'Jdcloud'=>array('access_key'=>'AKIDtest123','secret_key'=>'SKtest456'),
    'Huawei'=>array('access_key'=>'AKIDtest123','secret_key'=>'SKtest456'),
    'Baidu'=>array('access_key'=>'AKIDtest123','secret_key'=>'SKtest456'),
);
foreach ($cfg as $class => $c) {
    $code = strtolower($class); $c['endpoint'] = $base . '/' . $code; $c['_fixed_time'] = 1700000000;
    $driverClass = 'app\\common\\dns\\' . $class;
    $d = new $driverClass($c);
    $id = '1';
    $d->getDomains();
    $d->getRecords('example.com', $id, 'abc');
    $a = $d->addRecord('example.com', $id, 'abc', 'A', '1.2.3.4', 'default', 600, 10);
    $d->addRecord('example.com', $id, 'abc', 'TXT', 'hello world', 'default', 600, 10);
    $d->updateRecord('example.com', $id, $a === '' ? '101' : $a, 'abc', 'A', '5.6.7.8', 'default', 600, 10);
    $d->deleteRecord('example.com', $id, $a === '' ? '101' : $a);
}
echo "captured\n";
