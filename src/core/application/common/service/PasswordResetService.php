<?php

namespace app\common\service;

use app\common\lib\AuthGuard;
use app\common\lib\Notify;
use think\Db;

/**
 * 找回密码（前台用户 user 与后台管理员 admin 共用）。
 *
 * - 方式：邮箱验证码（默认）或一次性重置链接，后台「系统设置 → 登录安全」可选；
 * - 验证码 6 位（random_int），只存哈希，默认 10 分钟有效，新码发出后旧码作废；
 * - 链接令牌 256 位随机数，只存 SHA-256，默认 30 分钟有效，只能用一次；
 * - 同一邮箱输错 5 次（或同一 IP 输错 20 次）锁定 15 分钟，并作废该邮箱所有未用验证码；
 * - 不暴露邮箱是否注册：无论是否存在都返回同样的提示；
 * - 开启了两步验证的账号，重置时还必须输入谷歌验证码或恢复码（邮箱被盗也无法绕过）；
 * - 改密后：其他设备的登录全部失效（会话校验密码签名），并通过邮件/Telegram 通知本人，写审计日志。
 */
class PasswordResetService
{
    const MAX_FAILS = 5;
    const MAX_IP_FAILS = 20;
    const LOCK_SECONDS = 900;

    public static function table($type)
    {
        return $type === 'admin' ? 'admin' : 'user';
    }

    /** code | link | both */
    public static function method($type)
    {
        $m = setting($type === 'admin' ? 'admin_reset_method' : 'reset_method', 'code');

        return in_array($m, ['code', 'link', 'both'], true) ? $m : 'code';
    }

    public static function codeMinutes()
    {
        return max(1, min(60, (int)setting('reset_code_minutes', '10')));
    }

    public static function linkMinutes()
    {
        return max(5, min(1440, (int)setting('reset_link_minutes', '30')));
    }

    public static function genericSentMessage()
    {
        return '如果该邮箱已绑定账号，我们已把找回密码邮件发到这个邮箱，请查收（也看看垃圾邮件箱）';
    }

    protected static function scene($type)
    {
        return $type === 'admin' ? 'admin_reset' : 'reset';
    }

    protected static function normEmail($email)
    {
        return strtolower(trim((string)$email));
    }

    public static function findAccount($type, $email)
    {
        $email = self::normEmail($email);
        if ($email === '') {
            return null;
        }
        $row = Db::name(self::table($type))->where('email', $email)->find();

        return $row && (int)$row['status'] === 1 ? $row : null;
    }

    /**
     * 申请找回：$want = code | link。无论邮箱是否存在都返回同一提示文字。
     * 发送频率：同一邮箱 60 秒 1 次、1 小时 5 次；同一 IP 1 小时 20 次。
     */
    public static function request($type, $email, $want = 'code')
    {
        $email = self::normEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('请输入正确的邮箱地址');
        }
        $method = self::method($type);
        if ($method !== 'both') {
            $want = $method;
        }
        $want = $want === 'link' ? 'link' : 'code';
        $sendKey = 'reset_send_' . $type . '_' . sha1($email);
        if (self::countRecent($sendKey, 60) >= 1 || self::countRecent($sendKey, 3600) >= 5 || self::countRecentIp('password.reset_request', 3600) >= 20) {
            throw new \RuntimeException('发送太频繁，请稍后再试');
        }
        // 只在审计里记邮箱哈希，避免日志泄露邮箱列表
        audit($sendKey, '', '', 'info', 'guest', 0, '');
        audit('password.reset_request', $type, $want, 'info', 'guest', 0, '');
        $acc = self::findAccount($type, $email);
        if (!$acc) {
            return self::genericSentMessage();
        }
        $vars = ['username' => $acc['username'], 'minutes' => 0];
        if ($want === 'link') {
            $token = bin2hex(random_bytes(32));
            Db::name('password_reset')->insert(['owner_type' => $type, 'owner_id' => (int)$acc['id'], 'token_hash' => hash('sha256', $token), 'expire_time' => time() + self::linkMinutes() * 60, 'used_time' => 0, 'ip' => client_ip(), 'create_time' => time()]);
            $vars['minutes'] = self::linkMinutes();
            $vars['link'] = url_to($type === 'admin' ? 'admin/login/reset' : 'index/auth/reset', ['token' => $token], true);
            $event = 'password_reset_link';
        } else {
            // 旧验证码全部作废，只保留最新一个
            Db::name('verify_code')->where(['target' => $email, 'scene' => self::scene($type), 'used' => 0])->update(['used' => 1]);
            $code = sprintf('%06d', random_int(0, 999999));
            Db::name('verify_code')->insert(['target' => $email, 'scene' => self::scene($type), 'code' => hash('sha256', $code), 'expire_time' => time() + self::codeMinutes() * 60, 'used' => 0, 'ip' => client_ip(), 'create_time' => time()]);
            $vars['minutes'] = self::codeMinutes();
            $vars['code'] = $code;
            $event = 'password_reset_code';
        }
        try {
            if ($type === 'admin') {
                Notify::toEmail($event, $acc['email'], $vars);
            } else {
                Notify::user($event, $acc, $vars);
            }
        } catch (\Exception $e) {
            audit('password.reset_mail_error', $type . '#' . $acc['id'], $e->getMessage(), 'warn', 'guest', 0, '');
        }

