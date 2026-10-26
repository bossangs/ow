<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\service\CouponService;
use app\common\service\OrderAdminService;
use app\common\service\OrderService;
use app\common\service\ProvisionService;
use think\Db;

class Order extends FrontBase
{
    protected $needLogin = true;

    public function index()
    {
        list($orders, $total, $page, $size) = $this->paginate(Db::name('order')->where('user_id', $this->user['id'])->order('id desc'), 20);

        return $this->fetch('order/index', ['title' => '订单记录', 'orders' => $orders, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    public function detail()
    {
        $o = Db::name('order')->where('id', (int)$this->get('id'))->where('user_id', $this->user['id'])->find();
        if (!$o) {
            return $this->abort('订单不存在', 404);
        }

        return $this->fetch('order/detail', ['title' => '订单详情', 'order' => $o, 'related' => OrderAdminService::related($o)]);
    }

    /** 优惠码预览：金额全部由服务端按订单上下文重算。 */
    public function coupon_preview()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请求方式错误');
        }
        try {
            $type = trim($this->post('type'));
            $data = $this->couponData($type);
            $amount = $this->couponAmount($type, $data);

            return $this->ok('优惠码可用', CouponService::preview($this->user, $this->post('coupon_code'), $type, $data, $amount));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    protected function couponData($type)
    {
        $data = [];
        if ($type === 'host_new') {
            $data['product_id'] = (int)$this->post('product_id');
            $data['cycle'] = trim($this->post('cycle'));
        } elseif ($type === 'host_renew') {
            $data['service_id'] = (int)$this->post('service_id');
            $data['cycle'] = trim($this->post('cycle'));
        } elseif ($type === 'host_upgrade') {
            $data['service_id'] = (int)$this->post('service_id');
            $data['new_product_id'] = (int)$this->post('new_product_id');
            $data['cycle'] = trim($this->post('cycle'));
        } elseif ($type === 'dns_sub_new') {
            $data['domain_id'] = (int)$this->post('domain_id');
            $data['prefix'] = trim($this->post('prefix'));
        } elseif ($type === 'dns_sub_renew') {
            $data['sub_id'] = (int)$this->post('sub_id');
        } elseif ($type === 'dns_package') {
            $data['package_id'] = (int)$this->post('package_id');
            $data['user_package_id'] = (int)$this->post('user_package_id');
        }

        return $data;
    }

    protected function couponAmount($type, array $data)
    {
        if ($type === 'host_new') {
            $p = Db::name('product')->find($data['product_id']);

            return $p ? ProvisionService::priceOf($p, $data['cycle']) : 0;
        }
        if ($type === 'host_renew') {
            $s = Db::name('service')->where('id', $data['service_id'])->where('user_id', $this->user['id'])->find();
            $p = $s ? Db::name('product')->find($s['product_id']) : null;

            return $p ? ProvisionService::priceOf($p, $data['cycle']) : 0;
        }
        if ($type === 'host_upgrade') {
            $s = Db::name('service')->where('id', $data['service_id'])->where('user_id', $this->user['id'])->find();
            $p = Db::name('product')->find($data['new_product_id']);

            return ($s && $p) ? ProvisionService::upgradePrice($s, $p) : 0;
        }
        if ($type === 'dns_sub_new') {
            return (float)Db::name('dns_domain')->where('id', $data['domain_id'])->value('price');
        }
        if ($type === 'dns_sub_renew') {
            $sid = Db::name('dns_subdomain')->where('id', $data['sub_id'])->where('user_id', $this->user['id'])->value('domain_id');

            return (float)Db::name('dns_domain')->where('id', $sid)->value('price');
        }
        if ($type === 'dns_package') {
            return (float)Db::name('dns_package')->where('id', $data['package_id'])->value('price');
        }
        throw new \RuntimeException('该订单不支持使用优惠码');
    }

    public function removeCoupon()
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $o = Db::name('order')->where('id', (int)$this->post('id'))->where('user_id', $this->user['id'])->find();
        if (!$o) {
            return $this->abort('订单不存在', 404);
        }
        try {
            CouponService::removeFromOrder($o);
            $this->flash('优惠码已移除');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->back();
    }

    public function cancel()
    {
        if ($this->request->isPost()) {
            try {
                OrderAdminService::userCancel($this->user, (int)$this->post('id'));
                $this->flash('订单已取消');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back('index/order/index');
    }
    public function requestCancel()
    {
        if ($this->request->isPost()) {
            try {
                OrderAdminService::requestCancel($this->user, $this->post('target_type'), (int)$this->post('target_id'), $this->post('mode'), $this->post('reason'));
                $this->flash('取消申请已提交');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }
    public function withdrawRequest()
    {
        if ($this->request->isPost()) {
            try {
                OrderAdminService::withdrawRequest($this->user, (int)$this->post('id'));
                $this->flash('申请已撤回');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }
}
