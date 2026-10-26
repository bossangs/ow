<?php

namespace app\api\controller;

use app\common\controller\Base;
use app\common\service\CronService;
use think\Response;

/**
 * Web 计划任务入口（适合没有 crontab 的虚拟主机）
 * 地址：/index.php?s=/api/cron/run&key=密钥
 * - 密钥错误返回 403；同一时间只允许一个实例（文件锁）；
 * - 每次限时（cron_time_budget，默认 25 秒），未完成的任务下次继续；
 * - 不依赖 exec/shell_exec/proc_open。
 */
class Cron extends Base
{
    protected $noCsrf = ['run'];

    public function run()
    {
        $key = (string)$this->request->param('key', '');
        if (!defined('IDC_CRON_CLI')) {
            if (setting('cron_web_enable', '1') !== '1') {
                return $this->httpFail('Web 计划任务已关闭', 403);
            }
            if ($key === '' || !hash_equals((string)setting('cron_key', ''), $key)) {
                return $this->httpFail('计划任务密钥错误', 403);
            }
        }
        $budget = max(5, min(120, (int)setting('cron_time_budget', '25')));
        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit($budget + 30);
        }
        $dir = DATA_PATH . 'runtime/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $fp = @fopen($dir . 'cron.lock', 'c');
        if (!$fp || !@flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                @fclose($fp);
            }

            return $this->httpFail('计划任务正在运行', 409);
        }
        try {
            $started = microtime(true);
            $log = CronService::run(defined('IDC_CRON_CLI') ? max($budget, 120) : $budget);

            return $this->ok('计划任务执行完成', [
                'started_at' => time(),
                'elapsed' => round(microtime(true) - $started, 3),
                'tasks' => $log,
            ]);
        } catch (\Exception $e) {
            return $this->httpFail('计划任务失败：' . $e->getMessage(), 500);
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /** 返回真实 HTTP 状态码，方便 cron-job.org / 主机面板识别失败 */
    protected function httpFail($msg, $code)
    {
        return Response::create(['code' => $code, 'msg' => $msg], 'json', $code);
    }
}
