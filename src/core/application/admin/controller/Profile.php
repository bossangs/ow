<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\service\TwoFactorService;
use think\Response;

/**
 * 管理员本人的两步验证（谷歌验证器）：绑定、查看/下载恢复码、重新生成、关闭。
 */
class Profile extends AdminBase
{
    protected $title = '两步验证';

    public function twofa()
    {
        $a = $this->admin;
        $enabled = TwoFactorService::enabled($a);
        $data = [
            'enabled' => $enabled,
            'required' => setting('admin_2fa_required', '0') === '1',
            'codes' => TwoFactorService::pendingCodes('admin', $a['id']),
            'left' => $enabled ? TwoFactorService::recoveryLeft('admin', $a['id']) : 0,
            'bindTime' => (int) $a['totp_bind_time'],
            'issuer' => TwoFactorService::issuer('admin'),
            'locked' => TwoFactorService::lockedFor('admin', $a['id']),
        ];
        if (!$enabled) {
            $secret = TwoFactorService::setupSecret('admin', $a['id'], (bool) $this->request->get('renew'));
            $uri = TwoFactorService::setupUri('admin', $a, $secret);
            $data['secret'] = $secret;
            $data['secretGroups'] = trim(chunk_split($secret, 4, ' '));
            $data['qr'] = TwoFactorService::qrSvg($uri, 220);
        }

        return $this->fetch('profile/twofa', $data);
    }

    /** 扫码后输入第一次验证码，确认绑定 */
    public function enable()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        if (!password_verify((string) $this->request->post('password'), $this->admin['password'])) {
            $this->flash('登录密码不正确', 'danger');

            return $this->redirectTo('admin/profile/twofa');
        }
        try {
            TwoFactorService::enable('admin', $this->admin, (string) $this->request->post('code'), '');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->redirectTo('admin/profile/twofa');
        }
        session('admin_sudo_until', time() + max(1, (int) setting('admin_sudo_minutes', '5')) * 60);
        $this->flash('两步验证已开启。请立即保存下面的 10 个恢复码：手机丢失时只能靠它们登录。');

        return $this->redirectTo('admin/profile/twofa');
    }

    /** 下载恢复码（txt），下载后页面上不再显示 */
    public function codes()
    {
        $codes = TwoFactorService::pendingCodes('admin', $this->admin['id'], true);
        if (!$codes) {
            $this->flash('恢复码只能在生成后查看/下载一次。如果没保存好，请重新生成一组。', 'warning');

            return $this->redirectTo('admin/profile/twofa');
        }
        $site = setting('site_name', 'o2o云');
        $txt = $site . " 后台两步验证恢复码\r\n账号：" . $this->admin['username'] . "\r\n生成时间：" . date('Y-m-d H:i') . "\r\n\r\n"
            . implode("\r\n", $codes)
            . "\r\n\r\n每个恢复码只能使用一次。手机丢失或无法使用验证器时，在输入验证码的地方填写一个恢复码即可登录。\r\n请把本文件保存在安全的地方（例如密码管理器），不要发给任何人。\r\n";
        audit('2fa.admin.codes_download', $this->admin['username'], '');

        return Response::create($txt, 'html')->header([
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="recovery-codes-' . preg_replace('/[^A-Za-z0-9_-]/', '', $this->admin['username']) . '.txt"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** 已保存恢复码：从页面上清除 */
    public function saved()
    {
        if ($this->request->isPost()) {
            TwoFactorService::discardCodes('admin', $this->admin['id']);
            $this->flash('好的，恢复码已从页面上清除');
        }

        return $this->redirectTo('admin/profile/twofa');
    }

    /** 重新生成恢复码（旧的全部作废），需要输入当前验证码 */
    public function regenerate()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        try {
            TwoFactorService::verify('admin', $this->admin, (string) $this->request->post('code'), false);
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->redirectTo('admin/profile/twofa');
        }
        TwoFactorService::regenerateRecovery('admin', $this->admin['id']);
        audit('2fa.admin.regenerate', $this->admin['username'], '旧恢复码已作废', 'warn');
        $this->flash('已生成新的恢复码，旧的恢复码全部作废。请立即保存。');

        return $this->redirectTo('admin/profile/twofa');
    }

    /** 关闭两步验证：需要登录密码 + 当前验证码（或恢复码）；全站强制时不允许关闭 */
    public function disable()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        if (setting('admin_2fa_required', '0') === '1') {
            $this->flash('系统设置要求所有管理员必须开启两步验证，不能关闭。如需更换手机，请先关闭“强制”或让其他超级管理员帮您重置。', 'danger');

            return $this->redirectTo('admin/profile/twofa');
        }
        if (!password_verify((string) $this->request->post('password'), $this->admin['password'])) {
            $this->flash('登录密码不正确', 'danger');

            return $this->redirectTo('admin/profile/twofa');
        }
        try {
            TwoFactorService::verify('admin', $this->admin, (string) $this->request->post('code'), true);
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->redirectTo('admin/profile/twofa');
        }
        TwoFactorService::disable('admin', $this->admin);
        session('admin_sudo_until', null);
        $this->flash('两步验证已关闭', 'warning');

        return $this->redirectTo('admin/profile/twofa');
    }
}