        return self::genericSentMessage();
    }

    /** 校验邮箱验证码，返回 [账号, 验证码记录]；失败计数并可能锁定 */
    public static function checkCode($type, $email, $code)
    {
        $email = self::normEmail($email);
        $ek = 'reset:' . $type . ':' . sha1($email);
        $ik = 'reset:ip:' . client_ip();
        $wait = max(AuthGuard::lockedFor($ek), AuthGuard::lockedFor($ik));
        if ($wait > 0) {
            throw new \RuntimeException('验证码错误次数过多，请 ' . AuthGuard::waitText($wait) . '后重新获取验证码');
        }
        $code = preg_replace('/\s+/', '', (string)$code);
        $acc = self::findAccount($type, $email);
        $row = null;
        if ($acc && preg_match('/^\d{6}$/', $code)) {
            $row = Db::name('verify_code')->where(['target' => $email, 'scene' => self::scene($type), 'used' => 0])->where('expire_time', '>', time())->order('id desc')->find();
            if ($row && !hash_equals((string)$row['code'], hash('sha256', $code))) {
                $row = null;
            }
        }
        if (!$acc || !$row) {
            AuthGuard::fail($ik, self::MAX_IP_FAILS, self::LOCK_SECONDS);
            if (AuthGuard::fail($ek, self::MAX_FAILS, self::LOCK_SECONDS)) {
                Db::name('verify_code')->where(['target' => $email, 'scene' => self::scene($type), 'used' => 0])->update(['used' => 1]);
                audit('password.reset_locked', $type, '验证码错误次数过多', 'warn', 'guest', 0, '');
                throw new \RuntimeException('验证码错误次数过多，已作废当前验证码并锁定 15 分钟，请稍后重新获取');
            }
            throw new \RuntimeException('邮箱验证码不正确或已过期');
        }

        return [$acc, $row];
    }

    /** 校验重置链接令牌，返回 [账号, 令牌记录] */
    public static function checkToken($type, $token)
    {
        $ik = 'reset:ip:' . client_ip();
        $wait = AuthGuard::lockedFor($ik);
        if ($wait > 0) {
            throw new \RuntimeException('尝试次数过多，请 ' . AuthGuard::waitText($wait) . '后再试');
        }
        $token = (string)$token;
        $row = preg_match('/^[a-f0-9]{64}$/', $token) ? Db::name('password_reset')->where(['token_hash' => hash('sha256', $token), 'owner_type' => $type, 'used_time' => 0])->where('expire_time', '>', time())->find() : null;
        $acc = $row ? Db::name(self::table($type))->find($row['owner_id']) : null;
        if (!$row || !$acc || (int)$acc['status'] !== 1) {
            AuthGuard::fail($ik, self::MAX_IP_FAILS, self::LOCK_SECONDS);
            throw new \RuntimeException('重置链接无效、已使用或已过期，请重新申请');
        }

        return [$acc, $row];
    }

    /**
     * 完成重置。$via = code | link；$proof 为对应的验证码记录或令牌记录。
     * 账号开启了两步验证时必须提供 $twofaCode（谷歌验证码或恢复码）。
     */
    public static function complete($type, array $acc, $via, array $proof, $password, $password2, $twofaCode)
    {
        if ((string)$password !== (string)$password2) {
            throw new \InvalidArgumentException('两次输入的新密码不一致');
        }
        $err = password_strength_error($password, $acc['username'], $acc['email']);
        if ($err !== '') {
            throw new \InvalidArgumentException($err);
        }
        if (TwoFactorService::enabled($acc)) {
            if (trim((string)$twofaCode) === '') {
                throw new \InvalidArgumentException('该账号已开启两步验证：请同时输入谷歌验证器里的 6 位验证码，或一个恢复码');
            }
            TwoFactorService::verify($type, $acc, $twofaCode, true);
        }
        // 先作废凭证（原子），再改密码，防止同一凭证并发使用两次
        if ($via === 'link') {
            $ok = Db::name('password_reset')->where('id', $proof['id'])->where('used_time', 0)->update(['used_time' => time()]);
        } else {
            $ok = Db::name('verify_code')->where('id', $proof['id'])->where('used', 0)->update(['used' => 1]);
        }
        if (!$ok) {
            throw new \RuntimeException('验证码或链接已被使用，请重新申请');
        }
        self::setPassword($type, $acc, $password, $via === 'link' ? '邮件链接找回密码' : '邮箱验证码找回密码');
        AuthGuard::clear('reset:' . $type . ':' . sha1(self::normEmail($acc['email'])));
    }

    /**
     * 设置新密码的统一出口：更新哈希、作废该账号所有未用的找回凭证、写审计、通知本人。
     * 会话里保存的是密码哈希签名，改密后其他设备的登录自动失效。
     */
    public static function setPassword($type, array $acc, $password, $method, $operator = '')
    {
        $hash = password_hash((string)$password, PASSWORD_DEFAULT);
        $data = ['password' => $hash];
        if ($type === 'user') {
            $data['update_time'] = time();
        } else {
            $data['login_fail'] = 0;
            $data['lock_until'] = 0;
        }
        Db::name(self::table($type))->where('id', $acc['id'])->update($data);
        Db::name('password_reset')->where(['owner_type' => $type, 'owner_id' => (int)$acc['id'], 'used_time' => 0])->update(['used_time' => time()]);
        if (!empty($acc['email'])) {
            Db::name('verify_code')->where(['target' => self::normEmail($acc['email']), 'scene' => self::scene($type), 'used' => 0])->update(['used' => 1]);
        }
        $target = ($type === 'admin' ? '管理员 ' : '用户 ') . $acc['username'] . ' #' . (int)$acc['id'];
        audit('password.' . $type . '.changed', $target, $method . ($operator !== '' ? '，操作人：' . $operator : ''), 'warn', $operator !== '' ? null : ($type === 'admin' ? 'admin' : 'user'), $operator !== '' ? null : (int)$acc['id'], $operator !== '' ? null : $acc['username']);
        $vars = ['username' => $acc['username'], 'time' => date('Y-m-d H:i'), 'method' => $method, 'ip' => client_ip(), '_key' => 'pwd' . time()];
        try {
            if ($type === 'admin') {
                Notify::toEmail('password_changed', $acc['email'], $vars);
            } else {
                Notify::user('password_changed', $acc, $vars);
            }
        } catch (\Exception $e) {
        }

        return $hash;
    }

    protected static function countRecent($action, $window)
    {
        return Db::name('audit_log')->where('action', $action)->where('create_time', '>', time() - $window)->count();
    }

    protected static function countRecentIp($action, $window)
    {
        return Db::name('audit_log')->where('action', $action)->where('ip', client_ip())->where('create_time', '>', time() - $window)->count();
    }
}
