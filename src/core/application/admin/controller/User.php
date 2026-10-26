<?php

namespace app\admin\controller;

use app\common\service\OrderService;
use think\Db;

class User extends OpsBase
{
    protected $table = 'user';
    protected $title = '用户管理';
    protected $searchFields = array('email', 'username', 'remark');
    protected function fields()
    {
        return array('email' => array('邮箱', 'type' => 'text', 'list' => true), 'username' => array('用户名', 'type' => 'text', 'list' => true), 'password' => array('密码', 'type' => 'password', 'list' => false, 'help' => '新增时必填；编辑时留空表示不修改。8-64 位，需同时包含字母和数字。修改后该用户所有设备会退出登录'), 'tier_id' => array('会员等级', 'type' => 'number', 'list' => true), 'balance' => array('余额', 'type' => 'number', 'list' => true, 'readonly' => true, 'help' => '余额只能在用户详情页或「余额调整」里修改，会记录流水'), 'status' => array('状态', 'type' => 'switch', 'list' => true), 'email_verified' => array('邮箱已验证', 'type' => 'switch', 'list' => true), 'remark' => array('备注', 'type' => 'textarea', 'list' => false));
    }
    protected $sudoActions = array('edit:post', 'balance', 'reset2fa');

    protected function beforeSave(array $data, $id)
    {
        // CRUD 的 password 类型会做可逆加密，这里改用原始输入做 password_hash
        unset($data['password'], $data['balance']);
        $raw = (string)$this->request->post('password', '');
        if ($raw !== '') {
            $err = password_strength_error($raw, isset($data['username']) ? $data['username'] : '', isset($data['email']) ? $data['email'] : '');
            if ($err !== '') {
                throw new \Exception($err);
            }
            $data['password'] = password_hash($raw, PASSWORD_DEFAULT);
        } elseif (!$id) {
            throw new \Exception('新增用户必须设置密码');
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
            if (Db::name('user')->where('email', $data['email'])->where('id', '<>', (int)$id)->count()) {
                throw new \Exception('邮箱已被其他用户使用');
            }
        }
        if (isset($data['username']) && Db::name('user')->where('username', $data['username'])->where('id', '<>', (int)$id)->count()) {
            throw new \Exception('用户名已存在');
        }
        if (!$id) {
            $data['create_time'] = time();
        }

        return $data;
    }

    protected function afterSave($id, array $data)
    {
        if (isset($data['password'])) {
            audit('password.user.changed', '用户#' . $id, '由管理员设置', 'warn');
        }
    }

    protected function rowActions()
    {
        return array(array('label' => '重置两步验证', 'post' => true, 'style' => 'warning', 'confirm' => '确定重置该用户的两步验证吗？用户下次登录只需密码。', 'url' => function ($r) {
            return url_to('admin/user/reset2fa');
        }, 'when' => function ($r) {
            return !empty($r['totp_enabled']);
        }));
    }

    /** 用户换手机/丢手机时，由管理员重置两步验证（需二次验证，写审计，通知用户） */
    public function reset2fa()
    {
        $this->requirePost();
        $row = $this->row('user', (int)$this->request->post('id'));
        \app\common\service\TwoFactorService::disable('user', $row, $this->admin['username'], '管理员重置');
        $this->flash('已重置用户 ' . $row['username'] . ' 的两步验证');

        return $this->back();
    }
    public function balance()
    {
        $this->requirePost();
        $id = (int)$this->request->post('id');
        $amount = (float)$this->request->post('amount');
        $remark = (string)$this->request->post('remark');

        return $this->perform(function () use ($id, $amount, $remark) {
            if ($amount == 0) {
                throw new \Exception('调整金额不能为零');
            }
            // 统一走余额服务：记录流水、大额需要密码（+ 谷歌验证码）确认
            $after = \app\common\service\BalanceService::adjust($this->admin, $id, $amount, $remark !== '' ? $remark : '管理员调整余额', array('admin_password' => (string)$this->request->post('admin_password'), 'admin_2fa_code' => (string)$this->request->post('admin_2fa_code')));

            return '余额调整成功，当前余额 ' . money($after);
        });
    }
    public function detail()
    {
        $id = (int)$this->request->param('id');

        return $this->fetch('user/detail', array('user' => $this->row('user', $id), 'orders' => Db::name('order')->where('user_id', $id)->order('id desc')->limit(20)->select(), 'services' => Db::name('service')->where('user_id', $id)->select(), 'subs' => Db::name('dns_subdomain')->where('user_id', $id)->select()));
    }
}
