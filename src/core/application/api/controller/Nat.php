<?php
namespace app\api\controller;
use app\common\controller\Base;
use app\common\service\NatNodeService;
use app\common\service\NatWhmcsBridgeService;
use think\Db;
class Nat extends Base
{
    protected $noCsrf = ['script','register','heartbeat','whmcsInfo','whmcsCreate','whmcsGet','whmcsAction','whmcsTerminate'];
    private function body() { $d=json_decode((string)file_get_contents('php://input'),true); return is_array($d)?$d:[]; }
    private function nodeByToken($id,$token) { $n=$id>0?Db::name('nat_node')->find($id):null; return !$n||$token===''||(string)$n['reg_token']===''||!hash_equals((string)$n['reg_token'],$token)||(int)$n['reg_expire']<time()?null:$n; }
    private function bridgeAuth() { $h=isset($_SERVER['HTTP_AUTHORIZATION'])?(string)$_SERVER['HTTP_AUTHORIZATION']:''; if($h===''&&function_exists('getallheaders')){foreach((array)getallheaders() as $k=>$v)if(strtolower((string)$k)==='authorization'){$h=(string)$v;break;}} if(!preg_match('/^Bearer\s+(.+)$/i',$h,$m)||!NatWhmcsBridgeService::authenticate(trim($m[1]))) $this->abort('未授权',401); }
    private function bridgeHttps() { $p=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'||isset($_SERVER['SERVER_PORT'])&&(int)$_SERVER['SERVER_PORT']===443; $hdr=setting('trust_proxy_header',''); if($hdr){$k='HTTP_'.strtoupper(str_replace('-','_',$hdr));$p=$p||isset($_SERVER[$k])&&strtolower((string)$_SERVER[$k])==='https';} return $p; }
    private function bridge($post=false) { $this->bridgeAuth(); if(!$this->bridgeHttps()) $this->abort('桥接 API 必须通过 HTTPS 访问',400); }
    public function whmcsInfo() { $this->bridge(); return $this->ok('ok',['site_name'=>setting('site_name','IDC 云'),'nodes'=>NatWhmcsBridgeService::nodes()]); }
    public function whmcsCreate() { $this->bridge(true); try{return $this->ok('ok',NatWhmcsBridgeService::create($this->body()));}catch(\Exception $e){return $this->fail($e->getMessage(),$e->getCode()>=400?$e->getCode():422);} }
    public function whmcsGet() { $this->bridge(); try{return $this->ok('ok',NatWhmcsBridgeService::get((string)$this->request->get('external_id','')));}catch(\Exception $e){return $this->fail($e->getMessage(),$e->getCode()>=400?$e->getCode():404);} }
    public function whmcsAction() { $this->bridge(true); try{$d=$this->body();return $this->ok('ok',NatWhmcsBridgeService::action((string)$this->request->get('external_id',''),isset($d['action'])?$d['action']:'',$d));}catch(\Exception $e){return $this->fail($e->getMessage(),$e->getCode()>=400?$e->getCode():422);} }
    public function whmcsTerminate() { $this->bridge(true); try{$d=$this->body();return $this->ok('ok',NatWhmcsBridgeService::terminate(isset($d['external_id'])?$d['external_id']:''));}catch(\Exception $e){return $this->fail($e->getMessage(),$e->getCode()>=400?$e->getCode():422);} }
    public function script(){ $n=$this->nodeByToken((int)$this->request->get('node',0),(string)$this->request->get('token','')); if(!$n)return \think\Response::create("echo '[idcnat] 安装链接无效' >&2; exit 1\n",'text',401); try{$s=NatNodeService::renderScript($n);}catch(\Exception $e){return \think\Response::create("echo '[idcnat] 节点配置无效' >&2; exit 1\n",'text',400);} return \think\Response::create($s,'text',200,['Content-Type'=>'text/plain; charset=utf-8','X-IDCNAT-SHA256'=>hash('sha256',$s)]); }
    public function register(){ $d=$this->body();$n=$this->nodeByToken((int)(isset($d['node_id'])?$d['node_id']:0),(string)(isset($d['reg_token'])?$d['reg_token']:''));if(!$n)return $this->fail('注册令牌无效或已过期',403);try{$id=NatNodeService::register($n,$d);return $this->ok('节点注册成功',['node_id'=>(int)$n['id'],'upstream_id'=>$id]);}catch(\Exception $e){return $this->fail($e->getMessage(),422);} }
    public function heartbeat(){ $d=$this->body();$id=(int)(isset($d['node_id'])?$d['node_id']:0);$n=$id>0?Db::name('nat_node')->find($id):null;$t=$n?NatNodeService::agentToken(NatNodeService::options($n)):'';$ts=(string)(isset($d['ts'])?$d['ts']:'');$sig=(string)(isset($d['sign'])?$d['sign']:'');if(!$n||$ts===''||abs(time()-(int)$ts)>300||$t===''||!hash_equals(hash_hmac('sha256',(string)$id.'|'.$ts,$t),$sig))return $this->fail('心跳签名无效',403);$stats=[];foreach(['instances_total','instances_running','ports_used','load1','mem_used_mb','disk_used_gb','version'] as $k)if(isset($d[$k])&&is_scalar($d[$k]))$stats[$k]=$d[$k];Db::name('nat_node')->where('id',$id)->update(['status'=>1,'last_heartbeat'=>time(),'public_ip'=>isset($d['public_ipv4'])?(string)$d['public_ipv4']:$n['public_ip'],'stats'=>json_encode($stats)]);return $this->ok('心跳已更新'); }
}
