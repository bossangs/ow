<?php

/**
 * 全局公共函数
 */
use think\Db;

if (!function_exists('idc_conf')) {
    /** 读取数据目录 config.php 中的框架级配置 */
    function idc_conf($key, $default = null)
    {
        $c = isset($GLOBALS['IDC_CONFIG']) ? $GLOBALS['IDC_CONFIG'] : [];

        return isset($c[$key]) ? $c[$key] : $default;
    }
}

/**
 * 读取后台系统设置（数据库 setting 表），带进程内缓存 + 文件缓存
 */
function setting($name = null, $default = '')
{
    static $all = null;
    if ($name === false) { // 清除缓存
        $all = null;
        \think\facade\Cache::rm('idc_settings');

        return true;
    }
    if ($all === null) {
        $all = \think\facade\Cache::get('idc_settings');
        if (!is_array($all)) {
            $all = [];
            try {
                foreach (Db::name('setting')->select() as $row) {
                    $all[$row['name']] = $row['value'];
                }
            } catch (\Exception $e) {
                $all = [];
            }
            \think\facade\Cache::set('idc_settings', $all, 3600);
        }
    }
    if ($name === null) {
        return $all;
    }

    if (array_key_exists($name, $all)) {
        return $all[$name];
    }
    // 未保存过的设置：使用统一默认值表，保证各处读取一致
    static $defaults = null;
    if ($defaults === null) {
        $defaults = \app\common\lib\SettingDefaults::all();
    }

    return array_key_exists($name, $defaults) ? $defaults[$name] : $default;
}

/** 批量保存设置 */
function setting_save(array $data)
{
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $v = implode(',', $v);
        }
        $exists = Db::name('setting')->where('name', $k)->count();
        if ($exists) {
            Db::name('setting')->where('name', $k)->update(['value' => (string)$v]);
        } else {
            Db::name('setting')->insert(['name' => $k, 'value' => (string)$v]);
        }
    }
    setting(false);
}

/** 站点根路径（含子目录），如 /idc */
function base_path()
{
    static $b = null;
    if ($b === null) {
        if (IDC_CLI) {
            $u = rtrim(setting('site_url', ''), '/');
            $p = parse_url($u, PHP_URL_PATH);
            $b = $p ? rtrim($p, '/') : '';
        } else {
            $s = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
            $b = rtrim(str_replace('\\', '/', dirname($s)), '/');
        }
    }

    return $b;
}

/** 站点完整根地址 https://host/sub */
function site_url()
{
    $cfg = rtrim(setting('site_url', ''), '/');
    if ($cfg !== '') {
        return $cfg;
    }
    if (IDC_CLI) {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    return ($https ? 'https://' : 'http://') . $host . base_path();
}

/**
 * 生成站内 URL
 * @param string $path 模块/控制器/方法，如 index/user/index
 * @param array $params 查询参数
 * @param bool $full 是否带域名
 * 根据后台“网址模式”设置：compat => index.php?s=/a/b/c ； pathinfo => index.php/a/b/c ； rewrite => /a/b/c
 */
function url_to($path = '', array $params = [], $full = false)
{
    $path = trim($path, '/');
    $mode = setting('url_mode', 'compat');
    $base = $full ? site_url() : base_path();
    if ($path === '' || $path === 'index/index/index') {
        $url = $base . '/' . ($mode === 'rewrite' ? '' : 'index.php');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }
    if ($mode === 'rewrite') {
        $url = $base . '/' . $path;
        $q = $params ? '?' . http_build_query($params) : '';
    } elseif ($mode === 'pathinfo') {
        $url = $base . '/index.php/' . $path;
        $q = $params ? '?' . http_build_query($params) : '';
    } else {
        $url = $base . '/index.php?s=/' . $path;
        $q = $params ? '&' . http_build_query($params) : '';
    }

    return $url . $q;
}

/** 静态资源地址 */
function static_url($file)
{
    $file = ltrim($file, '/');
    $mt = @filemtime(WEB_ROOT . 'static/' . $file);
    return base_path() . '/static/' . $file . '?v=' . IDC_VERSION . ($mt ? '.' . $mt : '');
}

/** 金额格式化 */
function money($v, $symbol = true)
{
    $s = number_format((float)$v, 2, '.', '');

    return $symbol ? setting('currency_symbol', '¥') . $s : $s;
}

/** HTML 转义 */
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function random_str($len = 16, $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789')
{
    $s = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        $s .= $chars[random_int(0, $max)];
    }

    return $s;
}

/** 客户端 IP（可在后台设置是否信任反代头） */
function client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $hdr = setting('trust_proxy_header', '');
    if ($hdr !== '') {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $hdr));
        if (!empty($_SERVER[$key])) {
            $first = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
    }

    return $ip;
}

