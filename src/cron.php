<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
define('IDC_CRON_CLI', true);
require __DIR__ . '/index.php';
