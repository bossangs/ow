<?php
/*
 * 有状态 Cloudflare v4 mock，仅用于本地端到端流程测试（不连接真实 Cloudflare）。
 * 启动：php -S 127.0.0.1:18992 tests/e2e/mock_cf_state.php
 * 令牌：Bearer E2ETOKEN（平台账户）、Bearer SELLERTOKEN（市场卖家账户）
 * 状态文件：环境变量 CF_STATE，默认 /tmp/idcdns_cf_state.json
 * 测试辅助：GET /__state 查看全部记录；POST /__seed {"token":..,"zone":..,"records":[..]} 预置记录
 */
$stateFile = getenv('CF_STATE') ?: '/tmp/idcdns_cf_state.json';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/client/v4#', '', $path);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

function out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ok($result, $info = null)
{
    $r = ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];
    if ($info !== null) {
        $r['result_info'] = $info;
    }
    out($r);
}
function err($code, $msg, $status = 400)
{
    out(['success' => false, 'errors' => [['code' => $code, 'message' => $msg]], 'messages' => [], 'result' => null], $status);
}

$fp = fopen($stateFile . '.lock', 'c');
flock($fp, LOCK_EX);
$s = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
if (!$s) {
    $s = [
        'accounts' => [
            'E2ETOKEN' => ['zones' => ['z-plat' => 'e2e-plat.test']],
            'SELLERTOKEN' => ['zones' => ['z-sell' => 'seller-shop.test']],
        ],
        'records' => [
            'z-plat' => [
                ['id' => 'p-www', 'type' => 'A', 'name' => 'www.e2e-plat.test', 'content' => '198.51.100.10', 'ttl' => 600, 'proxied' => false],
            ],
            'z-sell' => [
                ['id' => 's-www', 'type' => 'A', 'name' => 'www.seller-shop.test', 'content' => '198.51.100.20', 'ttl' => 600, 'proxied' => false],
                ['id' => 's-mail', 'type' => 'MX', 'name' => 'seller-shop.test', 'content' => 'mail.seller-shop.test', 'priority' => 10, 'ttl' => 600, 'proxied' => false],
            ],
        ],
        'seq' => 1000,
        'log' => [],
    ];
}
function save()
{
    global $s, $stateFile;
    file_put_contents($stateFile, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($path === '/__state') {
    out($s);
}
if ($path === '/__seed' && $method === 'POST') {
    $zone = $body['zone'];
    foreach ($body['records'] as $r) {
        $r['id'] = 'seed' . (++$s['seq']);
        $s['records'][$zone][] = $r + ['ttl' => 600, 'proxied' => false];
    }
    save();
    ok(true);
}

$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$token = strpos($auth, 'Bearer ') === 0 ? substr($auth, 7) : '';
if (!isset($s['accounts'][$token])) {
    err(10000, 'Authentication error', 403);
}
$zones = $s['accounts'][$token]['zones'];
$s['log'][] = $method . ' ' . $path;
if (count($s['log']) > 300) {
    array_shift($s['log']);
}

if ($path === '/user/tokens/verify') {
    save();
    ok(['id' => 'tok', 'status' => 'active']);
}
if ($path === '/user') {
    err(9109, 'Unauthorized to access requested resource', 403);
}
if ($path === '/zones' && $method === 'GET') {
    $list = [];
    foreach ($zones as $id => $name) {
        if (!empty($_GET['name']) && $_GET['name'] !== $name) {
            continue;
        }
        $list[] = ['id' => $id, 'name' => $name, 'status' => 'active', 'account' => ['id' => 'acc-' . substr(md5($token), 0, 6)]];
    }
    save();
    ok($list, ['page' => 1, 'per_page' => 50, 'total_pages' => 1, 'count' => count($list), 'total_count' => count($list)]);
}
if (preg_match('#^/zones/([^/]+)$#', $path, $m)) {
    if (!isset($zones[$m[1]])) {
        err(1001, 'Invalid zone identifier', 404);
    }
    ok(['id' => $m[1], 'name' => $zones[$m[1]], 'status' => 'active']);
}
if (preg_match('#^/zones/([^/]+)/dns_records(?:/([^/]+))?$#', $path, $m)) {
    $zid = $m[1];
    $rid = isset($m[2]) ? $m[2] : '';
    if (!isset($zones[$zid])) {
        err(1001, 'Invalid zone identifier', 404);
    }
    $zoneName = $zones[$zid];
    if (!isset($s['records'][$zid])) {
        $s['records'][$zid] = [];
    }
    $recs = &$s['records'][$zid];
    $fqdn = function ($n) use ($zoneName) {
        $n = strtolower(rtrim((string)$n, '.'));
        if ($n === '@' || $n === '') {
            return $zoneName;
        }

        return ($n === $zoneName || substr($n, -strlen('.' . $zoneName)) === '.' . $zoneName) ? $n : $n . '.' . $zoneName;
    };
    if ($method === 'GET' && $rid === '') {
        $list = [];
        foreach ($recs as $r) {
            if (!empty($_GET['name']) && $fqdn($_GET['name']) !== $r['name']) {
                continue;
            }
            if (!empty($_GET['type']) && strtoupper($_GET['type']) !== $r['type']) {
                continue;
            }
            $list[] = $r;
        }
        $per = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 100;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $pages = max(1, (int)ceil(count($list) / $per));
        save();
        ok(array_values(array_slice($list, ($page - 1) * $per, $per)), ['page' => $page, 'per_page' => $per, 'total_pages' => $pages, 'count' => count($list), 'total_count' => count($list)]);
    }
    $find = function ($rid) use (&$recs) {
        foreach ($recs as $i => $r) {
            if ($r['id'] === $rid) {
                return $i;
            }
        }

        return -1;
    };
    if ($method === 'POST' && $rid === '') {
        foreach (['type', 'name', 'content'] as $k) {
            if (!isset($body[$k]) || $body[$k] === '') {
                err(9005, 'Missing ' . $k);
            }
        }
        $name = $fqdn($body['name']);
        foreach ($recs as $r) {
            if ($r['name'] === $name && $r['type'] === strtoupper($body['type']) && $r['content'] === $body['content']) {
                err(81057, 'Record already exists.');
            }
            if ($r['name'] === $name && ($r['type'] === 'CNAME' || strtoupper($body['type']) === 'CNAME')) {
                err(81053, 'An A, AAAA, or CNAME record with that host already exists.');
            }
        }
        $rec = [
            'id' => 'r' . (++$s['seq']), 'type' => strtoupper($body['type']), 'name' => $name, 'content' => (string)$body['content'],
            'ttl' => isset($body['ttl']) ? (int)$body['ttl'] : 1, 'proxied' => !empty($body['proxied']),
        ];
        if (isset($body['priority'])) {
            $rec['priority'] = (int)$body['priority'];
        }
        $recs[] = $rec;
        save();
        ok($rec);
    }
    if ($rid !== '') {
        $i = $find($rid);
        if ($i < 0) {
            err(81044, 'Record does not exist.', 404);
        }
        if ($method === 'GET') {
            ok($recs[$i]);
        }
        if ($method === 'DELETE') {
            array_splice($recs, $i, 1);
            save();
            ok(['id' => $rid]);
        }
        if ($method === 'PUT' || $method === 'PATCH') {
            foreach (['type', 'content', 'ttl', 'proxied', 'priority'] as $k) {
                if (array_key_exists($k, $body)) {
                    $recs[$i][$k] = $k === 'type' ? strtoupper($body[$k]) : $body[$k];
                }
            }
            if (isset($body['name'])) {
                $recs[$i]['name'] = $fqdn($body['name']);
            }
            save();
            ok($recs[$i]);
        }
    }
}
err(7000, 'No route for that URI', 400);
