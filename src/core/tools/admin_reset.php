<?php

/**
 * 管理员应急工具（只能在服务器命令行运行，网页无法访问 core 目录）
 *
 * 用法（在网站根目录执行）：
 *   php core/tools/admin_reset.php list                     列出管理员及两步验证状态
 *   php core/tools/admin_reset.php reset-2fa <用户名>         关闭该管理员的两步验证（手机丢失且恢复码用完时）
 *   php core/tools/admin_reset.php reset-password <用户名>    生成一个随机新密码并显示一次
 *   php core/tools/admin_reset.php unlock <用户名>            解除登录/验证码错误锁定
 *
 * 所有操作都会写入审计日志，并给该管理员邮箱发送提醒。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
define('IDC_ENTRY', 'index.php');
define('IDC_NO_RUN', true);
require dirname(__DIR__) . '/boot.php';

use app\common\lib\AuthGuard;
use app\common\service\PasswordResetService;
use app\common\service\TwoFactorService;
use think\Db;

$cmd = isset($argv[1]) ? $argv[1] : '';
$name = isset($argv[2]) ? trim($argv[2]) : '';
$out = function ($s) {
    fwrite(STDOUT, $s . "\n");
};
$operator = 'CLI(' . (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (string)@posix_getpwuid(posix_geteuid())['name'] : get_current_user()) . ')';

try {
    // 老版本数据库先补齐字段（与后台/计划任务相同的升级器，可重复执行）
    if (is_file(CORE_PATH . 'install/upgrade.php')) {
        require_once CORE_PATH . 'install/upgrade.php';
        idc_schema_upgrade();
    }
    if ($cmd === 'list') {
        $rows = Db::name('admin')->field('id,username,email,status,is_super,totp_enabled,lock_until')->order('id asc')->select();
        $out(sprintf('%-4s %-20s %-30s %-6s %-6s %-8s %s', 'ID', '用户名', '邮箱', '状态', '超管', '两步验证', '锁定'));
        foreach ($rows as $r) {
            $out(sprintf('%-4d %-20s %-30s %-6s %-6s %-8s %s', $r['id'], $r['username'], $r['email'], $r['status'] ? '正常' : '停用', $r['is_super'] ? '是' : '否', $r['totp_enabled'] ? '已开启' : '未开启', $r['lock_until'] > time() ? date('H:i', $r['lock_until']) . ' 前' : '-'));
        }
        exit(0);
    }
    if (!in_array($cmd, ['reset-2fa', 'reset-password', 'unlock'], true) || $name === '') {
        $out("用法：php core/tools/admin_reset.php list | reset-2fa <用户名> | reset-password <用户名> | unlock <用户名>");
        exit(1);
    }
    $a = Db::name('admin')->where('username', $name)->find();
    if (!$a) {
        $out('找不到管理员：' . $name);
        exit(2);
    }
    $target = '管理员 ' . $a['username'] . ' #' . $a['id'];
    if ($cmd === 'reset-2fa') {
        if (!TwoFactorService::enabled($a)) {
            $out('该管理员没有开启两步验证，无需重置。');
            exit(0);
        }
        TwoFactorService::disable('admin', $a, $operator, '服务器命令行应急重置');
        $out('已关闭 ' . $a['username'] . ' 的两步验证。请登录后台后在「我的两步验证」里重新绑定。');
    } elseif ($cmd === 'reset-password') {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        do {
            $pw = '';
            for ($i = 0; $i < 16; $i++) {
                $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (password_strength_error($pw, $a['username'], $a['email']) !== '');
        PasswordResetService::setPassword('admin', $a, $pw, '服务器命令行重置密码', $operator);
        $out('新密码（只显示这一次，请登录后立即修改）：' . $pw);
    } else {
        Db::name('admin')->where('id', $a['id'])->update(['login_fail' => 0, 'lock_until' => 0]);
        foreach (['2fa:admin:' . $a['id']] as $k) {
            AuthGuard::clear($k);
        }
        audit('admin.unlock', $target, '服务器命令行解除锁定，操作人：' . $operator, 'warn', 'system', 0, $operator);
        $out('已解除 ' . $a['username'] . ' 的登录锁定。');
    }
    exit(0);
} catch (\Exception $e) {
    $out('失败：' . $e->getMessage());
    exit(3);
}
