<?php

namespace app\admin\controller;

use app\common\service\TwoFactorService;
use think\Db;

/**
 * 管理员管理（仅超级管理员）。
 * - 密码保存为 password_hash，并做强度校验；留空表示不修改；
 * - 不能停用/降级/删除自己，系统里至少保留一个启用的超级管理员；
 * - 可以帮其他管理员重置两步验证（换手机、手机丢失），需二次验证并写审计日志。
 */
class Admin extends Crud
{
    protected $table = 'admin';
    protected $title = '管理员';
    protected $superOnly = true;
    protected $searchFields = ['username', 'nickname', 'email'];
    protected $sudoActions = ['edit:post', 'delete', 'reset2fa'];

    protected function columns()
    {
        return [
            'username' => ['用户名', 'type' => 'text', 'required' => true],
            'nickname' => ['昵称', 'type' => 'text'],
            'email' => ['邮箱', 'type' => 'text', 'help' => '用于找回密码、接收密码/两步验证变更提醒'],
            'password' => ['密码', 'type' => 'password', 'list' => false, 'help' => '新增时必填；编辑时留空表示不修改。8-64 位，需同时包含字母和数字'],
            'is_super' => ['超级管理员', 'type' => 'switch', 'help' => '超级管理员可以管理其他管理员、修改登录安全设置'],
            'status' => ['状态', 'type' => 'switch'],
            'totp_enabled' => ['两步验证', 'type' => 'text', 'readonly' => true],
            'login_fail' => ['失败次数', 'type' => 'number'],
            'last_login_time' => ['最后登录', 'type' => 'text', 'readonly' => true],
        ];
    }

    protected function decorateList(array $list)
    {
        foreach ($list as &$r) {
            $r['totp_enabled_html'] = !empty($r['totp_enabled']) ? '<span class="badge bg-success">已开启</span>' : '<span class="badge bg-secondary">未开启</span>';
            $r['is_super_html'] = !empty($r['is_super']) ? '<span class="badge bg-primary">超级管理员</span>' : '<span class="text-muted">普通</span>';
            $r['last_login_time_text'] = !empty($r['last_login_time']) ? date('Y-m-d H:i', $r['last_login_time']) : '-';
            unset($r['password'], $r['totp_secret']);
        }
        unset($r);

        return $list;
    }

    protected function rowActions()
    {
        return [[
            'label' => '重置两步验证',
            'post' => true,
            'style' => 'warning',
            'confirm' => '确定重置该管理员的两步验证吗？重置后他下次登录只需密码，并需重新绑定。',
            'url' => function ($r) {
                return url_to('admin/admin/reset2fa');
            },
            'when' => function ($r) {
                return !empty($r['totp_enabled']);
            },
        ]];
    }

    protected function beforeSave(array $data, $id)
    {
        unset($data['password'], $data['totp_enabled'], $data['last_login_time']);
        $data['username'] = trim($data['username']);
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,32}$/', $data['username'])) {
            throw new \Exception('用户名为 3-32 位字母、数字或 _ . @ -');
        }
        if (Db::name('admin')->where('username', $data['username'])->where('id', '<>', (int) $id)->count()) {
            throw new \Exception('用户名已存在');
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('邮箱格式不正确');
        }
        $data['email'] = strtolower($data['email']);
        $raw = (string) $this->request->post('password', '');
        if ($raw !== '') {
            $err = password_strength_error($raw, $data['username'], $data['email']);
            if ($err !== '') {
                throw new \Exception($err);
            }
            $data['password'] = password_hash($raw, PASSWORD_DEFAULT);
            $data['login_fail'] = 0;
            $data['lock_until'] = 0;
        } elseif (!$id) {
            throw new \Exception('新增管理员必须设置密码');
        }
        if ($id && (int) $id === (int) $this->admin['id']) {
            if (empty($data['is_super']) || empty($data['status'])) {
                throw new \Exception('不能取消自己的超级管理员身份或停用自己');
            }
        }
        if ($id && (empty($data['is_super']) || empty($data['status']))) {
            $this->assertOtherSuperLeft($id);
        }
        if (!$id) {
            $data['create_time'] = time();
        }

        return $data;
    }

    protected function afterSave($id, array $data)
    {
        if (isset($data['password'])) {
            audit('password.admin.changed', '管理员 ' . $data['username'] . ' #' . $id, '由超级管理员设置', 'warn');
        }
    }

    protected function beforeDelete($id)
    {
        if ((int) $id === (int) $this->admin['id']) {
            throw new \Exception('不能删除自己');
        }
        $this->assertOtherSuperLeft($id);
        Db::name('twofa_recovery')->where(['owner_type' => 'admin', 'owner_id' => (int) $id])->delete();
    }

    /** 保证除 $id 外还有至少一个启用的超级管理员 */
    protected function assertOtherSuperLeft($id)
    {
        $row = Db::name('admin')->find((int) $id);
        if ($row && !empty($row['is_super']) && (int) $row['status'] === 1) {
            $others = Db::name('admin')->where('is_super', 1)->where('status', 1)->where('id', '<>', (int) $id)->count();
            if (!$others) {
                throw new \Exception('系统里至少要保留一个启用的超级管理员');
            }
        }
    }

    /** 重置其他管理员的两步验证 */
    public function reset2fa()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        $row = Db::name('admin')->find((int) $this->request->post('id'));
        if (!$row) {
            $this->abort('管理员不存在', 404);
        }
        if ((int) $row['id'] === (int) $this->admin['id']) {
            $this->flash('不能在这里重置自己的两步验证，请到「两步验证」页面操作', 'danger');

            return $this->back();
        }
        TwoFactorService::disable('admin', $row, $this->admin['username'], '超级管理员重置');
        $this->flash('已重置管理员 ' . $row['username'] . ' 的两步验证' . (setting('admin_2fa_required', '0') === '1' ? '，他下次登录后需要重新绑定' : ''));

        return $this->back();
    }
}
