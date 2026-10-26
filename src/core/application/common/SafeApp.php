<?php

namespace app\common;

/**
 * 将运行时目录迁移到数据目录（数据目录可位于网站根目录之外）
 */
class SafeApp extends \think\App
{
    public function initialize()
    {
        if ($this->initialized) {
            return;
        }
        parent::initialize();
        $this->runtimePath = DATA_PATH . 'runtime' . DIRECTORY_SEPARATOR;
        $this->env->set('runtime_path', $this->runtimePath);
    }
}
