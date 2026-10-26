<?php

namespace app\admin\controller;

use think\Db;

/**
 * 根域名（可分发的主域名）
 * 平台域名 user_id = 0；用户在域名市场发布的域名也在此列出，审核请到「域名市场审核」
 */
class Domain extends Crud
{
    protected $table = 'dns_domain';
    protected $title = '根域名';
    protected $order = 'sort asc, id desc';
    protected $searchFields = ['domain', 'description'];

    protected function fields()
    {
        return [
            'domain' => ['根域名', 'type' => 'text', 'required' => true, 'help' => '例如 example.com，必须已托管在所选 DNS 服务商账号下'],
            'provider_id' => ['DNS 服务商', 'type' => 'select', 'required' => true, 'options' => $this->optionsFrom('dns_provider', 'name', [], ['user_id' => 0])],
            'remote_id' => ['服务商域名 ID', 'type' => 'text', 'list' => false, 'help' => '留空保存时自动从服务商查询（Cloudflare 的 Zone ID、DNSPod 的 DomainId 等）'],
            'user_id' => ['所属', 'type' => 'number', 'readonly' => true],
            'status' => ['启用分发', 'type' => 'switch', 'default' => 1],
            'listing_status' => ['市场状态', 'type' => 'select', 'list' => false, 'options' => [
                'active' => '正常', 'pending' => '待审核', 'rejected' => '已驳回', 'offline' => '已下架', 'paused' => '凭据失效暂停',
            ]],
            'price' => ['价格（每周期）', 'type' => 'number', 'default' => 0, 'help' => '0 = 免费领取（仍受会员等级配额限制）'],
            'period_days' => ['有效期（天）', 'type' => 'number', 'default' => 365, 'help' => '0 = 永久'],
            'renew_before_days' => ['可提前续费天数', 'type' => 'number', 'list' => false, 'default' => 7],
            'types' => ['允许的记录类型', 'type' => 'text', 'list' => false, 'default' => 'A,AAAA,CNAME,TXT', 'help' => '逗号分隔，如 A,AAAA,CNAME,TXT,MX,SRV,CAA；最终取用户等级/套餐与此处的交集'],
            'min_len' => ['前缀最短长度', 'type' => 'number', 'list' => false, 'default' => 3],
            'max_len' => ['前缀最长长度', 'type' => 'number', 'list' => false, 'default' => 32],
            'reserved' => ['保留前缀', 'type' => 'textarea', 'list' => false, 'help' => '逗号或换行分隔；会与系统设置里的全局保留前缀合并'],
            'tier_ids' => ['仅限会员等级', 'type' => 'multiselect', 'list' => false, 'options' => $this->optionsFrom('user_tier')],
            'max_per_user' => ['每用户最多注册', 'type' => 'number', 'list' => false, 'default' => 0, 'help' => '0 = 只受会员等级配额限制'],
            'stock' => ['最多可分发数量', 'type' => 'number', 'default' => 0, 'help' => '0 = 不限'],
            'sold' => ['已分发', 'type' => 'number', 'readonly' => true],
            'stock_alert' => ['低库存提醒阈值', 'type' => 'number', 'list' => false, 'default' => -1, 'help' => '-1 = 使用全局阈值'],
            'require_review' => ['注册需人工审核', 'type' => 'switch', 'list' => false],
            'default_ttl' => ['默认 TTL（秒）', 'type' => 'number', 'list' => false, 'default' => 600],
            'allow_line' => ['允许智能线路', 'type' => 'switch', 'list' => false, 'default' => 1, 'help' => '运营商/地区线路，仅部分服务商支持'],
            'allow_proxy' => ['允许 CDN 代理', 'type' => 'switch', 'list' => false, 'help' => '仅 Cloudflare 有效（橙色云朵）'],
            'commission_rate' => ['市场佣金 %', 'type' => 'number', 'list' => false, 'default' => -1, 'help' => '仅用户发布的域名：-1 = 按全局/卖家等级'],
            'lifecycle' => ['到期策略（覆盖全局）', 'type' => 'lifecycle', 'list' => false],
            'description' => ['说明', 'type' => 'text', 'list' => false, 'help' => '显示在前台域名列表'],
            'sort' => ['排序', 'type' => 'number', 'list' => false, 'default' => 0],
        ];
    }

    protected function beforeSave(array $d, $id)
    {
        $d['domain'] = strtolower(trim($d['domain'], ". \t"));
        if (!preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d['domain'])) {
            throw new \Exception('根域名格式不正确');
        }
        if (Db::name($this->table)->where('domain', $d['domain'])->where('id', '<>', (int) $id)->count()) {
            throw new \Exception('该根域名已存在');
        }
        if ((int) $d['min_len'] < 1 || (int) $d['max_len'] > 63 || (int) $d['min_len'] > (int) $d['max_len']) {
            throw new \Exception('前缀长度范围应在 1–63 之间，且最短不大于最长');
        }
        if ((float) $d['price'] < 0) {
            throw new \Exception('价格不能小于 0');
        }
        $d['types'] = implode(',', array_unique(array_filter(array_map('trim', explode(',', strtoupper(str_replace('，', ',', $d['types'])))))));
        if ($d['remote_id'] === '') {
            $d['remote_id'] = $this->lookupRemoteId((int) $d['provider_id'], $d['domain']);
        }
        if (!$id) {
            $d['create_time'] = time();
            $d['user_id'] = 0;
            $d['verified'] = 1;
        }

        return $d;
    }

    /** 从服务商查询域名 ID；查询失败不阻止保存（部分服务商无需 ID） */
    protected function lookupRemoteId($providerId, $domain)
    {
        try {
            $p = Db::name('dns_provider')->find($providerId);
            if (!$p) {
                return '';
            }
            $drv = \app\common\dns\DnsFactory::make($p['driver'], decrypt_json($p['config']));
            foreach ((array) $drv->getDomains($domain) as $x) {
                if (isset($x['domain']) && strtolower(trim($x['domain'], '.')) === $domain) {
                    return (string) (isset($x['id']) ? $x['id'] : '');
                }
            }
            $this->flash('提示：服务商账号中没有找到 ' . $domain . '，请确认域名已添加到该账号', 'warning');
        } catch (\Exception $e) {
            $this->flash('提示：自动查询域名 ID 失败（' . $e->getMessage() . '），可稍后手动填写', 'warning');
        }

        return '';
    }

    protected function decorateList(array $list)
    {
        $providers = Db::name('dns_provider')->column('name', 'id');
        $list = $this->userLabels($list);
        foreach ($list as &$r) {
            $r['provider_id_text'] = isset($providers[$r['provider_id']]) ? $providers[$r['provider_id']] : '已删除';
            $r['price_text'] = (float) $r['price'] > 0 ? money($r['price']) : '免费';
            $r['period_days_text'] = (int) $r['period_days'] > 0 ? $r['period_days'] . ' 天' : '永久';
            $r['stock_text'] = (int) $r['stock'] > 0 ? (string) $r['stock'] : '不限';
        }
        unset($r);

        return $list;
    }

    protected function beforeDelete($id)
    {
        $n = Db::name('dns_subdomain')->where('domain_id', $id)->where('status', 'in', ['active', 'review', 'suspended'])->count();
        if ($n) {
            throw new \Exception('该根域名下还有 ' . $n . ' 个有效子域名，请先停用（关闭「启用分发」）');
        }
    }
}
