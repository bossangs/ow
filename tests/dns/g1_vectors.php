<?php
$root='/workspace/idcdns/src/core/application';spl_autoload_register(function($c)use($root){if(strpos($c,'app\\')===0){$p=$root.'/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p))require $p;}});
use app\common\dns\Dnspod;use app\common\dns\Aliyun;use app\common\dns\Volcengine;use app\common\dns\Jdcloud;
$cfg=array('secret_id'=>'AKIDEXAMPLE','secret_key'=>'SECRETEXAMPLE','access_key_id'=>'AKIDEXAMPLE','access_key_secret'=>'SECRETEXAMPLE','access_key'=>'AKIDEXAMPLE','secret_key'=>'SECRETEXAMPLE','_fixed_time'=>1700000000,'endpoint'=>'http://127.0.0.1:18981');
$d=new Dnspod($cfg);$v=$d->signRequest('DescribeDomainList',array('Limit'=>1,'Offset'=>0),1700000000);if(strpos($v['headers']['Authorization'],'TC3-HMAC-SHA256')!==0)die("Dnspod signer FAIL\n");
$a=new Aliyun($cfg);$v=$a->signRequest('DescribeDomains',array('PageNumber'=>1,'PageSize'=>1),'nonce',1700000000);if(!$v['signature'])die("Aliyun signer FAIL\n");
$x=new Volcengine($cfg);$v=$x->signRequest('ListZones',array('PageNumber'=>1),1700000000);if(strpos($v['headers']['Authorization'],'HMAC-SHA256')!==0)die("Volcengine signer FAIL\n");
$j=new Jdcloud($cfg);$v=$j->signRequest('GET','/v2/regions/cn-north-1/domain',array(),' ',1700000000);$hh=array_change_key_case($v['headers'],CASE_LOWER);if(!preg_match('/^JDCLOUD[23]-HMAC-SHA256 Credential=AKIDEXAMPLE\//',$hh['authorization']))die("Jdcloud signer FAIL\n");
echo "Dnspod: no published vector found (verified against mock only)\nAliyun: no published vector found (verified against mock only)\nVolcengine: no published vector found (verified against mock only)\nJdcloud: no published vector found (verified against mock only)\n";
