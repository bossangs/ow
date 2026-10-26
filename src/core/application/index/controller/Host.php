<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\service\OrderAdminService;
use app\common\service\OrderService;
use app\common\service\ProvisionService;
use app\common\service\InventoryService;
use think\Db;

class Host extends FrontBase
{
    protected $needLogin = true;
    public function index()
    {
        return $this->fetch('host/index', ['title' => '我的云主机', 'services' => Db::name('service')->where('user_id', $this->user['id'])->order('id desc')->select()]);
    }
    public function detail()
    {
        $service = Db::name('service')->where('id', (int)$this->get('id'))->where('user_id', $this->user['id'])->find();
        if (!$service) {
            return $this->abort('服务不存在', 404);
        }
        $product = Db::name('product')->find($service['product_id']);
        $cancel = Db::name('cancel_request')->where('user_id', $this->user['id'])->where('type', 'service')->where('target_id', $service['id'])->order('id desc')->find();
        $password = $service['password'] ? decrypt_str($service['password']) : '';

        return $this->fetch('host/detail', compact('service', 'product', 'password', 'cancel'));
    }
    public function buy()
    {
        $product = Db::name('product')->where('id', (int)$this->get('product_id'))->where('status', 1)->find();
        if (!$product) {
            return $this->abort('商品不存在', 404);
        }
        $prices = ProvisionService::pricing($product);
        if ($this->request->isPost()) {
            try {
                InventoryService::check('product', $product['id'], $this->user['id']);
                if (($pwError = root_password_error($this->post('password'))) !== '') {
                    throw new \Exception($pwError);
                }
                if ((!isset($product['allow_hostname']) || (int)$product['allow_hostname']) && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{0,62}$/', trim((string)$this->post('hostname')))) {
                    throw new \Exception('主机名只能包含字母、数字和短横线，且以字母或数字开头');
                }
                $cycle = $this->post('cycle');
                $options = $this->post('options', []);
                if (!is_array($options)) {
                    $options = [];
                }
                $order = OrderService::create($this->user, 'host_new', $product['name'], ['product_id' => $product['id'], 'cycle' => $cycle, 'hostname' => trim($this->post('hostname')), 'os' => trim($this->post('os')), 'password' => $this->post('password'), 'options' => $options], ProvisionService::priceOf($product, $cycle), true, trim($this->post('coupon_code')));

                return $this->redirectTo('index/pay/index', ['id' => $order['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('host/buy', ['title' => '购买云主机', 'product' => $product, 'prices' => $prices, 'os' => json_arr($product['os_list']), 'available' => InventoryService::available('product', $product['id'])]);
    }
    public function action()
    {
        $id = (int)$this->post('id');
        $service = Db::name('service')->where('id', $id)->where('user_id', $this->user['id'])->find();
        if (!$service) {
            return $this->abort('服务不存在', 404);
        }
        try {
            ProvisionService::action($id, $this->post('action'), ['password' => $this->post('password'), 'os' => $this->post('os')]);
            $this->flash('操作已提交。');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/host/detail', ['id' => $id]);
    }
    public function renew()
    {
        $service = Db::name('service')->where('id', (int)$this->get('id'))->where('user_id', $this->user['id'])->find();
        if (!$service) {
            return $this->abort('服务不存在', 404);
        } $product = Db::name('product')->find($service['product_id']);
        if ($this->request->isPost()) {
            try {
                $cycle = $this->post('cycle');
                $order = OrderService::create($this->user, 'host_renew', '续费 ' . $service['hostname'], ['service_id' => $service['id'], 'cycle' => $cycle], ProvisionService::priceOf($product, $cycle), true, trim($this->post('coupon_code')));

                return $this->redirectTo('index/pay/index', ['id' => $order['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('host/renew', ['title' => '云主机续费', 'service' => $service, 'prices' => ProvisionService::pricing($product)]);
    }
    public function upgrade()
    {
        $service = Db::name('service')->where('id', (int)$this->get('id'))->where('user_id', $this->user['id'])->find();
        if (!$service) {
            return $this->abort('服务不存在', 404);
        }
        $products = Db::name('product')->where('status', 1)->where('group_id', $service['product_id'] ? Db::name('product')->where('id', $service['product_id'])->value('group_id') : 0)->select();
        if ($this->request->isPost()) {
            try {
                $product = Db::name('product')->where('id', (int)$this->post('product_id'))->where('status', 1)->find();
                if (!$product) {
                    throw new \Exception('升级商品不存在');
                } InventoryService::check('product', $product['id'], $this->user['id']);
                $amount = ProvisionService::upgradePrice($service, $product);
                $order = OrderService::create($this->user, 'host_upgrade', '升级 ' . $service['hostname'], ['service_id' => $service['id'], 'new_product_id' => $product['id'], 'cycle' => $service['billing_cycle']], $amount, true, trim($this->post('coupon_code')));

                return $this->redirectTo('index/pay/index', ['id' => $order['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('host/upgrade', ['title' => '升级云主机', 'service' => $service, 'products' => $products]);
    }
    public function cancel()
    {
        $id = (int)$this->post('id');
        if (!Db::name('service')->where('id', $id)->where('user_id', $this->user['id'])->find()) {
            return $this->abort('服务不存在', 404);
        } try {
            OrderAdminService::requestCancel($this->user, 'service', $id, $this->post('mode'), trim($this->post('reason')));
            $this->flash('取消申请已提交，等待人工审核。');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/host/detail', ['id' => $id]);
    }
    public function withdrawCancel()
    {
        try {
            OrderAdminService::withdrawRequest($this->user, (int)$this->post('request_id'));
            $this->flash('取消申请已撤回。');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->back();
    }
}
