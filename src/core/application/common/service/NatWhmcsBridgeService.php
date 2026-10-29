<?php
namespace app\common\service;

use app\common\upstream\UpstreamFactory;
use think\Db;

class NatWhmcsBridgeService
{
    public static function tokenHash()
    {
        return (string) setting('nat_whmcs_bridge_hash', '');
    }

    private static function bridgeId()
    {
        $id = (string) setting('nat_whmcs_bridge_id', '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new \Exception('WHMCS 桥接身份尚未初始化');
        return $id;
    }

    public static function authenticate($token)
    {
        $hash = self::tokenHash();
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/i', $token) && $hash !== '' && hash_equals($hash, hash('sha256', $token));
    }

    public static function nodes()
    {
        $out = [];
        foreach (Db::name('nat_node')->order('id asc')->select() as $n) {
            $state = NatNodeService::state($n);
            if ($state['code'] !== 'online' || !(int) $n['upstream_id']) continue;
            $up = Db::name('upstream')->find((int) $n['upstream_id']);
            if (!$up || $up['driver'] !== 'natnode' || !(int) $up['status']) continue;
            list($used, $capacity) = NatNodeService::capacity($n);
            $o = NatNodeService::options($n);
            $reported = isset($o['reported']) && is_array($o['reported']) ? $o['reported'] : [];
            $images = isset($reported['images']) ? $reported['images'] : [];
            if (is_string($images)) $images = array_values(array_filter(array_map('trim', explode(',', $images))));
            $out[] = ['id'=>(int)$n['id'], 'name'=>(string)$n['name'], 'state'=>$state['code'], 'public_ip'=>(string)$n['public_ip'], 'used'=>$used, 'capacity'=>$capacity, 'available'=>max(0,(int)$capacity-(int)$used), 'images'=>(array)$images];
        }
        return $out;
    }

    private static function validExternal($id)
    {
        return is_scalar($id) && preg_match('/^[0-9]{1,20}$/', (string)$id);
    }

    private static function instanceName($hash, $external)
    {
        return 'w' . substr($hash, 0, 5) . '-' . (string)$external;
    }

    private static function row($external)
    {
        $id = self::bridgeId();
        return Db::name('whmcs_nat_instance')->where('bridge_id', $id)->where('external_id', (string)$external)->find();
    }

    private static function driver($nodeId)
    {
        $n = Db::name('nat_node')->find((int)$nodeId);
        if (!$n || !(int)$n['upstream_id']) throw new \Exception('NAT 节点不存在');
        $up = Db::name('upstream')->find((int)$n['upstream_id']);
        if (!$up || $up['driver'] !== 'natnode') throw new \Exception('NAT 节点上游无效');
        return [UpstreamFactory::make('natnode', decrypt_json($up['config'])), $n];
    }

    private static function metadata($n)
    {
        $state = NatNodeService::state($n); list($used, $capacity) = NatNodeService::capacity($n);
        return ['id'=>(int)$n['id'], 'name'=>(string)$n['name'], 'state'=>$state['code'], 'public_ip'=>(string)$n['public_ip'], 'used'=>$used, 'capacity'=>$capacity];
    }

    public static function get($external)
    {
        if (!self::validExternal($external)) throw new \Exception('external_id 必须为 1-20 位数字');
        $r = self::row($external); if (!$r) throw new \Exception('实例不存在', 404);
        if ($r['status'] === 'terminated') return ['external_id'=>(string)$external, 'node_id'=>(int)$r['node_id'], 'node_name'=>'', 'instance'=>null, 'status'=>'terminated'];
        list($driver, $n) = self::driver($r['node_id']);
        return ['external_id'=>(string)$external, 'node_id'=>(int)$n['id'], 'node_name'=>(string)$n['name'], 'node'=>self::metadata($n), 'instance'=>$driver->getService($r['instance_name'])];
    }

    public static function create(array $p)
    {
        $external = isset($p['external_id']) ? (string)$p['external_id'] : '';
        if (!self::validExternal($external)) throw new \Exception('external_id 必须为 1-20 位数字');
        $image=isset($p['image'])?(string)$p['image']:'debian12'; $password=isset($p['password'])?(string)$p['password']:''; $hostname=isset($p['hostname'])?(string)$p['hostname']:'';
        $limits=['cpu'=>[1,128],'memory_mb'=>[64,1048576],'disk_gb'=>[1,100000],'nat_ports'=>[0,10000],'bandwidth_mbps'=>[0,1000000]];
        if(!preg_match('/^[a-z0-9]{2,24}$/',$image)) throw new \Exception('系统镜像代码无效');
        if(strlen($password)<8||strlen($password)>128||preg_match('/[\r\n]/',$password)) throw new \Exception('服务密码需为 8-128 个字符且不能包含换行');
        if($hostname!==''&&!preg_match('/^[A-Za-z0-9.-]{1,63}$/',$hostname)) throw new \Exception('主机名格式无效');
        foreach($limits as $k=>$range){$v=isset($p[$k])?$p[$k]:($k==='cpu'?1:($k==='memory_mb'?512:($k==='disk_gb'?10:0)));if(!is_numeric($v)||(string)(int)$v!==(string)$v||(int)$v<$range[0]||(int)$v>$range[1])throw new \Exception($k.' 参数超出允许范围');}
        $old = self::row($external);
        if ($old && $old['status'] === 'terminated') throw new \Exception('external_id 已终止，不可复用');
        if ($old && $old['status'] === 'active') return self::get($external);
        $bridgeId = self::bridgeId(); $name = self::instanceName($bridgeId, $external); $now = time();
        $nodes = [];
        if ($old && in_array($old['status'], ['creating','failed'], true)) {
            $locked = Db::name('nat_node')->find((int) $old['node_id']);
            if (!$locked && (int) $old['node_id'] > 0) throw new \Exception('该服务绑定的 NAT 节点已删除，请先恢复节点记录后重试');
            if ($locked) {
                try { list($probe, $pn) = self::driver($locked['id']); if (method_exists($probe, 'findService')) { $found = $probe->findService($old['instance_name']); if ($found !== null) { Db::name('whmcs_nat_instance')->where('id', $old['id'])->update(['status'=>'active','update_time'=>time()]); return self::get($external); } } } catch (\Exception $ignore) {}
                $nodes[] = ['n'=>$locked, 'used'=>0, 'cap'=>1];
            }
        }
        if (!$nodes) foreach (Db::name('nat_node')->order('id asc')->select() as $n) {
            $st = NatNodeService::state($n); if ($st['code'] !== 'online' || !(int)$n['upstream_id']) continue;
            $up = Db::name('upstream')->find((int)$n['upstream_id']); if (!$up || $up['driver'] !== 'natnode' || !(int)$up['status']) continue;
            list($used, $cap) = NatNodeService::capacity($n); if ($cap > 0 && $used >= $cap) continue;
            $nodes[] = ['n'=>$n, 'used'=>$used, 'cap'=>$cap];
        }
        usort($nodes, function($a,$b) { $ra=$a['cap'] ? $a['used']/$a['cap'] : 1; $rb=$b['cap'] ? $b['used']/$b['cap'] : 1; return $ra == $rb ? ((int)$a['n']['id']-(int)$b['n']['id']) : ($ra < $rb ? -1 : 1); });
        if (!$nodes) throw new \Exception('没有在线且有容量的 NAT 节点');
        $node = $nodes[0]['n'];
        $data = ['bridge_id'=>$bridgeId, 'external_id'=>$external, 'node_id'=>(int)$node['id'], 'instance_name'=>$name, 'status'=>'creating', 'create_time'=>$now, 'update_time'=>$now];
        if ($old) Db::name('whmcs_nat_instance')->where('id', $old['id'])->update($data); else Db::name('whmcs_nat_instance')->insert($data);
        try {
            list($driver, $node) = self::driver($node['id']);
            $payload = ['instance_name'=>$name, 'os'=>isset($p['image'])?$p['image']:'debian12', 'password'=>isset($p['password'])?$p['password']:'', 'hostname'=>isset($p['hostname'])?$p['hostname']:'', 'billingcycle'=>isset($p['billingcycle'])?$p['billingcycle']:'', 'options'=>['cpu'=>isset($p['cpu'])?(int)$p['cpu']:1, 'memory_mb'=>isset($p['memory_mb'])?(int)$p['memory_mb']:512, 'disk_gb'=>isset($p['disk_gb'])?(int)$p['disk_gb']:10, 'nat_ports'=>isset($p['nat_ports'])?(int)$p['nat_ports']:0, 'bandwidth_mbps'=>isset($p['bandwidth_mbps'])?(int)$p['bandwidth_mbps']:0]];
            $instance = $driver->createService($payload);
            Db::name('whmcs_nat_instance')->where('id', $old ? $old['id'] : Db::name('whmcs_nat_instance')->getLastInsID())->update(['status'=>'active','update_time'=>time()]);
            return ['external_id'=>$external, 'node_id'=>(int)$node['id'], 'node_name'=>(string)$node['name'], 'instance'=>$instance];
        } catch (\Exception $e) { Db::name('whmcs_nat_instance')->where('bridge_id',$bridgeId)->where('external_id',$external)->update(['status'=>'creating','update_time'=>time()]); throw $e; }
    }

    public static function action($external, $action, array $p = [])
    {
        $r=self::row($external); if (!$r || $r['status'] === 'terminated') throw new \Exception('实例不存在',404); list($d,$n)=self::driver($r['node_id']);
        if ($action==='suspend') $d->suspend($r['instance_name']); elseif ($action==='unsuspend') $d->unsuspend($r['instance_name']); elseif (in_array($action,['start','stop','restart','reset_password'],true)) $d->action($r['instance_name'],$action,$action==='reset_password'?['password'=>isset($p['password'])?$p['password']:'']:[]); else throw new \Exception('不支持的操作');
        return self::get($external);
    }

    public static function terminate($external)
    {
        if (!self::validExternal($external)) throw new \Exception('external_id 必须为 1-20 位数字');
        $r=self::row($external); if (!$r) return ['external_id'=>(string)$external,'status'=>'terminated']; if ($r['status']==='terminated') return ['external_id'=>(string)$external,'status'=>'terminated'];
        list($d,$n)=self::driver($r['node_id']);
        if (method_exists($d,'findService') && $d->findService($r['instance_name']) !== null) $d->terminate($r['instance_name']);
        Db::name('whmcs_nat_instance')->where('id',$r['id'])->update(['status'=>'terminated','update_time'=>time()]); return ['external_id'=>(string)$external,'status'=>'terminated'];
    }
}