/** CSRF */
function csrf_token()
{
    $t = session('csrf_token');
    if (!$t) {
        $t = bin2hex(random_bytes(16));
        session('csrf_token', $t);
    }

    return $t;
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . csrf_token() . '">';
}

function csrf_check()
{
    $t = isset($_POST['_token']) ? $_POST['_token'] : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
    $s = session('csrf_token');

    return is_string($t) && $s && hash_equals($s, $t);
}

/** 对称加密（AES-256-CBC + HMAC），密钥来自数据目录配置 app_key */
function encrypt_str($plain)
{
    if ($plain === '' || $plain === null) {
        return '';
    }
    $key = hash('sha256', idc_conf('app_key', 'idc'), true);
    $iv = random_bytes(16);
    $c = openssl_encrypt((string)$plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $mac = hash_hmac('sha256', $iv . $c, $key, true);

    return 'enc:' . base64_encode($iv . $mac . $c);
}

function decrypt_str($data)
{
    if (!is_string($data) || $data === '') {
        return '';
    }
    if (strpos($data, 'enc:') !== 0) {
        return $data;
    }
    $raw = base64_decode(substr($data, 4));
    if ($raw === false || strlen($raw) < 48) {
        return '';
    }
    $key = hash('sha256', idc_conf('app_key', 'idc'), true);
    $iv = substr($raw, 0, 16);
    $mac = substr($raw, 16, 32);
    $c = substr($raw, 48);
    if (!hash_equals(hash_hmac('sha256', $iv . $c, $key, true), $mac)) {
        return '';
    }
    $p = openssl_decrypt($c, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    return $p === false ? '' : $p;
}

/** 加密保存 JSON 配置 */
function encrypt_json(array $arr)
{
    return encrypt_str(json_encode($arr, JSON_UNESCAPED_UNICODE));
}

function decrypt_json($data)
{
    $d = json_decode(decrypt_str($data), true);

    return is_array($d) ? $d : [];
}

/**
 * 写审计日志
 * @param string $action 动作，如 dns.record.add
 * @param string $target 目标描述
 * @param mixed $detail 详情（数组将转 JSON）
 * @param string $level info|warn|danger
 */
function audit($action, $target = '', $detail = '', $level = 'info', $actorType = null, $actorId = null, $actorName = null)
{
    if ($actorType === null) {
        $aid = session('admin_id');
        $uid = session('user_id');
        if (\think\facade\Request::module() === 'admin' && $aid) {
            $actorType = 'admin';
            $actorId = $aid;
            $actorName = session('admin_name');
        } elseif ($uid) {
            $actorType = 'user';
            $actorId = $uid;
            $actorName = session('user_name');
        } else {
            $actorType = IDC_CLI ? 'system' : 'guest';
            $actorId = 0;
            $actorName = IDC_CLI ? 'cron' : '';
        }
    }
    try {
        Db::name('audit_log')->insert([
            'actor_type' => $actorType,
            'actor_id' => (int)$actorId,
            'actor_name' => mb_substr((string)$actorName, 0, 64),
            'action' => $action,
            'target' => mb_substr((string)$target, 0, 190),
            'detail' => is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : (string)$detail,
            'ip' => IDC_CLI ? '127.0.0.1' : client_ip(),
            'ua' => IDC_CLI ? 'cli' : mb_substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 250),
            'level' => $level,
            'create_time' => time(),
        ]);
    } catch (\Exception $e) {
    }
}

/** 计费周期 */
function billing_cycles()
{
    return [
        'monthly' => ['name' => '月付', 'months' => 1],
        'quarterly' => ['name' => '季付', 'months' => 3],
        'semiannually' => ['name' => '半年付', 'months' => 6],
        'annually' => ['name' => '年付', 'months' => 12],
        'biennially' => ['name' => '两年付', 'months' => 24],
        'triennially' => ['name' => '三年付', 'months' => 36],
    ];
}

function cycle_name($c)
{
    $all = billing_cycles();

    return isset($all[$c]) ? $all[$c]['name'] : $c;
}

/** 在时间戳基础上增加周期 */
function add_cycle($ts, $cycle)
{
    $all = billing_cycles();
    $m = isset($all[$cycle]) ? $all[$cycle]['months'] : 1;

    return strtotime('+' . $m . ' month', $ts);
}

function fmt_time($ts, $fmt = 'Y-m-d H:i')
{
    return $ts ? date($fmt, $ts) : '-';
}

/**
 * 状态中文名与徽标颜色。$ctx 区分同一英文码在不同业务里的含义：
 * order 订单 / service 云主机 / deliver 交付 / withdraw 提现 / cancel 退订申请 /
 * coupon 优惠码使用 / listing 市场上架 / ticket 工单 / subdomain 二级域名 / hold 库存预留
 */
function status_meta($status, $ctx = '')
{
    $status = (string)$status;
    $ctxMap = [
        'order' => [
            'unpaid' => ['warning', '待支付'], 'pending' => ['warning', '待支付'], 'paid' => ['primary', '已支付'],
            'cancelled' => ['secondary', '已取消'], 'refunded' => ['info', '已退款'], 'partial_refunded' => ['info', '部分退款'],
            'closed' => ['secondary', '已关闭'], 'expired' => ['secondary', '已过期'], 'failed' => ['danger', '支付失败'],
        ],
        'deliver' => [
            'done' => ['success', '已开通'], 'success' => ['success', '已开通'], 'pending' => ['warning', '待开通'],
            'processing' => ['info', '开通中'], 'failed' => ['danger', '开通失败'], '' => ['light text-dark', '—'],
        ],
        'service' => [
            'pending' => ['warning', '待开通'], 'active' => ['success', '运行中'], 'suspended' => ['secondary', '已暂停'],
            'terminated' => ['dark', '已删除'], 'failed' => ['danger', '开通失败'], 'cancelled' => ['secondary', '已取消'],
            'expired' => ['danger', '已到期'],
        ],
        'withdraw' => [
            'pending' => ['warning', '待审核'], 'approved' => ['info', '已通过'], 'paid' => ['success', '已打款'],
            'rejected' => ['danger', '已驳回'], 'cancelled' => ['secondary', '已取消'],
        ],
        'cancel' => [
            'pending' => ['warning', '待处理'], 'approved' => ['success', '已同意'], 'rejected' => ['danger', '已拒绝'],
            'done' => ['success', '已完成'], 'cancelled' => ['secondary', '已撤回'],
        ],
        'coupon' => [
            'reserved' => ['warning', '已锁定'], 'used' => ['success', '已使用'], 'released' => ['secondary', '已释放'],
            'unused' => ['primary', '未使用'], 'expired' => ['secondary', '已过期'], 'disabled' => ['secondary', '已停用'],
        ],
        'listing' => [
            'pending' => ['warning', '待审核'], 'verifying' => ['warning', '待验证'], 'active' => ['success', '已上架'],
            'paused' => ['secondary', '已暂停'], 'rejected' => ['danger', '已驳回'], 'withdrawn' => ['dark', '已下架'],
        ],
        'ticket' => [
            'open' => ['primary', '待处理'], 'answered' => ['success', '已回复'], 'replied' => ['warning', '用户回复'],
            'waiting' => ['info', '等待用户'], 'closed' => ['secondary', '已关闭'],
        ],
        'subdomain' => [
            'active' => ['success', '正常'], 'pending' => ['warning', '待支付'], 'suspended' => ['secondary', '已暂停'],
            'expired' => ['danger', '已过期'], 'disabled' => ['secondary', '已停用'], 'deleted' => ['dark', '已删除'],
        ],
        'record' => [
            'active' => ['success', '正常'], 'paused' => ['secondary', '已暂停'], 'pending' => ['warning', '同步中'],
            'failed' => ['danger', '同步失败'], 'protected' => ['dark', '受保护'], 'disabled' => ['secondary', '已停用'],
        ],
        'hold' => [
            'reserved' => ['warning', '已预留'], 'committed' => ['success', '已占用'], 'released' => ['secondary', '已释放'],
        ],
    ];
    if ($ctx !== '' && isset($ctxMap[$ctx][$status])) {
        return $ctxMap[$ctx][$status];
    }
    $map = [
        'active' => ['success', '运行中'], 'pending' => ['warning', '待处理'], 'suspended' => ['secondary', '已暂停'],
        'terminated' => ['dark', '已删除'], 'failed' => ['danger', '失败'], 'cancelled' => ['secondary', '已取消'],
        'unpaid' => ['warning', '待支付'], 'paid' => ['success', '已支付'], 'refunded' => ['info', '已退款'],
        'rejected' => ['danger', '已驳回'], 'expired' => ['secondary', '已过期'], 'review' => ['warning', '待审核'],
        'open' => ['primary', '待处理'], 'answered' => ['success', '已回复'], 'replied' => ['warning', '用户回复'], 'closed' => ['secondary', '已关闭'],
        'enable' => ['success', '正常'], 'disable' => ['secondary', '停用'], 'disabled' => ['secondary', '已停用'],
        'approved' => ['success', '已通过'], 'done' => ['success', '已完成'], 'success' => ['success', '成功'],
        'used' => ['success', '已使用'], 'unused' => ['primary', '未使用'], 'reserved' => ['warning', '已预留'],
        'released' => ['secondary', '已释放'], 'committed' => ['success', '已占用'], 'paused' => ['secondary', '已暂停'],
        'withdrawn' => ['dark', '已下架'], 'processing' => ['info', '处理中'], '1' => ['success', '正常'], '0' => ['secondary', '停用'],
    ];

    return isset($map[$status]) ? $map[$status] : ['light text-dark', $status === '' ? '—' : $status];
}
function status_label($status, $ctx = '')
{
    $m = status_meta($status, $ctx);

    return $m[1];
}
/** 状态徽标（中文 + 颜色） */
function status_badge($status, $ctx = '')
{
    $m = status_meta($status, $ctx);

    return '<span class="badge bg-' . $m[0] . '" title="' . h((string)$status) . '">' . h($m[1]) . '</span>';
}
/** 订单综合状态：已支付且交付完成显示「已开通」，交付失败显示「开通失败」 */
function order_status_badge(array $order)
{
    $st = isset($order['status']) ? (string)$order['status'] : '';
    $dl = isset($order['deliver_status']) ? (string)$order['deliver_status'] : '';
    if ($st === 'paid' && $dl !== '') {
        if ($dl === 'done' || $dl === 'success') {
            $type = isset($order['type']) ? (string)$order['type'] : '';
            $text = in_array($type, ['recharge', 'tier', 'dns_sub_renew', 'host_renew', 'host_upgrade', 'dns_package'], true) ? '已完成' : '已开通';
            return '<span class="badge bg-success" title="paid/' . h($dl) . '">' . $text . '</span>';
        }
        return status_badge($dl, 'deliver');
    }

    return status_badge($st, 'order');
}
/** 订单类型中文名 */
function order_type_name($type)
{
    $map = [
        'host_new' => '新购云主机', 'host_renew' => '云主机续费', 'host_upgrade' => '云主机升降配',
        'dns_sub_new' => '注册二级域名', 'dns_sub_renew' => '二级域名续费', 'dns_package' => 'DNS 套餐',
        'recharge' => '余额充值', 'tier' => '会员升级', 'market_buy' => '域名市场购买',
    ];

    return isset($map[$type]) ? $map[$type] : (string)$type;
}
/** 余额流水类型中文名 */
function balance_type_name($type)
{
    $map = [
        'recharge' => '充值', 'card' => '卡密充值', 'pay' => '消费', 'refund' => '退款', 'sale_income' => '市场收入',
        'withdraw' => '提现', 'withdraw_reject' => '提现退回', 'admin_add' => '后台加款', 'admin_deduct' => '后台扣款',
        'commission' => '佣金', 'gift' => '赠送',
    ];

    return isset($map[$type]) ? $map[$type] : (string)$type;
}
function order_no($prefix = '')
{
    return $prefix . date('YmdHis') . random_int(1000, 9999);
}

/** 分页 HTML（Bootstrap） */
function pager_html($total, $page, $size, array $query = [], $path = '')
{
    $pages = max(1, (int)ceil($total / max(1, $size)));
    if ($pages <= 1) {
        return '<div class="text-muted small">共 ' . (int)$total . ' 条</div>';
    }
    $path = $path ?: (\think\facade\Request::module() . '/' . strtolower(\think\facade\Request::controller()) . '/' . \think\facade\Request::action());
    $html = '<nav class="d-flex align-items-center flex-wrap gap-2"><span class="text-muted small me-2">共 ' . (int)$total . ' 条</span><ul class="pagination pagination-sm mb-0 flex-wrap">';
    $start = max(1, $page - 3);
    $end = min($pages, $page + 3);
    $mk = function ($p, $label, $active = false, $disabled = false) use ($query, $path) {
        $q = $query;
        $q['page'] = $p;
        $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');

        return '<li class="' . $cls . '"><a class="page-link" href="' . h(url_to($path, $q)) . '">' . $label . '</a></li>';
    };
    $html .= $mk(max(1, $page - 1), '&laquo;', false, $page <= 1);
    if ($start > 1) {
        $html .= $mk(1, '1');
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $mk($i, (string)$i, $i == $page);
    }
    if ($end < $pages) {
        $html .= $mk($pages, (string)$pages);
    }
    $html .= $mk(min($pages, $page + 1), '&raquo;', false, $page >= $pages);

    return $html . '</ul></nav>';
}

/** 读取 JSON 字段 */
function json_arr($s)
{
    if (is_array($s)) {
        return $s;
    }
    $d = json_decode((string)$s, true);

    return is_array($d) ? $d : [];
}

/** 掩码显示密钥 */
function mask_secret($s)
{
    $s = (string)$s;
    if ($s === '') {
        return '';
    }
    $l = strlen($s);

    return $l <= 6 ? str_repeat('*', $l) : substr($s, 0, 3) . str_repeat('*', min(12, $l - 6)) . substr($s, -3);
}

/** 图形验证码是否开启（后台「系统设置 → 安全」可关闭） */
function captcha_enabled()
{
    return setting('captcha_enable', '1') === '1';
}

/** root 密码强度校验：返回错误信息，合格返回空字符串 */
function root_password_error($pw)
{
    $pw = (string)$pw;
    $len = strlen($pw);
    if ($len < 8 || $len > 32) {
        return 'root 密码长度需为 8-32 位';
    }
    if (preg_match('/[\s\'"`\\\\]/', $pw)) {
        return 'root 密码不能包含空格、引号或反斜杠';
    }
    $classes = 0;
    foreach (array('/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^A-Za-z0-9]/') as $re) {
        $classes += preg_match($re, $pw) ? 1 : 0;
    }
    if ($classes < 3) {
        return 'root 密码需包含大写字母、小写字母、数字、符号中的至少三类';
    }

    return '';
}

/** 交付说明里的英文状态词翻成中文（如「服务 #1 active」→「服务 #1 运行中」） */
function deliver_msg_text($msg)
{
    return preg_replace_callback('/\b(active|pending|failed|suspended|terminated|cancelled|expired|done)\b/', function ($m) {
        return status_label($m[1], 'service') === $m[1] ? status_label($m[1]) : status_label($m[1], 'service');
    }, (string)$msg);
}

/**
 * 站点 Logo 地址。后台「系统设置 → 基本 → 站点 Logo」上传的优先，否则用内置 o2o云 Logo。
 *
 * @param bool $light    深色背景用反白版（后台侧栏）；未上传反白版时回退到普通 Logo
 * @param bool $large    大图（登录页/邮件，128px 高）；否则 64px 高（页头 32px 显示的 2x 图）
 * @param bool $absolute 返回绝对地址（邮件用）；无法确定站点地址时返回空串
 */
function brand_logo_url($light = false, $large = false, $absolute = false)
{
    $custom = '';
    if ($light) {
        $custom = (string)setting('site_logo_light', '');
    }
    if ($custom === '') {
        $custom = (string)setting('site_logo', '');
    }
    if ($custom !== '' && preg_match('#^uploads/brand/[A-Za-z0-9_.-]+$#', $custom)) {
        $path = $custom;
    } else {
        $path = 'static/brand/logo' . ($light ? '-light' : '') . ($large ? '@2x' : '') . '.png';
    }
    if ($absolute) {
        $base = site_url();

        return $base === '' ? '' : $base . '/' . $path;
    }

    return base_path() . '/' . $path . (strpos($path, 'static/') === 0 ? '?v=' . IDC_VERSION : '');
}

/** 网站图标地址（favicon），后台上传的优先 */
function brand_favicon_url()
{
    $custom = (string)setting('site_favicon', '');
    if ($custom !== '' && preg_match('#^uploads/brand/[A-Za-z0-9_.-]+$#', $custom)) {
        return base_path() . '/' . $custom;
    }

    return static_url('brand/favicon.ico');
}

/** 所有页面 <head> 里的图标标签：favicon + PNG 图标 + apple-touch-icon */
function brand_head_tags()
{
    $custom = (string)setting('site_favicon', '');
    if ($custom !== '' && preg_match('#^uploads/brand/[A-Za-z0-9_.-]+$#', $custom)) {
        $url = h(base_path() . '/' . $custom);
        $tags = '<link rel="icon" href="' . $url . '">';
        if (preg_match('/\.png$/i', $custom)) {
            $tags .= '<link rel="apple-touch-icon" href="' . $url . '">';
        }

        return $tags;
    }

    return '<link rel="icon" href="' . h(static_url('brand/favicon.ico')) . '" sizes="any">'
        . '<link rel="icon" type="image/png" sizes="32x32" href="' . h(static_url('brand/icon-32.png')) . '">'
        . '<link rel="icon" type="image/png" sizes="16x16" href="' . h(static_url('brand/icon-16.png')) . '">'
        . '<link rel="icon" type="image/png" sizes="192x192" href="' . h(static_url('brand/icon-192.png')) . '">'
        . '<link rel="apple-touch-icon" sizes="180x180" href="' . h(static_url('brand/apple-touch-icon.png')) . '">';
}

/**
 * 登录密码强度校验（注册、找回密码、修改密码、管理员密码通用）。
 * 规则：8-64 位；至少包含字母和数字；不能与用户名/邮箱相同；不能是常见弱密码。
 * 返回错误信息，合格返回空字符串。
 */
function password_strength_error($pw, $username = '', $email = '')
{
    $pw = (string)$pw;
    $len = strlen($pw);
    if ($len < 8 || $len > 64) {
        return '密码长度需为 8-64 位';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        return '密码需同时包含字母和数字';
    }
    $lower = strtolower($pw);
    foreach (array((string)$username, (string)$email, strstr((string)$email, '@', true)) as $x) {
        if ($x !== '' && $x !== false && strtolower($x) === $lower) {
            return '密码不能与用户名或邮箱相同';
        }
    }
    $weak = array('password1', 'password123', 'passw0rd', 'qwerty123', 'abc12345', 'abcd1234', 'a1234567', 'admin123', 'admin888', 'admin1234', '1qaz2wsx', 'qwe12345', 'iloveyou1', 'aa123456', 'woaini1314', '12345678a', 'a12345678', '123456789a', 'zxcvbnm1');
    if (in_array($lower, $weak, true) || preg_match('/^(.)\1+$/', $pw)) {
        return '密码过于常见，请换一个更难猜的密码';
    }
    return '';
}
/** 后台独立页面（登录、两步验证、找回密码）的页头：与登录页同样式 */
function admin_auth_open($title, $subtitle = '')
{
    $site = setting('site_name', 'o2o云');
    $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' - ' . h($site) . '</title>' . brand_head_tags()
        . '<link rel="stylesheet" href="' . h(static_url('vendor/bootstrap/bootstrap.min.css')) . '"><link rel="stylesheet" href="' . h(static_url('css/admin.css')) . '"></head>'
        . '<body class="admin-login"><div class="auth-card"><h1 class="auth-logo"><img src="' . h(brand_logo_url(false, true)) . '" alt="' . h($site) . '" height="52"></h1>'
        . '<p class="text-muted">' . h($subtitle !== '' ? $subtitle : $title) . '</p>';
    foreach ((session('flash') ?: array()) as $f) {
        $html .= '<div class="alert alert-' . h($f[0]) . '">' . nl2br(h($f[1])) . '</div>';
    }
    session('flash', null);
    return $html;
}
function admin_auth_close()
{
    return '</div><script src="' . h(static_url('js/admin.js')) . '"></script></body></html>';
}
