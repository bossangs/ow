<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\service\CardService;
use think\Db;

class Card extends AdminBase
{
    protected $title = '卡密';
    public function index()
    {
        $list = Db::name('card_batch')->order('id desc')->select();

        return $this->fetch('card/index', ['list' => $list]);
    }
    public function generate()
    {
        if (!$this->request->isPost()) {
            return $this->fetch('card/generate');
        }try {
            $id = CardService::generate($this->admin, ['name' => $this->request->post('name', ''), 'note' => $this->request->post('note', ''), 'face_value' => $this->request->post('face_value', 0), 'qty' => $this->request->post('qty', 0), 'prefix' => $this->request->post('prefix', ''), 'expire_date' => $this->request->post('expire_date', ''), 'per_user_limit' => $this->request->post('per_user_limit', 0)]);
            $this->flash('生成成功');

            return $this->redirectTo('admin/card/index', ['id' => $id]);
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->back();
        }
    }
    public function export()
    {
        try {
            list($name, $content) = CardService::export($this->admin, (int)$this->request->param('id'), $this->request->get('format', 'csv'));
            audit('admin.card.export', '卡密导出#' . $this->request->param('id'));

            return response($content, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->back();
        }
    }
    public function cards()
    {
        $bid = (int)$this->request->param('id');
        $batch = Db::name('card_batch')->find($bid);
        if (!$batch) {
            $this->abort('批次不存在', 404);
        }$cards = Db::name('recharge_card')->where('batch_id', $bid)->order('id desc')->select();
        foreach ($cards as &$c) {
            $c['display_status'] = CardService::statusOf($c);
        }

        return $this->fetch('card/cards', compact('batch', 'cards'));
    }
    public function disable()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }CardService::disable([], (int)$this->request->post('batch_id', 0));
        $this->flash('已停用未使用卡密');

        return $this->back();
    }
    public function delete()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }CardService::delete([], (int)$this->request->post('batch_id', 0));
        $this->flash('已删除未使用/已停用卡密');

        return $this->back();
    }
}
