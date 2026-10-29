<?php
/**
 * 系统引导：定位数据目录、校验路由参数、加载 ThinkPHP 5.1
 */
if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    header('Content-Type: text/html; charset=utf-8');
    exit('本系统需要 PHP 7.0 或更高版本，当前版本：' . PHP_VERSION);
}

define('IDC_VERSION', '1.1.0');
define('WEB_ROOT', rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/');
define('CORE_PATH', WEB_ROOT . 'core/');
define('IDC_CLI', PHP_SAPI === 'cli');

// ---------- 定位数据目录 ----------
$__dataPath = '';
if (is_file(CORE_PATH . 'datapath.php')) {
    $__p = include CORE_PATH . 'datapath.php';
    if (is_string($__p) && $__p !== '') {
        $__dataPath = rtrim(str_replace('\\', '/', $__p), '/') . '/';
    }
}
// 安装器把随机名或自定义数据目录记录在 data/datapath.php（PHP 文件，直接访问不会泄露内容）
if ($__dataPath === '' && is_file(WEB_ROOT . 'data/datapath.php')) {
    $__p = include WEB_ROOT . 'data/datapath.php';
    if (is_string($__p) && $__p !== '' && is_dir($__p)) {
        $__dataPath = rtrim(str_replace('\\', '/', $__p), '/') . '/';
    }
}
if ($__dataPath === '' && is_file(WEB_ROOT . 'data/config.php')) {
    $__dataPath = WEB_ROOT . 'data/';
}
define('DATA_PATH', $__dataPath);
define('IDC_INSTALLED', DATA_PATH !== '' && is_file(DATA_PATH . 'install.lock.php') && is_file(DATA_PATH . 'config.php'));

if (!IDC_INSTALLED) {
    if (IDC_CLI) {
        fwrite(STDERR, "系统尚未安装，请先通过浏览器访问 install.php 完成安装。\n");
        exit(1);
    }
    $base = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    header('Location: ' . $base . '/install.php');
    exit;
}

$GLOBALS['IDC_CONFIG'] = include DATA_PATH . 'config.php';

// ---------- 路由参数白名单（防御 ThinkPHP 历史上的控制器注入类漏洞） ----------
if (isset($_GET['s'])) {
    if (!is_string($_GET['s']) || !preg_match('#^[A-Za-z0-9_/\.\-]{0,200}$#', $_GET['s']) || strpos($_GET['s'], '..') !== false) {
        header('HTTP/1.1 400 Bad Request');
        exit('Bad Request');
    }
}
foreach (['PATH_INFO', 'ORIG_PATH_INFO'] as $__k) {
    if (!empty($_SERVER[$__k]) && !preg_match('#^[A-Za-z0-9_/\.\-]{0,200}$#', $_SERVER[$__k])) {
        header('HTTP/1.1 400 Bad Request');
        exit('Bad Request');
    }
}
unset($__dataPath, $__p, $__k);

// ---------- mbstring 兜底 ----------
require CORE_PATH . 'polyfill.php';

// ---------- 加载框架 ----------
require CORE_PATH . 'thinkphp/base.php';
// PHP 8.1+ 对 ThinkPHP 5.1 内核（ArrayAccess 等方法的返回类型）和部分内置函数传 null 会发“弃用”提示，
// 框架默认把所有提示都转成异常（编译类时还会因容器未就绪而崩溃），导致整站 500。
// 弃用提示不是错误：PHP 8 以上直接忽略它们；警告、通知仍交给框架原样处理，行为与 PHP 7 一致。
if (PHP_VERSION_ID >= 80000) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    set_error_handler(function ($errno, $errstr, $errfile = '', $errline = 0) {
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            return true;
        }
        \think\Error::appError($errno, $errstr, $errfile, $errline);
        return true;
    });
}
// 框架按网站根目录找 vendor/，而第三方库放在 core/vendor/，这里显式注册 PHPMailer 命名空间
\think\Loader::addNamespace('PHPMailer\\PHPMailer', CORE_PATH . 'vendor/phpmailer/phpmailer/src/');
require CORE_PATH . 'application/common/SafeApp.php';

\think\Container::getInstance()->bindTo('app', \app\common\SafeApp::class);

if (defined('IDC_NO_RUN')) {
    // 命令行工具（core/tools/*.php）：只初始化框架和数据库，不分发路由
    \think\Container::get('app', [CORE_PATH . 'application/'])->initialize();
    return;
}
if (defined('IDC_CRON_CLI')) {
    $_GET['s'] = '/api/cron/run';
}
\think\Container::get('app', [CORE_PATH . 'application/'])->run()->send();
