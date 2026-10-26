<?php

namespace app\admin\controller;

use app\common\service\MarketService;
use think\Db;

/**
 * 提现审核：只能「已打款」或「驳回（退回余额）」，不能直接改金额或状态
 */
class Withdraw extends Crud
{
    /** 修改密钥/接口配置、审批等敏感操作需要二次验证（已开启两步验证的管理员） */
    protected $sudoActions = ['edit:post'];
    protected $table = 'withdraw';
    protected $title = '提现审核';
    protected $canAdd = false;
    protected $canDelete = false;
    protected $searchFields = ['account', 'method', 'admin_note'];

    protected function statusOptions()
    {
        return ['pending' => '待处理', 'paid' => '已打款', 'rejected' => '已驳回（已退回余额）', 'approved' => '已通过'];
    }

    protected function fields()
    {
        return [
            'user_id' => ['用户', 'type' => 'number'],
            'amount' => ['提现金额', 'type' => 'number'],
            'fee' => ['手续费', 'type' => 'number'],
            'method' => ['收款方式', 'type' => 'text'],
            'account' => ['收款账号', 'type' => 'text'],
            'status' => ['状态', 'type' => 'select', 'options' => $this->statusOptions()],
            'admin_note' => ['处理备注', 'type' => 'text'],
            'create_time' => ['申请时间', 'type' => 'text'],
            'handle_time' => ['处理时间', 'type' => 'text'],
        ];
    }

    protected function listQuery()
    {
        $q = parent::listQuery();
        $st = (string) $this->request->get('status', '');
        if ($st !== '' && isset($this->statusOptions()[$st])) {
            $q->where('status', $st);
        }

        return $q;
    }

    protected function decorateList(array $list)
    {
        $list = $this->userLabels($list);
        foreach ($list as &$r) {
            $r['amount_text'] = money($r['amount']);
            $r['fee_text'] = money($r['fee']);
            $r['actual'] = round($r['amount'] - $r['fee'], 2);
        }
        unset($r);

        return $list;
    }

    /** 处理页：显示申请详情 + 打款/驳回 */
    public function edit()
    {
        $id = (int) $this->request->param('id', 0);
        $w = Db::name('withdraw')->find($id);
        if (!$w) {
            $this->abort('提现申请不存在', 404);
        }
        if ($this->request->isPost()) {
            try {
                $action = (string) $this->request->post('action', '');
                if (!in_array($action, ['paid', 'reject'], true)) {
                    throw new \Exception('请选择处理结果');
                }
                $note = trim((string) $this->request->post('admin_note', ''));
                if ($action === 'reject' && $note === '') {
                    throw new \Exception('驳回时请填写原因，用户会看到');
                }
                MarketService::handleWithdraw($id, $action === 'paid', $note);
                $this->flash($action === 'paid' ? '已标记为已打款' : '已驳回，金额已退回用户余额');

                return $this->redirectTo('admin/withdraw/index');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->back();
            }
        }
        $user = Db::name('user')->find($w['user_id']);
        $info = sprintf(
            '用户：%s #%d　金额：%s　手续费：%s　实际应打款：%s　收款方式：%s　账号：%s　状态：%s',
            $user ? $user['username'] : '已删除',
            $w['user_id'],
            money($w['amount']),
            money($w['fee']),
            money($w['amount'] - $w['fee']),
            $w['method'],
            $w['account'],
            $this->statusOptions()[$w['status']]
        );
        $fields = ['info' => ['申请详情', 'type' => 'html', 'help' => $info]];
        if ($w['status'] === 'pending') {
            $fields['action'] = ['处理结果', 'type' => 'select', 'required' => true, 'options' => [
                '' => '请选择', 'paid' => '我已线下打款，标记已打款', 'reject' => '驳回（金额退回用户余额）',
            ]];
            $fields['admin_note'] = ['备注 / 驳回原因', 'type' => 'text', 'help' => '例如打款流水号；驳回时必填'];
        } else {
            $fields['done'] = ['处理结果', 'type' => 'html', 'help' => '已于 ' . fmt_time($w['handle_time']) . ' 处理，备注：' . ($w['admin_note'] !== '' ? $w['admin_note'] : '无')];
        }
        $this->assign('pageTitle', '处理提现申请');

        return $this->fetch('common/crud_form', ['fields' => $fields, 'row' => [], 'id' => $id, 'ctrl' => 'withdraw']);
    }

    protected function rowActions()
    {
        return [];
    }

    protected function topActions()
    {
        return [
            ['label' => '只看待处理', 'url' => url_to('admin/withdraw/index', ['status' => 'pending'])],
            ['label' => '全部', 'url' => url_to('admin/withdraw/index')],
        ];
    }
}
