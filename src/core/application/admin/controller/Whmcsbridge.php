<?php
namespace app\admin\controller;
use app\common\controller\AdminBase;
class Whmcsbridge extends AdminBase
{
    protected $title='WHMCS NAT 桥接';
    protected $sudoActions=['rotate'];
    public function index()
    {
        $token=session('nat_whmcs_bridge_token'); session('nat_whmcs_bridge_token',null);
        return $this->fetch('whmcsbridge/index',['token'=>$token,'hash'=>setting('nat_whmcs_bridge_hash',''),'created'=>setting('nat_whmcs_bridge_created','')]);
    }
    public function rotate()
    {
        if(!$this->request->isPost()) $this->abort('请求方式错误',405);
        $token=bin2hex(function_exists('random_bytes')?random_bytes(32):openssl_random_pseudo_bytes(32));
        $settings=['nat_whmcs_bridge_hash'=>hash('sha256',$token),'nat_whmcs_bridge_created'=>date('c')];
        if(!preg_match('/^[a-f0-9]{32}$/',(string)setting('nat_whmcs_bridge_id',''))) $settings['nat_whmcs_bridge_id']=bin2hex(function_exists('random_bytes')?random_bytes(16):openssl_random_pseudo_bytes(16));
        setting_save($settings);
        session('nat_whmcs_bridge_token',$token); $this->flash('桥接密钥已轮换；明文只会显示这一次。');
        return $this->redirectTo('admin/whmcsbridge/index');
    }
}
