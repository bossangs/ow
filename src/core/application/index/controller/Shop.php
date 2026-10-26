<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\service\DnsService;
use app\common\service\OrderService;
use app\common\service\ProvisionService;
use app\common\service\InventoryService;
use think\Db;

class Shop extends FrontBase
{
    protected $needLogin = false;
    public function index()
    {
        $groups = Db::name('product_group')->where('status', 1)->order('sort asc,id asc')->select();
        $products = Db::name('product')->where('status', 1)->order('sort asc,id asc')->select();
        foreach ($products as &$p) {
            $p['_prices'] = ProvisionService::pricing($p);
            $p['_available'] = InventoryService::available('product', $p['id']);
        }

        return $this->fetch('shop/index', ['title' => '云主机', 'groups' => $groups, 'products' => $products]);
    }
    public function product()
    {
        $p = Db::name('product')->where('id', (int)$this->get('id'))->where('status', 1)->find();
        if (!$p) {
            return $this->abort('商品不存在', 404);
        }

        return $this->fetch('shop/product', ['title' => $p['name'], 'product' => $p, 'prices' => ProvisionService::pricing($p), 'os' => json_arr($p['os_list']), 'available' => InventoryService::available('product', $p['id'])]);
    }
    public function domains()
    {
        $d = $this->user ? DnsService::availableDomains($this->user) : Db::name('dns_domain')->where('status', 1)->where('listing_status', 'active')->order('sort asc,id asc')->select();
        foreach ($d as &$v) {
            $v['_available'] = InventoryService::available('domain', $v['id']);
        }

        return $this->fetch('shop/domains', ['title' => '域名分发', 'domains' => $d, 'prefix' => trim($this->get('prefix'))]);
    }
    public function checkDomain()
    {
        $d = Db::name('dns_domain')->where('id', (int)$this->get('domain_id'))->find();
        if (!$d) {
            return $this->fail('域名不存在', 404);
        }try {
            return $this->ok('查询完成', DnsService::checkAvailable($d, trim($this->get('prefix')), true));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
    public function packages()
    {
        $p = Db::name('dns_package')->where('status', 1)->order('sort asc,id asc')->select();
        foreach ($p as &$v) {
            $v['_available'] = InventoryService::available('package', $v['id']);
        }

        return $this->fetch('shop/packages', ['title' => 'DNS套餐', 'packages' => $p]);
    }
    public function buyPackage()
    {
        if (!$this->user) {
            return $this->redirectTo('index/auth/login');
        }$p = Db::name('dns_package')->where('id', (int)$this->get('id'))->where('status', 1)->find();
        if (!$p) {
            return $this->abort('套餐不存在', 404);
        }if ($this->request->isPost()) {
            try {
                InventoryService::check('package', $p['id'], $this->user['id']);
                $o = OrderService::create($this->user, 'dns_package', $p['name'], ['package_id' => $p['id']], $p['price'], true, trim($this->post('coupon_code')));

                return $this->redirectTo('index/pay/index', ['id' => $o['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('shop/packages', ['title' => 'DNS套餐', 'packages' => [$p]]);
    }
}
