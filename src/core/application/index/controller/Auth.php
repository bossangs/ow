<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\lib\AuthGuard;
use app\common\lib\Captcha;
use app\common\lib\Notify;
use app\common\service\PasswordResetService;
use app\common\service\RiskService;
use app\common\service\TierService;
use app\common\service\TwoFactorService;
use think\Db;

class Auth extends FrontBase
{
    protected $needLogin = false;

    /** 同一账号连续输错密码 5 次锁 15 分钟；同一 IP 30 次锁 15 分钟 */
    const LOGIN_MAX_FAILS = 5;
    const LOGIN_MAX_IP_FAILS = 30;
    const LOGIN_LOCK = 900;
    /** 输完密码后，等待输入谷歌验证码的有效时间（秒） */
    const PENDING_TTL = 300;

    public function captcha()
    {
        header('Content-Type: image/svg+xml; charset=utf-8');

        return Captcha::create(preg_replace('/[^a-z_]/', '', (string)$this->get('scene', 'login')));
    }

    /** 只允许站内跳转：/开头（不能是 // 或 /\）或 模块/控制器/方法 形式，防止开放重定向 */
    protected function safeBack($back)
    {
        $back = (string)$back;
        if (!preg_match('#^/(?![/\\\\])#', $back) && !preg_match('#^[a-z_]+/[a-z_]+/[a-z_]+$#', $back)) {
            return '';
        }

        return $back;
    }

    public function login()
    {
        if ($this->user) {
            return $this->redirectTo('index/user/index');
        }
        if ($this->request->isPost()) {
            if (!Captcha::check($this->post('captcha'), 'login')) {
                $this->flash('验证码错误', 'danger');

                return $this->back();
            }
            $account = trim((string)$this->post('account'));
            $accKey = 'ulogin:' . sha1(strtolower($account));
            $ipKey = 'uloginip:' . client_ip();
            $wait = max(AuthGuard::lockedFor($accKey), AuthGuard::lockedFor($ipKey));
            if ($wait > 0) {
                $this->flash('密码错误次数过多，请 ' . AuthGuard::waitText($wait) . '后再试，或使用「忘记密码」', 'danger');

                return $this->back();
            }
            $u = $account === '' ? null : Db::name('user')->where('username|email', $account)->find();
            if (!$u || !password_verify((string)$this->post('password'), $u['password']) || !(int)$u['status']) {
                AuthGuard::fail($accKey, self::LOGIN_MAX_FAILS, self::LOGIN_LOCK);
                AuthGuard::fail($ipKey, self::LOGIN_MAX_IP_FAILS, self::LOGIN_LOCK);
                $this->flash('账号或密码错误', 'danger');

                return $this->back();
            }
            AuthGuard::clear($accKey);
            $back = $this->safeBack($this->get('back'));
            if (TwoFactorService::enabled($u)) {
                // 密码正确但还没完成两步验证：只记“待验证”，不建立登录态
                session('user_2fa_pending', ['id' => (int)$u['id'], 'sig' => substr(sha1($u['password']), 0, 16), 'time' => time(), 'back' => $back]);

                return $this->redirectTo('index/auth/twofa');
            }
            $this->completeLogin($u);

            return $this->redirectTo($back ?: 'index/user/index');
        }

        return $this->fetch('auth/login', ['title' => '登录']);
    }

    /** 登录第二步：谷歌验证码或恢复码 */
    public function twofa()
    {
        $pending = session('user_2fa_pending');
        $u = null;
        if (is_array($pending) && time() - (int)$pending['time'] <= self::PENDING_TTL) {
            $u = Db::name('user')->find((int)$pending['id']);
            if (!$u || !(int)$u['status'] || substr(sha1($u['password']), 0, 16) !== $pending['sig']) {
                $u = null;
            }
        }
        if (!$u || !TwoFactorService::enabled($u)) {
            session('user_2fa_pending', null);
            $this->flash('登录已超时，请重新输入账号和密码', 'warning');

            return $this->redirectTo('index/auth/login');
        }
        if ($this->request->isPost()) {
            try {
                $via = TwoFactorService::verify('user', $u, (string)$this->post('code'), true);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->redirectTo('index/auth/twofa');
            }
            session('user_2fa_pending', null);
            $this->completeLogin($u);
            if ($via === 'recovery') {
                $left = TwoFactorService::recoveryLeft('user', $u['id']);
                $this->flash('您使用了一个恢复码登录，还剩 ' . $left . ' 个。' . ($left <= 3 ? '建议尽快在「账号安全」里重新生成恢复码。' : ''), 'warning');
            }

            return $this->redirectTo(!empty($pending['back']) ? $pending['back'] : 'index/user/index');
        }

        return $this->fetch('auth/twofa', ['title' => '两步验证', 'account' => $u, 'locked' => TwoFactorService::lockedFor('user', $u['id'])]);
    }

    protected function completeLogin(array $u)
    {
        try {
            \think\facade\Session::regenerate(true);
        } catch (\Exception $e) {
        }
        session('user_id', $u['id']);
        session('user_name', $u['username']);
        session('user_pwd_sig', substr(sha1($u['password']), 0, 16));
        Db::name('user')->where('id', $u['id'])->update(['last_login_ip' => client_ip(), 'last_login_time' => time()]);
    }

