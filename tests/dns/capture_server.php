<?php
/* Offline request recorder. It never contacts a vendor. */
$line = array(
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => function_exists('getallheaders') ? getallheaders() : array(),
    'body' => file_get_contents('php://input'),
);
file_put_contents('/tmp/dns_capture.jsonl', json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$prefix = trim(explode('/', trim($path, '/'))[0], '/');
$query = array(); parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?: '', $query);
$body = json_decode($line['body'], true); if (!is_array($body)) $body = array();
function reply($x) { header('Content-Type: application/json'); echo json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
$next = 101;
if ($prefix === 'dnspod') {
    $a = isset($_SERVER['HTTP_X_TC_ACTION']) ? $_SERVER['HTTP_X_TC_ACTION'] : '';
    if ($a === 'DescribeDomainList') return reply(array('Response'=>array('DomainList'=>array(array('DomainId'=>1,'Name'=>'example.com','RecordCount'=>0)),'DomainCountInfo'=>array('AllTotal'=>1),'RequestId'=>'x')));
    if ($a === 'DescribeRecordList') return reply(array('Response'=>array('RecordList'=>array(),'RequestId'=>'x')));
    if ($a === 'CreateRecord') return reply(array('Response'=>array('RecordId'=>$next++,'RequestId'=>'x')));
    return reply(array('Response'=>array('RequestId'=>'x')));
}
if ($prefix === 'aliyun') {
    $a = isset($query['Action']) ? $query['Action'] : '';
    if ($a === 'DescribeDomains') return reply(array('Domains'=>array('Domain'=>array(array('DomainId'=>'1','DomainName'=>'example.com','RecordCount'=>0)))));
    if ($a === 'DescribeDomainRecords') return reply(array('DomainRecords'=>array('Record'=>array())));
    if ($a === 'AddDomainRecord') return reply(array('RecordId'=>(string)$next++));
    return reply(array('RequestId'=>'x'));
}
if ($prefix === 'volcengine') {
    $a = isset($query['Action']) ? $query['Action'] : '';
    if ($a === 'ListZones') return reply(array('Result'=>array('Zones'=>array(array('ZID'=>'1','Name'=>'example.com')))));
    if ($a === 'ListRecords') return reply(array('Result'=>array('Records'=>array())));
    if ($a === 'CreateRecord') return reply(array('Result'=>array('RecordID'=>(string)$next++)));
    return reply(array('Result'=>array()));
}
if ($prefix === 'jdcloud') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && substr($path, -7) === '/domain') return reply(array('result'=>array('domainList'=>array(array('domainId'=>'1','domainName'=>'example.com')))));
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return reply(array('result'=>array('resourceRecordList'=>array())));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') return reply(array('result'=>array('resourceRecordId'=>(string)$next++)));
    return reply(array('result'=>array()));
}
if ($prefix === 'huawei') {
    if (strpos($path, '/recordsets') !== false) return reply(array('recordsets'=>array()));
    return reply(array('zones'=>array(array('id'=>'1','name'=>'example.com.','record_num'=>0))));
}
if ($prefix === 'baidu') {
    if (strpos($path, '/record') !== false) return reply(array('id'=>(string)$next++,'records'=>array(),'result'=>array()));
    return reply(array('zones'=>array(array('name'=>'example.com.')),'nextMarker'=>''));
}
reply(array('Response'=>array('RequestId'=>'x')));
