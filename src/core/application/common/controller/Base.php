<?php

namespace app\common\controller;

use think\Db;
use think\Response;

/**
 * 控制器基类：原生 PHP 视图渲染（无需编译缓存）、CSRF、提示消息
 */
abstract class Base
{
    /** @var \think\Request */
    protected $request;
    protected $module = 'index';
    protected $layout = 'layout';
    /** 不校验 CSRF 的方法（如支付回调） */
    protected $noCsrf = [];
    protected $vars = [];

    public function __construct()
    {
        $this->request = \think\facade\Request::instance();
        $this->module = $this->request->module();
        $this->sendSecurityHeaders();
        $this->initialize();
        if ($this->request->isPost() && !in_array(strtolower($this->request->action()), $this->noCsrf, true)) {
            if (!csrf_check()) {
                $this->abort('页面已过期或请求来源无效，请刷新页面后重试', 419);
            }
        }
    }

    protected function initialize()
    {
    }

    protected function sendSecurityHeaders()
    {
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
    }

    protected function assign($k, $v = null)
    {
        if (is_array($k)) {
            $this->vars = array_merge($this->vars, $k);
        } else {
            $this->vars[$k] = $v;
        }
    }

    /** 渲染视图 core/application/{module}/view/{tpl}.php */
    protected function fetch($tpl, array $vars = [], $layout = null)
    {
        $vars = array_merge($this->vars, $vars);
        $content = $this->renderFile($this->module . '/view/' . $tpl . '.php', $vars);
        $layout = $layout === null ? $this->layout : $layout;
        if ($layout) {
            $vars['content'] = $content;
            $content = $this->renderFile($this->module . '/view/' . $layout . '.php', $vars);
        }

        return Response::create($content, 'html');
    }

    protected function renderFile($rel, array $__vars)
    {
        $__file = CORE_PATH . 'application/' . $rel;
        if (!is_file($__file)) {
            throw new \Exception('视图不存在：' . $rel);
        }
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new \Exception($e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        }

        return ob_get_clean();
    }

    protected function flash($msg, $type = 'success')
    {
        $f = session('flash') ?: [];
        $f[] = [$type, $msg];
        session('flash', $f);
    }

    /** 只接受 POST（防止通过链接/图片触发的状态变更） */
    protected function requirePost()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
    }

    protected function redirectTo($path, array $params = [])
    {
        $url = (strpos($path, 'http') === 0 || strpos($path, '/') === 0) ? $path : url_to($path, $params);

        return Response::create($url, 'redirect', 302);
    }

    protected function back($default = '')
    {
        $ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
            return Response::create($ref, 'redirect', 302);
        }

        return $this->redirectTo($default ?: $this->module . '/index/index');
    }

    protected function ok($msg = '操作成功', array $data = [])
    {
        return Response::create(['code' => 0, 'msg' => $msg, 'data' => $data], 'json');
    }

    protected function fail($msg = '操作失败', $code = 1)
    {
        return Response::create(['code' => $code, 'msg' => $msg], 'json');
    }

    /** 直接终止并输出错误页 */
    protected function abort($msg, $status = 400)
    {
        if ($this->request->isAjax()) {
            $r = Response::create(['code' => $status, 'msg' => $msg], 'json', $status);
        } else {
            $html = $this->renderFile('common/view/error.php', ['msg' => $msg, 'status' => $status]);
            $r = Response::create($html, 'html', $status);
        }
        throw new \think\exception\HttpResponseException($r);
    }

    protected function post($k = null, $d = '')
    {
        $v = $k === null ? $this->request->post() : $this->request->post($k, $d);

        return $v;
    }

    protected function get($k = null, $d = '')
    {
        return $k === null ? $this->request->get() : $this->request->get($k, $d);
    }

    protected function page()
    {
        return max(1, (int)$this->request->get('page', 1));
    }

    /** 简单分页查询 */
    protected function paginate($query, $size = 20)
    {
        $page = $this->page();
        $q2 = clone $query;
        $total = $q2->count();
        $list = $query->page($page, $size)->select();

        return [$list, $total, $page, $size];
    }
}
