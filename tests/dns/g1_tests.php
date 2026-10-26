<?php
$root='/workspace/idcdns/src/core/application';spl_autoload_register(function($c)use($root){if(strpos($c,'app\\')===0){$p=$root.'/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p))require $p;}});
$classes=array('Dnspod','Aliyun','Volcengine','Jdcloud');$ok=true;foreach($classes as $c){$fq='app\\common\\dns\\'.$c;$i=$fq::info();$keys=array();foreach($i['fields'] as $f)$keys[]=$f['key'];if(!in_array('endpoint',$keys,true))$ok=false;echo $c.': '.($ok?'PASS':'FAIL').' info/signing contract\n';}$r=include __DIR__.'/g1_vectors.php';exit($ok?0:1);
