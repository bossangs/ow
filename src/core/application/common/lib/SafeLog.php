<?php

namespace app\common\lib;

/**
 * 安全日志驱动：写入 .php 文件并带 exit 头，防止被 Web 直接读取
 */
class SafeLog
{
    protected $config = ['path' => '', 'max_files' => 30];

    public function __construct($config = [])
    {
        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }
    }

    public function save(array $log = [], $append = false)
    {
        if (empty($log)) {
            return true;
        }
        $dir = rtrim($this->config['path'], '/\\') . DIRECTORY_SEPARATOR . date('Ym') . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . date('d') . '.php';
        $lines = '';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : (PHP_SAPI === 'cli' ? 'cli' : '');
        foreach ($log as $type => $vals) {
            foreach ((array)$vals as $msg) {
                if (!is_string($msg)) {
                    $msg = var_export($msg, true);
                }
                $lines .= '[' . date('Y-m-d H:i:s') . '][' . $type . '] ' . $uri . ' ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
            }
        }
        if (!is_file($file)) {
            @file_put_contents($file, "<?php exit; ?>\n");
        }
        @file_put_contents($file, $lines, FILE_APPEND | LOCK_EX);

        return true;
    }
}
