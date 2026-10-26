<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\payment\PayFactory;
use think\Db;

/**
 * 支付网关：易支付 / BEpusdt / 支付宝 / 余额 / 线下转账 等
 * gateway 表以 code 为主键，每个网关一行，首次进入自动初始化
 */
class Gateway extends AdminBase
{
    /** 修改密钥/接口配置、审批等敏感操作需要二次验证（已开启两步验证的管理员） */
    protected $sudoActions = ['edit:post', 'delete'];
    protected $table = 'gateway';
    protected $title = '支付网关';
    protected $order = 'sort asc';

    protected function fieldsFor($code)
    {
        $all = PayFactory::infoAll();

        return [
            'name' => ['显示名称', 'type' => 'text', 'required' => true, 'help' => '用户在付款页看到的名称'],
            'enabled' => ['启用', 'type' => 'switch'],
            'config' => ['接口配置', 'type' => 'driver-config', 'list' => false, 'drivers' => $all, 'driver' => $code],
            'fee_rate' => ['手续费 %', 'type' => 'number', 'default' => 0, 'help' => '向用户额外收取的比例，0 = 不收'],
            'min_amount' => ['单笔最低金额', 'type' => 'number', 'default' => 0, 'help' => '0 = 不限'],
            'max_amount' => ['单笔最高金额', 'type' => 'number', 'default' => 0, 'help' => '0 = 不限'],
            'sort' => ['排序', 'type' => 'number', 'default' => 0],
        ];
    }

    protected function initRows()
    {
        foreach (PayFactory::infoAll() as $code => $info) {
            if (!Db::name('gateway')->where('code', $code)->count()) {
                Db::name('gateway')->insert(['code' => $code, 'name' => $info['name'], 'enabled' => 0, 'config' => encrypt_json([]), 'sort' => 0]);
            }
        }
    }

    public function index()
    {
        $this->initRows();
        $all = PayFactory::infoAll();
        $list = [];
        foreach (Db::name('gateway')->order('sort asc, code asc')->select() as $r) {
            if (!isset($all[$r['code']])) {
                continue;
            }
            $r['id'] = $r['code'];
            $r['code_text'] = $all[$r['code']]['name'];
            $list[] = $r;
        }
        $fields = [
            'code' => ['网关类型', 'type' => 'text'],
            'name' => ['显示名称', 'type' => 'text'],
            'enabled' => ['启用', 'type' => 'switch'],
            'fee_rate' => ['手续费 %', 'type' => 'number'],
            'sort' => ['排序', 'type' => 'number'],
        ];

        return $this->fetch('common/crud_list', [
            'fields' => $fields, 'list' => $list, 'total' => count($list), 'page' => 1, 'size' => 100,
            'ctrl' => 'gateway', 'search' => false, 'rowActions' => [], 'topActions' => [],
            'canAdd' => false, 'canEdit' => true, 'canDelete' => false,
        ]);
    }

    public function edit()
    {
        $code = (string) $this->request->param('id', '');
        $all = PayFactory::infoAll();
        $row = Db::name('gateway')->where('code', $code)->find();
        if (!$row || !isset($all[$code])) {
            $this->abort('网关不存在', 404);
        }
        $fields = $this->fieldsFor($code);
        if ($this->request->isPost()) {
            try {
                $data = [];
                foreach ($fields as $k => $f) {
                    if ($f['type'] === 'driver-config') {
                        $data[$k] = $this->gatewayConfig($code, $all[$code], $row);
                        continue;
                    }
                    $v = $this->normalizeValue($k, $f, 0);
                    if ($v === null) {
                        continue;
                    }
                    if (!empty($f['required']) && $v === '') {
                        throw new \Exception($f[0] . '不能为空');
                    }
                    $data[$k] = $v;
                }
                if ((float) $data['fee_rate'] < 0 || (float) $data['fee_rate'] > 100) {
                    throw new \Exception('手续费比例应在 0–100 之间');
                }
                Db::name('gateway')->where('code', $code)->update($data);
                audit('admin.gateway.save', '网关 ' . $code, array_diff_key($data, ['config' => 1]));
                $this->flash('保存成功');

                return $this->redirectTo('admin/gateway/index');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->back();
            }
        }
        $this->assign('pageTitle', '支付网关：' . $all[$code]['name']);

        return $this->fetch('common/crud_form', ['fields' => $fields, 'row' => $row, 'id' => 0, 'ctrl' => 'gateway']);
    }

    /** 收集网关配置；敏感字段留空 = 保留原值 */
    protected function gatewayConfig($code, array $info, array $row)
    {
        $old = decrypt_json((string) $row['config']);
        $enabled = (bool) $this->request->post('enabled');
        $out = [];
        foreach ((array) $info['fields'] as $df) {
            $k = $df['key'];
            $x = $this->request->post('cfg_' . $code . '_' . $k, '');
            $x = is_array($x) ? implode(',', $x) : trim((string) $x);
            $secret = !empty($df['secret']) || (isset($df['type']) && $df['type'] === 'password');
            if ($secret && $x === '' && isset($old[$k])) {
                $x = $old[$k];
            }
            if ($enabled && !empty($df['required']) && $x === '') {
                throw new \Exception('启用前请填写：' . $df['label']);
            }
            $out[$k] = $x;
        }

        return encrypt_json($out);
    }

    public function delete()
    {
        $this->abort('支付网关不能删除，可关闭「启用」', 403);
    }
}
