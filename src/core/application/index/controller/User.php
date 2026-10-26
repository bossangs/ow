<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\lib\Captcha;
use app\common\service\CardService;
use app\common\service\OrderService;
use app\common\service\TierService;
use think\Db;

class User extends FrontBase
{
    protected $needLogin = true;
    public function index()
    {
        return $this->fetch('user/index', ['title' => '用户中心', 'tier' => TierService::current($this->user), 'hosts' => Db::name('service')->where('user_id', $this->user['id'])->count(), 'subs' => Db::name('dns_subdomain')->where('user_id', $this->user['id'])->count(), 'pending' => Db::name('order')->where('user_id', $this->user['id'])->where('status', 'unpaid')->count(), 'notices' => Db::name('user_notice')->where('user_id', $this->user['id'])->where('is_read', 0)->count(), 'orders' => Db::name('order')->where('user_id', $this->user['id'])->order('id desc')->limit(5)->select()]);
    }
    public function profile()
    {
        if ($this->request->isPost()) {
            $u = Db::name('user')->find($this->user['id']);
            $email = strtolower(trim((string)$this->post('email')));
            $newPw = (string)$this->post('password');
            $d = ['telegram_chat_id' => trim((string)$this->post('telegram_chat_id')), 'update_time' => time()];
            $emailChanged = $email !== strtolower((string)$u['email']);
            if ($emailChanged || $newPw !== '') {
                // 修改邮箱或密码属于敏感操作：必须输入当前密码
                if (!password_verify((string)$this->post('current_password'), $u['password'])) {
                    $this->flash('修改邮箱或密码需要输入正确的当前密码', 'danger');

                    return $this->back();
                }
            }
            if ($emailChanged) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->flash('邮箱格式不正确', 'danger');

                    return $this->back();
                }
                if (Db::name('user')->where('email', $email)->where('id', '<>', $u['id'])->count()) {
                    $this->flash('该邮箱已被其他账号使用', 'danger');

                    return $this->back();
                }
                $d['email'] = $email;
                if (setting('reg_email_verify', '0') === '1') {
                    $d['email_verified'] = 0;
                }
            }
            if ($newPw !== '') {
                if ((string)$this->post('password2') !== $newPw) {
                    $this->flash('两次输入的新密码不一致', 'danger');

                    return $this->back();
                }
                $err = password_strength_error($newPw, $u['username'], $email);
                if ($err !== '') {
                    $this->flash($err, 'danger');

                    return $this->back();
                }
            }
            Db::name('user')->where('id', $u['id'])->update($d);
            if ($emailChanged) {
                audit('user.email_changed', '用户 ' . $u['username'] . ' #' . $u['id'], $u['email'] . ' → ' . $email, 'warn', 'user', $u['id'], $u['username']);
                // 通知旧邮箱，防止账号被盗后悄悄改绑
                try {
                    \app\common\lib\Notify::mail($u['email'], '您的账号邮箱已修改', '您好 ' . $u['username'] . "：\n\n您的账号邮箱已于 " . date('Y-m-d H:i') . ' 改为 ' . $email . '（IP：' . client_ip() . "）。\n如果不是您本人操作，请立即联系客服。");
                } catch (\Exception $e) {
                }
            }
            if ($newPw !== '') {
                $u = Db::name('user')->find($u['id']);
                $hash = \app\common\service\PasswordResetService::setPassword('user', $u, $newPw, '用户中心修改密码');
                session('user_pwd_sig', substr(sha1($hash), 0, 16));
                $this->flash('资料已更新，密码已修改，其他设备上的登录已退出');
            } else {
                $this->flash('资料已更新');
            }

