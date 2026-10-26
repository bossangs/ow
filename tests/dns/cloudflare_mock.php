<?php
/* Offline Cloudflare v4 mock. Deliberately independent of Cloudflare driver. */
function cfout($body, $status = 200) { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function ok($result, $info = array()) { return array('success'=>true,'errors'=>array(),'messages'=>array(),'result'=>$result,'result_info'=>$info); }
function err($code, $message, $status = 400) { cfout(array('success'=>false,'errors'=>array(array('code'=>$code,'message'=>$message,'error_chain'=>array())),'messages'=>array(),'result'=>null), $status); }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input'); $body = json_decode($raw, true); if (!is_array($body)) $body = array();
$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$token = isset($_SERVER['HTTP_X_TEST_TOKEN']) ? $_SERVER['HTTP_X_TEST_TOKEN'] : '';
/* Auth is checked natively by endpoint: token and global-key variants. */
if ($path === '/user/tokens/verify') {
    if ($auth !== 'Bearer TOKENEXAMPLE' && $auth !== 'Bearer INACTIVE') err(10000, 'Authentication error');
    cfout(ok(array('status'=>$auth === 'Bearer INACTIVE' ? 'inactive' : 'active'))); 
}
if ($path === '/user') {
    if (isset($_SERVER['HTTP_X_AUTH_EMAIL']) && isset($_SERVER['HTTP_X_AUTH_KEY']) && $_SERVER['HTTP_X_AUTH_EMAIL'] === 'user@example.com' && $_SERVER['HTTP_X_AUTH_KEY'] === 'GLOBALKEY') cfout(ok(array('id'=>'u1','email'=>'user@example.com')));
    err(10000, 'Authentication error');
}
if (strpos($path, '/error/') === 0) {
    $code = basename($path);
    if ($code === 'zones' && isset($_GET['name']) && strpos($_GET['name'], 'contains:') === 0) $code = substr($_GET['name'], 9);
    $status = $code === '429' ? 429 : 400; err((int)$code, 'mock error', $status);
}
if (strpos($path, '/zones/') === 0 && strpos($path, '/dns_records') !== false) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($method === 'GET') {
        $rows = $page === 1 ? array(
            array('id'=>'r-a','name'=>'Example.COM.','type'=>'A','content'=>'1.2.3.4','ttl'=>120,'proxied'=>true),
            array('id'=>'r-mx','name'=>'mail.example.com.','type'=>'MX','content'=>'mx.example.net.','priority'=>20,'ttl'=>300,'proxied'=>false)
        ) : array(array('id'=>'r-srv','name'=>'_sip._tcp.example.com.','type'=>'SRV','data'=>array('weight'=>5,'priority'=>10,'port'=>5060,'target'=>'sip.example.com.'),'ttl'=>600,'proxied'=>false));
        cfout(ok($rows, array('page'=>$page,'per_page'=>100,'total_pages'=>2,'count'=>count($rows),'total_count'=>3)));
    }
    $recordId = basename($path);
    if ($method === 'DELETE' && $recordId === 'gone81044') err(81044, 'Record does not exist');
    if ($method === 'DELETE' && $recordId === 'gone404') err(1000, 'Not found', 404);
    if ($method === 'DELETE' && $recordId === 'baddelete') err(81057, 'Record already exists');
    if ($method === 'POST' || $method === 'PUT') {
        if (!isset($body['type']) || !isset($body['name']) || !isset($body['ttl'])) err(9005, 'Invalid record');
        if ($method === 'POST' && $body['type'] === 'A' && $body['name'] === 'a.example.com' && (!isset($body['proxied']) || $body['proxied'] !== true || $body['ttl'] !== 1)) err(9005, 'A proxy body mismatch');
        if ($body['type'] === 'A' && $body['name'] === 'plain.example.com' && (!isset($body['proxied']) || $body['proxied'] !== false || $body['ttl'] !== 60)) err(9005, 'A plain body mismatch');
        if ($body['type'] === 'TXT' && isset($body['proxied'])) err(9005, 'TXT must not contain proxied');
        if ($body['type'] === 'MX' && (!isset($body['priority']) || $body['priority'] !== 15)) err(9005, 'MX priority mismatch');
        if ($body['type'] === 'SRV' && (!isset($body['data']) || !is_array($body['data']))) err(9005, 'SRV data mismatch');
        if ($body['type'] === 'CAA' && (!isset($body['data']) || $body['data']['flags'] !== 0 || $body['data']['tag'] !== 'issue' || $body['data']['value'] !== 'letsencrypt.org')) err(9005, 'CAA data mismatch');
        cfout(ok(array('id'=>$method === 'PUT' ? 'updated' : 'new-record')));
    }
    if ($method === 'DELETE') cfout(ok(array('id'=>'deleted')));
}
if ($path === '/zones' && $method === 'GET') {
    if (isset($_GET['name']) && $_GET['name'] !== 'contains:example') err(9005, 'keyword mismatch');
    if (isset($_GET['account.id']) && $_GET['account.id'] !== 'acc1') err(9005, 'account mismatch');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $rows = $page === 1 ? array(array('id'=>'z1','name'=>'Example.COM','status'=>'active')) : array(array('id'=>'z2','name'=>'SECOND.COM','status'=>'pending'));
    cfout(ok($rows, array('page'=>$page,'per_page'=>50,'total_pages'=>2,'count'=>1,'total_count'=>2)));
}
err(7003, 'Unsupported mock path');
