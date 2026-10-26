<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\dns\DnsFactory;
use app\common\service\MarketService;
use app\common\service\DnsService;
use app\common\service\OrderService;
use app\common\service\InventoryService;
use think\Db;

class Market extends FrontBase
{
    protected $needLogin = true;

    public function index()
    {
        $sales = Db::name('market_sale')->where('seller_id', $this->user['id'])->order('id desc')->limit(50)->select();
        $subIds = array_filter(array_column($sales, 'sub_id'));
        $fqdn = $subIds ? Db::name('dns_subdomain')->where('id', 'in', $subIds)->column('fqdn', 'id') : [];
        foreach ($sales as &$sale) {
            $sale['fqdn'] = isset($fqdn[$sale['sub_id']]) ? $fqdn[$sale['sub_id']] : '';
        }
        unset($sale);

        return $this->fetch('market/index', [
            'title' => '域名市场',
            'listings' => Db::name('dns_domain')->where('listing_status', 'active')->where('status', 1)->order('sort asc,id desc')->select(),
            'domains' => Db::name('dns_domain')->where('user_id', $this->user['id'])->order('id desc')->select(),
            'providers' => MarketService::userProviders($this->user['id']),
            'sales' => $sales,
            'withdraws' => Db::name('withdraw')->where('user_id', $this->user['id'])->order('id desc')->select(),
            'commission' => setting('market_commission_rate', '10'),
            'withdrawMethods' => array_filter(array_map('trim', explode(',', setting('withdraw_methods', '支付宝,USDT-TRC20,银行卡')))),
        ]);
    }

    public function buy()
    {
        $domain = Db::name('dns_domain')->where('id', (int)$this->get('id'))->where('listing_status', 'active')->where('status', 1)->find();
        if (!$domain) {
            return $this->abort('域名不存在或已下架', 404);
        }
        if ($this->request->isPost()) {
            try {
                $a = DnsService::checkAvailable($domain, $this->post('prefix'), false);
                InventoryService::check('domain', $domain['id'], $this->user['id']);
                $order = OrderService::create($this->user, 'dns_sub_new', '购买 ' . $a['fqdn'], ['domain_id' => $domain['id'], 'prefix' => $a['prefix']], $domain['price'], true, trim($this->post('coupon_code')));

                return $this->redirectTo('index/pay/index', ['id' => $order['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('market/buy', ['title' => '购买子域名', 'domain' => $domain, 'availableCount' => InventoryService::available('domain', $domain['id'])]);
    }

    public function provider()
    {
        $drivers = DnsFactory::infoAll();
        if ($this->request->isPost()) {
            try {
                $cfg = $this->post('config', []);
                MarketService::saveProvider($this->user, $this->post('driver'), is_array($cfg) ? $cfg : [], $this->post('name'), (int)$this->post('id'));
                $this->flash('凭据已保存并通过健康检查');

                return $this->redirectTo('index/market/provider');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('market/provider', ['title' => 'DNS 凭据', 'drivers' => $drivers, 'providers' => MarketService::userProviders($this->user['id']), 'editId' => (int)$this->get('edit')]);
    }

    public function remoteDomains()
    {
        try {
            return $this->ok('域名列表', MarketService::remoteDomains($this->user, (int)$this->get('provider_id')));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    protected function listingOptions()
    {
        return ['price' => $this->post('price'), 'period_days' => $this->post('period_days'), 'types' => (array)$this->post('types', []), 'min_len' => $this->post('min_len'), 'max_len' => $this->post('max_len'), 'reserved' => $this->post('reserved'), 'stock' => $this->post('stock'), 'max_per_user' => $this->post('max_per_user'), 'default_ttl' => $this->post('default_ttl'), 'allow_proxy' => $this->post('allow_proxy'), 'description' => $this->post('description')];
    }

    public function submit()
    {
        if (!$this->request->isPost()) {
            return $this->redirectTo('index/market/index');
        }
        try {
            MarketService::submit($this->user, (int)$this->post('provider_id'), $this->post('remote_id'), $this->post('domain'), $this->listingOptions());
            $this->flash('域名已提交，请添加 TXT 后验证所有权');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/market/index');
    }

    public function update()
    {
        if ($this->request->isPost()) {
            try {
                MarketService::update($this->user, (int)$this->post('id'), $this->listingOptions());
                $this->flash('销售参数已更新');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }

    public function verify()
    {
        if ($this->request->isPost()) {
            try {
                MarketService::verify($this->user, (int)$this->post('id'));
                $this->flash('TXT 验证成功');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }

    public function status()
    {
        if ($this->request->isPost()) {
            try {
                $domain = Db::name('dns_domain')->where('id', (int)$this->post('id'))->where('user_id', $this->user['id'])->find();
                if (!$domain) {
                    throw new \Exception('域名不存在');
                }
                MarketService::setStatus($domain['id'], $this->post('status'), $this->post('reason'));
                $this->flash('状态已更新');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }

    public function remove()
    {
        if ($this->request->isPost()) {
            try {
                MarketService::remove($this->user, (int)$this->post('id'));
                $this->flash('域名已删除');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }

    public function withdraw()
    {
        if ($this->request->isPost()) {
            try {
                MarketService::withdraw($this->user, $this->post('amount'), $this->post('method'), $this->post('account'));
                $this->flash('提现申请已提交');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }
}
