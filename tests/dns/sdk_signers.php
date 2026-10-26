<?php
$root='/workspace/idcdns/src/core/application';spl_autoload_register(function($c)use($root){if(strpos($c,'app\\')===0){$p=$root.'/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p))require_once $p;}});
$name=isset($argv[1])?$argv[1]:'';$cfg=array('secret_id'=>'AKIDEXAMPLE','secret_key'=>'SECRETEXAMPLE','access_key_id'=>'AKIDEXAMPLE','access_key_secret'=>'SECRETEXAMPLE','access_key'=>'AKIDEXAMPLE','_fixed_time'=>1700000000,'endpoint'=>'http://127.0.0.1:18990/'.$name);
try { if($name==='Dnspod'){$d=new app\common\dns\Dnspod($cfg);$x=$d->signRequest('DescribeDomainList',array('Limit'=>1,'Offset'=>0),1700000000);$ok=strpos($x['headers']['Authorization'],'TC3-HMAC-SHA256 ')===0;}
elseif($name==='Aliyun'){$d=new app\common\dns\Aliyun($cfg);$x=$d->signRequest('DescribeDomains',array('PageNumber'=>1),'fixed-nonce',1700000000);$ok=(string)$x['signature']!=='';}
elseif($name==='Volcengine'){$d=new app\common\dns\Volcengine($cfg);$x=$d->signRequest('ListZones',array('PageNumber'=>1),1700000000);$ok=strpos($x['headers']['Authorization'],'HMAC-SHA256 ')===0;}
elseif($name==='Jdcloud'){$d=new app\common\dns\Jdcloud($cfg);$x=$d->signRequest('GET','/v2/regions/cn-north-1/domain',array(),' ',1700000000);$ok=strpos($x['headers']['Authorization'],'JDCLOUD2-HMAC-SHA256 ')===0;}
elseif($name==='Huawei'){$d=new app\common\dns\Huawei($cfg);$x=$d->sign('GET','/v2/zones','type=public','2025-01-01T00:00:00Z');$ok=strpos($x['Authorization'],'SDK-HMAC-SHA256 ')===0;}
elseif($name==='Baidu'){$d=new app\common\dns\Baidu($cfg);$x=$d->sign('GET','/v1/dns/zone','','','2025-01-01T00:00:00Z');$ok=strpos($x['Authorization'],'bce-auth-v1/')===0;}
else $ok=false; exit($ok?0:1); } catch(Exception $e){fwrite(STDERR,$e->getMessage());exit(1);}
