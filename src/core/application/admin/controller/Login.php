<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\lib\Captcha;
use app\common\service\PasswordResetService;
use app\common\service\RiskService;
use app\common\service\TwoFactorService;
use think\Db;
use think\Response;

class Login extends AdminBase
{
    protected $needLogin = false;

    /** 输完密码后，等待输入谷歌验证码的有效时间（秒） */
    const PENDING_TTL = 300;

    public function captcha()
    {
        return Captcha::response('admin');
    }

    public function index()
    {
        if ($this->admin) {
            return $this->redirectTo('admin/dashboard/index');
        }
        if ($this->request->isPost()) {
            $u = trim((string) $this->request->post('username'));
            $p = (string) $this->request->post('password');
            $c = (string) $this->request->post('captcha');
            if (!Captcha::check($c, 'admin')) {
                $this->flash('验证码错误', 'danger');

                return $this->back();
            }
            if (RiskService::tooFrequent('admin.login_fail', 20, 900, true, 0)) {
                $this->flash('登录失败次数过多，请 15 分钟后再试', 'danger');

                return $this->back();
            }
            $a = Db::name('admin')->where('username', $u)->find();
            if (!$a || ($a['lock_until'] > time()) || !password_verify($p, $a['password']) || (int) $a['status'] !== 1) {
                if ($a) {
                    $fails = (int) $a['login_fail'] + 1;
                    Db::name('admin')->where('id', $a['id'])->update(['login_fail' => $fails, 'lock_until' => $fails >= 5 ? time() + 900 : 0]);
                }
                audit('admin.login_fail', $u, '', 'warn', 'guest', 0, '');
                $this->flash('账号、密码错误或账号已锁定', 'danger');

                return $this->back();
            }
            if (TwoFactorService::enabled($a)) {
                // 密码正确但还没完成两步验证：只记“待验证”，不建立登录态
                session('admin_2fa_pending', ['id' => (int) $a['id'], 'sig' => substr(sha1($a['password']), 0, 16), 'time' => time()]);

                return $this->redirectTo('admin/login/twofa');
            }
            $this->completeLogin($a, '密码');

            return $this->redirectTo('admin/dashboard/index');
        }

        return $this->fetch('login', [], false);
    }

    /** 登录第二步：谷歌验证码或恢复码 */
    public function twofa()
    {
        $pending = session('admin_2fa_pending');
        $a = null;
        if (is_array($pending) && time() - (int) $pending['time'] <= self::PENDING_TTL) {
            $a = Db::name('admin')->find((int) $pending['id']);
            if (!$a || (int) $a['status'] !== 1 || substr(sha1($a['password']), 0, 16) !== $pending['sig']) {
                $a = null;
            }
        }
        if (!$a || !TwoFactorService::enabled($a)) {
            session('admin_2fa_pending', null);
            $this->flash('登录已超时，请重新输入账号和密码', 'warning');

            return $this->redirectTo('admin/login/index');
        }
        if ($this->request->isPost()) {
            try {
                $via = TwoFactorService::verify('admin', $a, (string) $this->request->post('code'), true);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->redirectTo('admin/login/twofa');
            }
            session('admin_2fa_pending', null);
            $this->completeLogin($a, $via === 'recovery' ? '密码 + 恢复码' : '密码 + 谷歌验证码');
            if ($via === 'recovery') {
                $left = TwoFactorService::recoveryLeft('admin', $a['id']);
                $this->flash('您使用了一个恢复码登录，还剩 ' . $left . ' 个。' . ($left <= 3 ? '建议尽快在「个人资料 → 两步验证」重新生成恢复码。' : ''), 'warning');
            }
            // 刚输过验证码，视为已完成二次验证
            session('admin_sudo_until', time() + $this->sudoMinutes() * 60);

            return $this->redirectTo('admin/dashboard/index');
        }

        return $this->fetch('login/twofa', ['account' => $a, 'locked' => TwoFactorService::lockedFor('admin', $a['id'])], false);
    }

    protected function completeLogin(array $a, $via)
    {
        try {
            \think\facade\Session::regenerate(true);
        } catch (\Exception $e) {
        }
        Db::name('admin')->where('id', $a['id'])->update(['login_fail' => 0, 'lock_until' => 0, 'last_login_time' => time(), 'last_login_ip' => client_ip()]);
        session('admin_id', $a['id']);
        session('admin_name', $a['username']);
        session('admin_pwd_sig', substr(sha1($a['password']), 0, 16));
        audit('admin.login', $a['username'], $via, 'info', 'admin', $a['id'], $a['username']);
    }

    protected function sudoMinutes()
    {
        return max(1, min(60, (int) setting('admin_sudo_minutes', '5')));
    }

