<?php
return [
    'type'           => 'Think',
    'auto_rule'      => 1,
    'view_path'      => '',
    'view_suffix'    => 'html',
    'view_depr'      => DIRECTORY_SEPARATOR,
    'tpl_begin'      => '{',
    'tpl_end'        => '}',
    'taglib_begin'   => '{',
    'taglib_end'     => '}',
    'cache_path'     => DATA_PATH . 'runtime/temp/',
    'cache_suffix'   => 'php',
    // 变量输出默认 HTML 转义，防 XSS；需原样输出时使用 {$var|raw}
    'default_filter' => 'htmlentities',
    'tpl_cache'      => true,
];
