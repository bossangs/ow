<?php

namespace app\admin\controller;

use app\common\upstream\UpstreamFactory;
use think\Db;

/**
 * 上游接口：WHMCS / 智简魔方（业务系统 V10、财务系统）/ NAT 节点
 * 配置按驱动显示独立表单，敏感字段加密保存、留空不修改
 */
class Upstream extends Crud
{
    /** 修改密钥/接口配置、审批等敏感操作需要二次验证（已开启两步验证的管理员） */
    protected $sudoActions = ['edit:post', 'delete'];
    protected $table = 'upstream';
    protected $title = '上游接口';
    protected $searchFields = ['name', 'driver', 'remark'];

    protected function fields()
    {
        $drivers = UpstreamFactory::infoAll();
        $opts = [];
        foreach ($drivers as $code => $info) {
            $opts[$code] = $info['name'];
        }

        return [
            'name' => ['名称', 'type' => 'text', 'required' => true, 'help' => '仅后台显示，如「主站 WHMCS」'],
            'driver' => ['上游类型', 'type' => 'select', 'required' => true, 'options' => $opts],
            'config' => ['连接配置', 'type' => 'driver-config', 'list' => false, 'drivers' => $drivers],
            'status' => ['启用', 'type' => 'switch', 'default' => 1],
            'last_test_msg' => ['最近测试结果', 'type' => 'text', 'readonly' => true],
            'last_test_time' => ['测试时间', 'type' => 'text', 'readonly' => true],
            'remark' => ['备注', 'type' => 'text'],
        ];
    }

    protected function beforeSave(array $d, $id)
    {
        if (!$id) {
            $d['create_time'] = time();
        }

        return $d;
    }

    protected function beforeDelete($id)
    {
        $n = Db::name('product')->where('upstream_id', $id)->count();
        if ($n) {
            throw new \Exception('还有 ' . $n . ' 个产品使用该上游，请先修改这些产品');
        }
    }

    public function test()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        $r = Db::name($this->table)->find((int) $this->request->post('id'));
        if (!$r) {
            $this->abort('上游不存在', 404);
        }
        try {
            $x = UpstreamFactory::make($r['driver'], decrypt_json($r['config']))->testConnection();
            $msg = is_array($x) && isset($x['message']) ? $x['message'] : '连接成功';
            Db::name($this->table)->where('id', $r['id'])->update(['last_test_time' => time(), 'last_test_msg' => mb_substr($msg, 0, 250)]);
            $this->flash('测试连接成功：' . $msg);
        } catch (\Exception $e) {
            Db::name($this->table)->where('id', $r['id'])->update(['last_test_time' => time(), 'last_test_msg' => mb_substr('失败：' . $e->getMessage(), 0, 250)]);
            $this->flash('测试失败：' . $e->getMessage(), 'danger');
        }

        return $this->back();
    }

    /** 上游价格：去掉 null / -1（未开放的周期） */
    public static function cleanPricing($pricing)
    {
        $out = [];
        foreach ((array) $pricing as $c => $v) {
            if ($v !== null && $v !== '' && (float) $v >= 0) {
                $out[$c] = round((float) $v, 2);
            }
        }

        return $out;
    }

    /** 导入上游商品：新商品默认「上游成本加价」模式、未上架，已存在的只更新成本 */
    public function importProducts()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        try {
            $r = Db::name($this->table)->find((int) $this->request->post('id'));
            if (!$r) {
                throw new \Exception('上游不存在');
            }
            $items = UpstreamFactory::make($r['driver'], decrypt_json($r['config']))->getProducts();
            $markup = (float) setting('import_markup', '20');
            $added = 0;
            $updated = 0;
            foreach ((array) $items as $p) {
                $pid = isset($p['pid']) ? (string) $p['pid'] : (isset($p['id']) ? (string) $p['id'] : '');
                if ($pid === '') {
                    continue;
                }
                $cost = self::cleanPricing(isset($p['pricing']) ? $p['pricing'] : []);
                $exists = Db::name('product')->where('upstream_id', $r['id'])->where('upstream_pid', $pid)->find();
                if ($exists) {
                    $data = ['cost' => json_encode($cost, JSON_UNESCAPED_UNICODE), 'update_time' => time()];
                    if ($exists['price_mode'] === 'markup') {
                        $data['pricing'] = json_encode(Product::markupPricing($cost, $exists['markup']), JSON_UNESCAPED_UNICODE);
                    }
                    Db::name('product')->where('id', $exists['id'])->update($data);
                    $updated++;
                    continue;
                }
                Db::name('product')->insert([
                    'name' => isset($p['name']) && $p['name'] !== '' ? $p['name'] : '上游商品 ' . $pid,
                    'description' => isset($p['description']) ? strip_tags((string) $p['description']) : '',
                    'upstream_id' => $r['id'],
                    'upstream_pid' => $pid,
                    'upstream_options' => '{}',
                    'price_mode' => 'markup',
                    'markup' => $markup,
                    'cost' => json_encode($cost, JSON_UNESCAPED_UNICODE),
                    'pricing' => json_encode(Product::markupPricing($cost, $markup), JSON_UNESCAPED_UNICODE),
                    'stock' => isset($p['stock']) ? (int) $p['stock'] : -1,
                    'os_list' => '[]',
                    'status' => 0,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
                $added++;
            }
            audit('admin.upstream.import', '上游#' . $r['id'], ['added' => $added, 'updated' => $updated]);
            $this->flash('导入完成：新增 ' . $added . ' 个（默认未上架，请检查后上架），更新成本 ' . $updated . ' 个');
        } catch (\Exception $e) {
            $this->flash('导入失败：' . $e->getMessage(), 'danger');
        }

        return $this->back();
    }

    protected function rowActions()
    {
        return [
            ['label' => '测试连接', 'post' => true, 'url' => function ($r) {
                return url_to('admin/upstream/test');
            }],
            ['label' => '导入商品', 'post' => true, 'url' => function ($r) {
                return url_to('admin/upstream/importProducts');
            }, 'when' => function ($r) {
                return $r['driver'] !== 'nat';
            }],
        ];
    }
}
