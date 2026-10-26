<?php

namespace app\admin\controller;

use app\common\upstream\UpstreamFactory;
use think\Db;

/**
 * 云主机产品
 * 定价方式：fixed = 按「售价」字段；markup = 上游成本 ×(1 + 加价%)，保存或同步时自动重算售价
 */
class Product extends Crud
{
    protected $table = 'product';
    protected $title = '云主机产品';
    protected $order = 'sort asc, id desc';
    protected $searchFields = ['name', 'upstream_pid', 'description'];

    protected function fields()
    {
        return [
            'name' => ['产品名称', 'type' => 'text', 'required' => true],
            'group_id' => ['产品分组', 'type' => 'select', 'options' => $this->optionsFrom('product_group', 'name', [0 => '未分组'])],
            'upstream_id' => ['交付方式 / 上游', 'type' => 'select', 'options' => $this->optionsFrom('upstream', 'name', [0 => '手动交付（不对接上游）']),
                'help' => '选择 WHMCS / 智简魔方 / NAT 节点等上游后，用户付款会自动在上游开通'],
            'upstream_pid' => ['上游产品 ID', 'type' => 'text', 'list' => false,
                'help' => 'WHMCS 的 pid、智简魔方的商品 ID；NAT 节点填套餐代码。可在「上游接口 → 导入商品」自动生成'],
            'upstream_options' => ['上游附加参数 (JSON)', 'type' => 'json-kv', 'list' => false,
                'help' => '原样传给上游，例如 {"configoptions":{"12":"4"},"billingcycle_map":{}}；不需要可留空'],
            'price_mode' => ['定价方式', 'type' => 'select', 'options' => ['fixed' => '固定售价', 'markup' => '上游成本加价']],
            'markup' => ['加价比例 %', 'type' => 'number', 'list' => false, 'show_when' => '#f_price_mode=markup',
                'help' => '例如 20 表示售价 = 上游成本 × 1.2，保存后自动重算各周期售价'],
            'cost' => ['上游成本价', 'type' => 'pricing', 'list' => false, 'show_when' => '#f_price_mode=markup',
                'help' => '导入或同步上游商品时自动填写，也可手动修改'],
            'pricing' => ['售价（按付款周期）', 'type' => 'pricing', 'help' => '留空的周期不对用户开放；加价模式下由成本自动计算'],
            'stock' => ['库存', 'type' => 'number', 'default' => -1, 'help' => '-1 = 不限；0 = 售罄'],
            'sold' => ['已售', 'type' => 'number', 'readonly' => true],
            'reserved' => ['待支付预留', 'type' => 'number', 'readonly' => true, 'list' => false],
            'per_user_limit' => ['每用户限购', 'type' => 'number', 'list' => false, 'default' => 0, 'help' => '0 = 不限'],
            'stock_alert' => ['低库存提醒阈值', 'type' => 'number', 'list' => false, 'default' => -1, 'help' => '-1 = 使用系统设置的全局阈值'],
            'nat_stock_cap' => ['NAT 库存上限', 'type' => 'number', 'list' => false, 'default' => -1, 'help' => '仅 NAT 节点产品：-1 = 按节点剩余容量自动计算'],
            'os_list' => ['可选操作系统', 'type' => 'lines', 'list' => false, 'help' => '每行一个，如 Debian 12、Ubuntu 22.04；留空则不让用户选择'],
            'allow_hostname' => ['允许用户填写主机名', 'type' => 'switch', 'list' => false, 'default' => 1],
            'auto_setup' => ['付款后自动开通', 'type' => 'switch', 'list' => false, 'default' => 1, 'help' => '关闭后订单进入「待开通」，由管理员手动开通'],
            'tier_ids' => ['仅限会员等级购买', 'type' => 'multiselect', 'list' => false, 'options' => $this->optionsFrom('user_tier')],
            'lifecycle' => ['到期策略（覆盖全局）', 'type' => 'lifecycle', 'list' => false],
            'spec_cpu' => ['CPU', 'type' => 'text', 'list' => false, 'placeholder' => '如 2 核', 'help' => '以下配置规格展示在前台商品页和商城卡片，留空的项不显示'],
            'spec_memory' => ['内存', 'type' => 'text', 'list' => false, 'placeholder' => '如 2 GB'],
            'spec_disk' => ['硬盘', 'type' => 'text', 'list' => false, 'placeholder' => '如 40 GB SSD'],
            'spec_bandwidth' => ['带宽', 'type' => 'text', 'list' => false, 'placeholder' => '如 10 Mbps'],
            'spec_traffic' => ['流量', 'type' => 'text', 'list' => false, 'placeholder' => '如 1 TB/月 或 不限'],
            'spec_ip' => ['IP', 'type' => 'text', 'list' => false, 'placeholder' => '如 1 个独立 IPv4 / NAT 共享 IP + 20 端口'],
            'spec_region' => ['地域', 'type' => 'text', 'list' => false, 'placeholder' => '如 香港'],
            'spec_line' => ['线路', 'type' => 'text', 'list' => false, 'placeholder' => '如 CN2 GIA / BGP'],
            'spec_extra' => ['其他规格', 'type' => 'lines', 'list' => false, 'help' => '每行一项，格式「名称：值」，如「防御：10G」'],
            'description' => ['产品介绍', 'type' => 'textarea', 'list' => false, 'help' => '展示在商城卡片和购买页，支持换行'],
            'status' => ['上架', 'type' => 'switch', 'default' => 1],
            'sort' => ['排序', 'type' => 'number', 'list' => false, 'default' => 0, 'help' => '数字越小越靠前'],
        ];
    }

