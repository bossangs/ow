<?php

namespace app\common\controller;

use think\Db;

/**
 * 前台基类：加载当前登录用户、站点状态
 */
abstract class FrontBase extends Base
{
    protected $user = null;
    protected $needLogin = false;
    /** 不需要登录的动作（如支付平台异步回调），小写动作名 */
    protected $guestActions = [];

    protected function initialize()
    {
        // 可选的“访问触发”计划任务（默认关闭），在页面发送给浏览器之后执行
        if (setting('cron_visit_trigger', '0') === '1') {
            \think\facade\Hook::add('response_end', function () {
                \app\common\service\CronService::visitTrigger();
            });
        }
        if (setting('site_closed', '0') === '1' && !session('admin_id')) {
            $this->abort(setting('site_closed_msg', '站点维护中，请稍后访问'), 503);
        }
        $uid = (int)session('user_id');
        if ($uid) {
            $u = Db::name('user')->find($uid);
            if ($u && (int)$u['status'] === 1 && session('user_pwd_sig') === substr(sha1($u['password']), 0, 16)) {
                $this->user = $u;
            } else {
                session('user_id', null);
                session('user_name', null);
            }
        }
        $isGuestAction = in_array(strtolower($this->request->action()), $this->guestActions, true);
        if ($this->needLogin && !$this->user && !$isGuestAction) {
            if ($this->request->isAjax()) {
                $this->abort('请先登录', 401);
            }
            $back = $this->request->url();
            throw new \think\exception\HttpResponseException($this->redirectTo('index/auth/login', ['back' => $back]));
        }
        if ($this->needLogin && $this->user && setting('reg_email_verify', '0') === '1' && !$this->user['email_verified']
            && !in_array(strtolower($this->request->controller() . '/' . $this->request->action()), ['user/verify', 'user/sendverify'], true)) {
            throw new \think\exception\HttpResponseException($this->redirectTo('index/user/verify'));
        }
        $this->assign([
            'user' => $this->user,
            'siteName' => setting('site_name', 'IDC 云'),
            'navActive' => strtolower($this->request->controller()),
        ]);
    }
}