    /** 管理员找回密码：第一步发邮件（验证码或链接），第二步验证码 + 新密码（+ 两步验证码） */
    public function forgot()
    {
        if ($this->admin) {
            return $this->redirectTo('admin/dashboard/index');
        }
        $method = PasswordResetService::method('admin');
        if ($this->request->isPost()) {
            $act = (string) $this->request->post('act', 'send');
            $email = trim((string) $this->request->post('email'));
            if ($act === 'send') {
                if (!Captcha::check((string) $this->request->post('captcha'), 'admin')) {
                    $this->flash('图形验证码错误', 'danger');

                    return $this->redirectTo('admin/login/forgot');
                }
                try {
                    $msg = PasswordResetService::request('admin', $email, (string) $this->request->post('via', 'code'));
                    session('admin_reset_email', strtolower($email));
                    $this->flash($msg);
                } catch (\Exception $e) {
                    $this->flash($e->getMessage(), 'danger');
                }

                return $this->redirectTo('admin/login/forgot');
            }
            try {
                list($acc, $row) = PasswordResetService::checkCode('admin', $email, (string) $this->request->post('code'));
                PasswordResetService::complete('admin', $acc, 'code', $row, (string) $this->request->post('password'), (string) $this->request->post('password2'), (string) $this->request->post('twofa_code'));
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->redirectTo('admin/login/forgot');
            }
            session('admin_reset_email', null);
            $this->flash('密码已重置，请用新密码登录');

            return $this->redirectTo('admin/login/index');
        }

        return $this->fetch('login/forgot', ['method' => $method, 'email' => (string) session('admin_reset_email'), 'minutes' => PasswordResetService::codeMinutes()], false);
    }

    /** 通过邮件里的一次性链接重置 */
    public function reset()
    {
        $token = (string) $this->request->param('token', '');
        try {
            list($acc, $row) = PasswordResetService::checkToken('admin', $token);
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->redirectTo('admin/login/forgot');
        }
        if ($this->request->isPost()) {
            try {
                PasswordResetService::complete('admin', $acc, 'link', $row, (string) $this->request->post('password'), (string) $this->request->post('password2'), (string) $this->request->post('twofa_code'));
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->redirectTo('admin/login/reset', ['token' => $token]);
            }
            $this->flash('密码已重置，请用新密码登录');

            return $this->redirectTo('admin/login/index');
        }

        return $this->fetch('login/reset', ['token' => $token, 'account' => $acc, 'need2fa' => TwoFactorService::enabled($acc)], false);
    }

    /** 敏感操作前的二次验证（重新输入谷歌验证码） */
    public function sudo()
    {
        if (!$this->admin) {
            return $this->redirectTo('admin/login/index');
        }
        $back = (string) session('admin_sudo_back');
        if ($back === '' || $back[0] !== '/' || (isset($back[1]) && ($back[1] === '/' || $back[1] === '\\'))) {
            $back = url_to('admin/dashboard/index');
        }
        if (!TwoFactorService::enabled($this->admin)) {
            return redirect($back);
        }
        if ($this->request->isPost()) {
            try {
                $via = TwoFactorService::verify('admin', $this->admin, (string) $this->request->post('code'), true);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->redirectTo('admin/login/sudo');
            }
            session('admin_sudo_until', time() + $this->sudoMinutes() * 60);
            session('admin_sudo_back', null);
            audit('admin.sudo', $this->admin['username'], $via === 'recovery' ? '恢复码' : '谷歌验证码');

            return redirect($back);
        }

        return $this->fetch('login/sudo', ['minutes' => $this->sudoMinutes(), 'back' => $back]);
    }

    public function logout()
    {
        session('admin_id', null);
        session('admin_name', null);
        session('admin_pwd_sig', null);
        session('admin_sudo_until', null);
        session('admin_2fa_pending', null);

        return $this->redirectTo('admin/login/index');
    }

    public function password()
    {
        if (!$this->admin) {
            return $this->redirectTo('admin/login/index');
        }
        $this->requireSudo();
        if (!$this->request->isPost()) {
            return $this->fetch('password');
        }
        $old = (string) $this->request->post('old_password');
        $new = (string) $this->request->post('password');
        if (!password_verify($old, $this->admin['password'])) {
            $this->flash('原密码不正确', 'danger');

            return $this->back();
        }
        $new2 = $this->request->post('password2');
        if ($new2 !== null && (string) $new2 !== $new) {
            $this->flash('两次输入的新密码不一致', 'danger');

            return $this->back();
        }
        $err = password_strength_error($new, $this->admin['username'], $this->admin['email']);
        if ($err !== '') {
            $this->flash($err, 'danger');

            return $this->back();
        }
        $hash = PasswordResetService::setPassword('admin', $this->admin, $new, '后台修改密码');
        session('admin_pwd_sig', substr(sha1($hash), 0, 16));
        $this->flash('密码已修改，其他设备上的后台登录已退出');

        return $this->redirectTo('admin/dashboard/index');
    }
}