            return $this->back();
        }

        return $this->fetch('user/profile', ['title' => '账户设置']);
    }

    /** 账号安全：两步验证（谷歌验证器）绑定 / 恢复码 / 关闭 */
    public function security()
    {
        $u = Db::name('user')->find($this->user['id']);
        $enabled = \app\common\service\TwoFactorService::enabled($u);
        $data = [
            'title' => '账号安全',
            'enabled' => $enabled,
            'codes' => \app\common\service\TwoFactorService::pendingCodes('user', $u['id']),
            'left' => $enabled ? \app\common\service\TwoFactorService::recoveryLeft('user', $u['id']) : 0,
            'bindTime' => (int)$u['totp_bind_time'],
            'issuer' => \app\common\service\TwoFactorService::issuer('user'),
            'locked' => \app\common\service\TwoFactorService::lockedFor('user', $u['id']),
            'account' => $u,
        ];
        if (!$enabled) {
            $secret = \app\common\service\TwoFactorService::setupSecret('user', $u['id'], (bool)$this->get('renew'));
            $data['secret'] = $secret;
            $data['secretGroups'] = trim(chunk_split($secret, 4, ' '));
            $data['qr'] = \app\common\service\TwoFactorService::qrSvg(\app\common\service\TwoFactorService::setupUri('user', $u, $secret), 220);
        }

        return $this->fetch('user/security', $data);
    }

    public function twofaEnable()
    {
        $this->requirePost();
        $u = Db::name('user')->find($this->user['id']);
        if (!password_verify((string)$this->post('password'), $u['password'])) {
            $this->flash('登录密码不正确', 'danger');

            return $this->redirectTo('index/user/security');
        }
        try {
            \app\common\service\TwoFactorService::enable('user', $u, (string)$this->post('code'), '');
            $this->flash('两步验证已开启。请立即保存下面的 10 个恢复码：手机丢失时只能靠它们登录。');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/user/security');
    }

    public function twofaCodes()
    {
        $codes = \app\common\service\TwoFactorService::pendingCodes('user', $this->user['id'], true);
        if (!$codes) {
            $this->flash('恢复码只能在生成后查看/下载一次。如果没保存好，请重新生成一组。', 'warning');

            return $this->redirectTo('index/user/security');
        }
        $txt = setting('site_name', 'o2o云') . " 两步验证恢复码\r\n账号：" . $this->user['username'] . "\r\n生成时间：" . date('Y-m-d H:i') . "\r\n\r\n" . implode("\r\n", $codes)
            . "\r\n\r\n每个恢复码只能使用一次。手机丢失或无法使用验证器时，在输入验证码的地方填写一个恢复码即可登录。\r\n请把本文件保存在安全的地方，不要发给任何人。\r\n";

        return \think\Response::create($txt, 'html')->header([
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="recovery-codes.txt"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function twofaSaved()
    {
        $this->requirePost();
        \app\common\service\TwoFactorService::discardCodes('user', $this->user['id']);
        $this->flash('好的，恢复码已从页面上清除');

        return $this->redirectTo('index/user/security');
    }

    public function twofaRegenerate()
    {
        $this->requirePost();
        $u = Db::name('user')->find($this->user['id']);
        try {
            \app\common\service\TwoFactorService::verify('user', $u, (string)$this->post('code'), false);
            \app\common\service\TwoFactorService::regenerateRecovery('user', $u['id']);
            audit('2fa.user.regenerate', '用户 ' . $u['username'] . ' #' . $u['id'], '旧恢复码已作废', 'warn', 'user', $u['id'], $u['username']);
            $this->flash('已生成新的恢复码，旧的恢复码全部作废。请立即保存。');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/user/security');
    }

    public function twofaDisable()
    {
        $this->requirePost();
        $u = Db::name('user')->find($this->user['id']);
        if (!password_verify((string)$this->post('password'), $u['password'])) {
            $this->flash('登录密码不正确', 'danger');

            return $this->redirectTo('index/user/security');
        }
        try {
            \app\common\service\TwoFactorService::verify('user', $u, (string)$this->post('code'), true);
            \app\common\service\TwoFactorService::disable('user', $u);
            $this->flash('两步验证已关闭', 'warning');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/user/security');
    }
    public function balance()
    {
        list($logs, $total, $page, $size) = $this->paginate(Db::name('balance_log')->where('user_id', $this->user['id'])->order('id desc'), 20);

        return $this->fetch('user/balance', ['title' => '余额明细', 'logs' => $logs, 'total' => $total, 'page' => $page, 'size' => $size]);
    }
    public function recharge()
    {
        if ($this->request->isPost()) {
            try {
                $amount = round((float)$this->post('amount'), 2);
                if ($amount <= 0) {
                    throw new \Exception('充值金额必须大于 0');
                }$o = OrderService::create($this->user, 'recharge', '余额充值', [], $amount, false);

                return $this->redirectTo('index/pay/index', ['id' => $o['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('user/recharge', ['title' => '余额充值']);
    }
    public function card()
    {
        $need = CardService::needCaptcha($this->user['id'], client_ip());
        if ($this->request->isPost()) {
            try {
                $ok = true;
                if ($need) {
                    $ok = Captcha::check($this->post('captcha'), 'card');
                }if (!$ok) {
                    throw new \Exception('图形验证码错误');
                }$v = CardService::redeem($this->user, $this->post('code'), client_ip(), $ok);
                $this->flash('充值成功：' . money($v), 'success');
                $need = false;
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
                $need = CardService::needCaptcha($this->user['id'], client_ip());
            }
        }

        return $this->fetch('user/card', ['title' => '卡密充值', 'needCaptcha' => $need]);
    }
    public function tiers()
    {
        $tiers = Db::name('user_tier')->where('status', 1)->where('purchasable', 1)->order('level asc')->select();
        if ($this->request->isPost()) {
            $tier = Db::name('user_tier')->where('id', (int)$this->post('tier_id'))->where('status', 1)->where('purchasable', 1)->find();
            if (!$tier) {
                return $this->abort('会员等级不存在', 404);
            }$o = OrderService::create($this->user, 'tier', '升级会员：' . $tier['name'], ['tier_id' => $tier['id']], $tier['price'], false);

            return $this->redirectTo('index/pay/index', ['id' => $o['id']]);
        }

        return $this->fetch('user/tiers', ['title' => '会员等级', 'tiers' => $tiers, 'current' => TierService::current($this->user)]);
    }
    public function verify()
    {
        if ($this->request->isPost()) {
            $row = Db::name('verify_code')->where(['target' => $this->user['email'], 'scene' => 'verify', 'code' => trim($this->post('email_code')), 'used' => 0])->where('expire_time', '>', time())->order('id desc')->find();
            if (!$row) {
                $this->flash('验证码无效', 'danger');

                return $this->back();
            }Db::name('verify_code')->where('id', $row['id'])->update(['used' => 1]);
            Db::name('user')->where('id', $this->user['id'])->update(['email_verified' => 1]);
            $this->flash('邮箱已验证');

            return $this->redirectTo('index/user/index');
        }

        return $this->fetch('auth/verify', ['title' => '验证邮箱']);
    }
    public function sendverify()
    {
        return $this->redirectTo('index/auth/sendcode');
    }
    public function notices()
    {
        return $this->fetch('user/notices', ['title' => '站内通知', 'notices' => Db::name('user_notice')->where('user_id', $this->user['id'])->order('id desc')->select()]);
    }
    public function readNotice()
    {
        if ($this->request->isPost()) {
            Db::name('user_notice')->where('id', (int)$this->post('id'))->where('user_id', $this->user['id'])->update(['is_read' => 1]);
        }

        return $this->back('index/user/notices');
    }
}
