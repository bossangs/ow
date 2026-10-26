<?php
// 数据库连接信息由安装程序写入数据目录 config.php，不在此硬编码
$__c = isset($GLOBALS['IDC_CONFIG']['database']) ? $GLOBALS['IDC_CONFIG']['database'] : [];
return array_merge([
    'type'            => 'mysql',
    'hostname'        => '127.0.0.1',
    'database'        => '',
    'username'        => '',
    'password'        => '',
    'hostport'        => '3306',
    'dsn'             => '',
    'params'          => [],
    'charset'         => 'utf8mb4',
    'prefix'          => 'idc_',
    'debug'           => false,
    'deploy'          => 0,
    'rw_separate'     => false,
    'master_num'      => 1,
    'slave_no'        => '',
    'read_master'     => false,
    'fields_strict'   => false,
    'resultset_type'  => 'array',
    'auto_timestamp'  => false,
    'datetime_format' => false,
    'sql_explain'     => false,
    'break_reconnect' => true,
], $__c);
