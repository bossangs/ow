<?php
// 日志写入数据目录，文件为 .php 且带 exit 头，即便目录被外网访问也不会泄露内容
return [
    'type'        => '\\app\\common\\lib\\SafeLog',
    'path'        => DATA_PATH . 'runtime/log/',
    'level'       => ['error', 'warning', 'notice', 'sql_error'],
    'close'       => false,
    'max_files'   => 30,
];
