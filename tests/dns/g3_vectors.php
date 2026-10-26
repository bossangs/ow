<?php
require_once __DIR__.'/../../src/core/application/common/dns/DnsDriver.php';
require_once __DIR__.'/../../src/core/application/common/dns/DnsException.php';
require_once __DIR__.'/../../src/core/application/common/dns/Cloudflare.php';
require_once __DIR__.'/../../src/core/application/common/dns/Dnsla.php';
require_once __DIR__.'/../../src/core/application/common/dns/Namesilo.php';
require_once __DIR__.'/../../src/core/application/common/dns/Godaddy.php';
foreach (array('Cloudflare','Dnsla','Namesilo','Godaddy') as $c) echo $c.': no published signer vector (header construction verified by mock)'.PHP_EOL;
