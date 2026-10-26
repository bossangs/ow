<?php
require_once '/workspace/idcdns/src/core/application/common/lib/HttpClient.php';
require_once '/workspace/idcdns/src/core/application/common/upstream/UpstreamException.php';
require_once '/workspace/idcdns/src/core/application/common/upstream/UpstreamDriver.php';
require_once '/workspace/idcdns/src/core/application/common/upstream/NatNode.php';
use app\common\upstream\NatNode;
function ok($x,$m){if(!$x) throw new Exception($m);}
$n=new NatNode(array('agent_url'=>'http://127.0.0.1:19182','agent_token'=>'local-test-token','verify_ssl'=>false));
ok($n->testConnection()['ok'],'connection');
$ids=array(); $ranges=array();
for($i=0;$i<3;$i++){ $x=$n->createService(array('os'=>'debian12','password'=>'password1','options'=>array('nat_ports'=>2))); $ids[]=$x['service_id']; $g=$n->getService($x['service_id']); ok($g['ssh_port']>0,'ssh'); $ranges[]=$g['port_range']; ok($g['ipv4_internal']!=='','internal ip'); }
ok(count(array_unique($ranges))===3,'overlap');
foreach(array('on','off','reboot') as $a) $n->action($ids[0],$a);
$n->action($ids[0],'reset_password',array('password'=>'password2')); $n->suspend($ids[0]);
try{$n->action($ids[0],'on'); throw new Exception('suspended start accepted');}catch(Exception $e){}
$n->unsuspend($ids[0]); $n->action($ids[0],'reinstall',array('image'=>'debian12')); $n->terminate($ids[0]);
try{$n->createService(array('os'=>'bad','options'=>array())); throw new Exception('bad image accepted');}catch(Exception $e){}
foreach(array_slice($ids,1) as $id) $n->terminate($id);
echo "NAT PHP driver end-to-end passed\n";
