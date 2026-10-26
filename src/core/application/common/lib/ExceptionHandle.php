<?php

namespace app\common\lib;

use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;

/**
 * 统一异常处理：生产环境不暴露堆栈，记录到安全日志
 */
class ExceptionHandle extends Handle
{
    public function render(\Exception $e)
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }
        if (\think\facade\App::isDebug()) {
            return parent::render($e);
        }
        $code = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $msg = $code == 404 ? '页面不存在' : '系统繁忙，请稍后再试';
        if ($e instanceof ValidateException) {
            $code = 422;
            $msg = $e->getError();
        }
        if (\think\facade\Request::isAjax() || strpos((string)\think\facade\Request::header('accept'), 'json') !== false) {
            return json(['code' => 1, 'msg' => $msg], $code);
        }
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $code . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,"Microsoft YaHei",sans-serif;background:#f5f7fb;color:#334;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}'
            . '.b{background:#fff;padding:40px 48px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.06);text-align:center}h1{font-size:48px;margin:0 0 10px;color:#4f6ef7}a{color:#4f6ef7}</style></head>'
            . '<body><div class="b"><h1>' . $code . '</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p><a href="javascript:history.back()">返回上一页</a></p></div></body></html>';

        return response($html, $code);
    }
}