    /** 按成本和加价比例计算售价 */
    public static function markupPricing(array $cost, $markup)
    {
        $out = [];
        foreach ($cost as $cycle => $price) {
            if ($price === null || $price === '' || (float) $price < 0) {
                continue;
            }
            $out[$cycle] = round((float) $price * (1 + (float) $markup / 100), 2);
        }

        return $out;
    }

    protected function beforeSave(array $d, $id)
    {
        if ((int) $d['upstream_id'] > 0 && trim($d['upstream_pid']) === '') {
            $driver = Db::name('upstream')->where('id', (int) $d['upstream_id'])->value('driver');
            if ($driver !== 'nat') {
                throw new \Exception('对接上游时必须填写上游产品 ID');
            }
        }
        if ($d['price_mode'] === 'markup') {
            $cost = json_decode($d['cost'], true);
            if (!$cost) {
                throw new \Exception('加价模式需要先填写上游成本价（或从上游导入）');
            }
            $d['pricing'] = json_encode(self::markupPricing($cost, $d['markup']), JSON_UNESCAPED_UNICODE);
        }
        if (!json_decode($d['pricing'], true)) {
            throw new \Exception('至少需要设置一个付款周期的售价');
        }
        $d['update_time'] = time();
        if (!$id) {
            $d['create_time'] = time();
        }

        return $d;
    }

    protected function decorateList(array $list)
    {
        $ups = Db::name('upstream')->column('name', 'id');
        foreach ($list as &$r) {
            $r['upstream_id_text'] = $r['upstream_id'] ? (isset($ups[$r['upstream_id']]) ? $ups[$r['upstream_id']] : '已删除上游') . ' / ' . $r['upstream_pid'] : '手动交付';
            $r['stock_text'] = (int) $r['stock'] < 0 ? '不限' : (string) $r['stock'];
        }
        unset($r);

        return $list;
    }

    protected function beforeDelete($id)
    {
        $n = Db::name('service')->where('product_id', $id)->where('status', 'in', ['pending', 'active', 'suspended'])->count();
        if ($n) {
            throw new \Exception('该产品还有 ' . $n . ' 个有效服务，请先下架（关闭「上架」）而不是删除');
        }
    }

    /** 从上游刷新成本价；加价模式同时重算售价 */
    public function syncPrice()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        try {
            $p = Db::name('product')->find((int) $this->request->post('id'));
            if (!$p || !$p['upstream_id']) {
                throw new \Exception('该产品没有对接上游');
            }
            $up = Db::name('upstream')->find($p['upstream_id']);
            if (!$up) {
                throw new \Exception('上游不存在');
            }
            $found = null;
            foreach ((array) UpstreamFactory::make($up['driver'], decrypt_json($up['config']))->getProducts() as $item) {
                if ((string) $item['pid'] === (string) $p['upstream_pid']) {
                    $found = $item;
                    break;
                }
            }
            if (!$found) {
                throw new \Exception('上游找不到产品 ID ' . $p['upstream_pid']);
            }
            $cost = Upstream::cleanPricing($found['pricing']);
            $data = ['cost' => json_encode($cost, JSON_UNESCAPED_UNICODE), 'update_time' => time()];
            if ($p['price_mode'] === 'markup') {
                $data['pricing'] = json_encode(self::markupPricing($cost, $p['markup']), JSON_UNESCAPED_UNICODE);
            }
            Db::name('product')->where('id', $p['id'])->update($data);
            audit('admin.product.sync_price', '产品#' . $p['id'], $data);
            $this->flash('已从上游同步成本价' . ($p['price_mode'] === 'markup' ? '并重算售价' : ''));
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->back();
    }

    protected function rowActions()
    {
        return [[
            'label' => '同步上游价格',
            'post' => true,
            'url' => function ($r) {
                return url_to('admin/product/syncPrice');
            },
            'when' => function ($r) {
                return (int) $r['upstream_id'] > 0;
            },
        ]];
    }
}