    public function register()
    {
        if (setting('reg_enable', '1') !== '1') {
            return $this->abort('注册已关闭', 403);
        }
        if ($this->request->isPost()) {
            if (!Captcha::check($this->post('captcha'), 'register')) {
                $this->flash('验证码错误', 'danger');

                return $this->back();
            }
            $email = strtolower(trim((string)$this->post('email')));
            $name = trim((string)$this->post('username'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $name)) {
                $this->flash('邮箱或用户名格式不正确', 'danger');

                return $this->back();
            }
            $password = (string)$this->post('password');
            $password2 = $this->post('password2');
            if ($password2 !== null && $password2 !== '' && (string)$password2 !== $password) {
                $this->flash('两次输入的密码不一致', 'danger');

                return $this->back();
            }
            $err = password_strength_error($password, $name, $email);
            if ($err !== '') {
                $this->flash($err, 'danger');

                return $this->back();
            }
            if (Db::name('user')->where(function ($q) use ($email, $name) {
                $q->where('email', $email)->whereOr('username', $name)->whereOr('username', $email);
            })->count()) {
                $this->flash('邮箱或用户名已存在', 'danger');

                return $this->back();
            }
            $verified = setting('reg_email_verify', '0') !== '1';
            if (!$verified && !$this->consumeCode($email, 'register', $this->post('email_code'))) {
                $this->flash('邮箱验证码无效或已过期', 'danger');

                return $this->back();
            }
            $tier = TierService::defaultTier();
            $id = Db::name('user')->insertGetId(['email' => $email, 'username' => $name, 'password' => password_hash($password, PASSWORD_DEFAULT), 'tier_id' => $tier ? $tier['id'] : 0, 'balance' => (float)setting('reg_bonus', '0'), 'status' => 1, 'email_verified' => $verified ? 1 : 0, 'reg_ip' => client_ip(), 'create_time' => time(), 'update_time' => time()]);
            $u = Db::name('user')->find($id);
            $this->completeLogin($u);
            Notify::user('user_register', $u);

            return $this->redirectTo('index/user/index');
        }

        return $this->fetch('auth/register', ['title' => '注册']);
    }

    /** 注册邮箱验证码（找回密码有单独的流程，不走这里） */
    public function sendcode()
    {
        $scene = (string)$this->post('scene', 'register');
        if ($scene !== 'register') {
            return $this->fail('不支持的验证码类型');
        }
        $email = strtolower(trim((string)$this->post('email')));
        if (!Captcha::check($this->post('captcha'), $scene)) {
            return $this->fail('图形验证码错误');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || RiskService::tooFrequent('email_code_' . $scene . '_' . $email, 1, 60, true)) {
            return $this->fail('请求过于频繁或邮箱无效');
        }
        $code = (string)random_int(100000, 999999);
        Db::name('verify_code')->insert(['target' => $email, 'scene' => $scene, 'code' => $code, 'expire_time' => time() + 600, 'ip' => client_ip(), 'create_time' => time()]);
        Notify::mail($email, setting('verify_code_title', '邮箱验证码'), str_replace('{code}', $code, setting('verify_code_body', '您的验证码是 {code}，10 分钟内有效。')));

        return $this->ok('验证码已发送');
    }

    /** 找回密码：第一步发邮件（验证码或链接），第二步验证码 + 新密码（+ 两步验证码） */
    public function forgot()
    {
        $method = PasswordResetService::method('user');
        if ($this->request->isPost()) {
            $act = (string)$this->post('act', 'send');
            $email = trim((string)$this->post('email'));
            if ($act === 'send') {
                if (!Captcha::check($this->post('captcha'), 'reset')) {
                    $this->flash('图形验证码错误', 'danger');

                    return $this->redirectTo('index/auth/forgot');
                }
                try {
                    $msg = PasswordResetService::request('user', $email, (string)$this->post('via', 'code'));
                    session('user_reset_email', strtolower($email));
                    $this->flash($msg);
                } catch (\Exception $e) {
                    $this->flash($e->getMessage(), 'danger');
                }

                return $this->redirectTo('index/auth/forgot');
            }
            try {
                list($acc, $row) = PasswordResetService::checkCode('user', $email, (string)$this->post('code', $this->post('email_code')));
                PasswordResetService::complete('user', $acc, 'code', $row, (string)$this->post('password'), (string)$this->post('password2', $this->post('password')), (string)$this->post('twofa_code'));
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->redirectTo('index/auth/forgot');
            }
            session('user_reset_email', null);
            $this->flash('密码已重置，请用新密码登录');

            return $this->redirectTo('index/auth/login');
        }

        return $this->fetch('auth/forgot', ['title' => '找回密码', 'method' => $method, 'email' => (string)session('user_reset_email'), 'minutes' => PasswordResetService::codeMinutes()]);
    }

    /** 通过邮件里的一次性链接重置 */
    public function reset()
    {
        $token = (string)$this->request->param('token', '');
        try {
            list($acc, $row) = PasswordResetService::checkToken('user', $token);
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->redirectTo('index/auth/forgot');
        }
        if ($this->request->isPost()) {
            try {
                PasswordResetService::complete('user', $acc, 'link', $row, (string)$this->post('password'), (string)$this->post('password2'), (string)$this->post('twofa_code'));
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->redirectTo('index/auth/reset', ['token' => $token]);
            }
            $this->flash('密码已重置，请用新密码登录');

            return $this->redirectTo('index/auth/login');
        }

        return $this->fetch('auth/reset', ['title' => '设置新密码', 'token' => $token, 'account' => $acc, 'need2fa' => TwoFactorService::enabled($acc)]);
    }

    protected function consumeCode($target, $scene, $code)
    {
        $code = trim((string)$code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $row = Db::name('verify_code')->where(['target' => $target, 'scene' => $scene, 'code' => $code, 'used' => 0])->where('expire_time', '>', time())->order('id desc')->find();
        if (!$row) {
            return false;
        }

        return (bool)Db::name('verify_code')->where('id', $row['id'])->where('used', 0)->update(['used' => 1]);
    }

    public function logout()
    {
        session('user_id', null);
        session('user_name', null);
        session('user_pwd_sig', null);
        session('user_2fa_pending', null);

        return $this->redirectTo('');
    }
}
