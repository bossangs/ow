<?php
require_once dirname(__DIR__,2).'/src/core/application/common/dns/DnsException.php';
require_once dirname(__DIR__,2).'/src/core/application/common/dns/DnsDriver.php';
require_once dirname(__DIR__,2).'/src/core/application/common/dns/Huawei.php';
require_once dirname(__DIR__,2).'/src/core/application/common/dns/Baidu.php';
require_once dirname(__DIR__,2).'/src/core/application/common/dns/West.php';
use app\common\dns\Huawei; use app\common\dns\Baidu; use app\common\dns\West;
$ok=true;
$h=new Huawei(array('access_key'=>'AKIDEXAMPLE','secret_key'=>'secret','endpoint'=>'http://127.0.0.1:18982/huawei'));
$a=$h->sign('GET','/v2/zones','type=public','', '20250101T000000Z');
if(strpos($a['Authorization'],'SDK-HMAC-SHA256 Access=AKIDEXAMPLE')!==0||strpos($a['Authorization'],'SignedHeaders=')===false){echo "Huawei signer FAIL\n";$ok=false;}else echo "Huawei: no published vector found (signer shape checked; mock verification required)\n";
$b=new Baidu(array('access_key'=>'ak','secret_key'=>'sk','endpoint'=>'http://127.0.0.1:18982/baidu'));
$x=$b->sign('GET','/v1/dns/zone','','','2025-01-01T00:00:00Z');
if(strpos($x['Authorization'],'bce-auth-v1/ak/')!==0){echo "Baidu signer FAIL\n";$ok=false;}else echo "Baidu: no published vector found (signer shape checked; mock verification required)\n";
$w=new West(array('username'=>'zhangsan','api_password'=>'5dh232kfg!*','_fixed_time'=>1554691950854));
if($w->token()!=='f17581fb2535b2a7ee4468eb3f96a2a9'){echo "West official MD5 vector FAIL\n";$ok=false;}else echo "West official MD5 vector PASS\n";
exit($ok?0:1);
