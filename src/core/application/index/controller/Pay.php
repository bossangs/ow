<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\payment\PayFactory;
use app\common\service\OrderService;
use think\Db;
use think\Response;

class Pay extends FrontBase
{
    protected $needLogin = true;
    protected $noCsrf = ['notify', 'returned', 'status'];
    protected $guestActions = ['notify', 'returned'];

    protected function order($id)
    {
        $order = Db::name('order')->where('id', (int)$id)->where('user_id', $this->user['id'])->find();
        if (!$order) {
            $this->abort('订单不存在', 404);
        }

        return $order;
    }

    public function index()
    {
        $order = $this->order($this->get('id'));
        if ($this->request->isPost()) {
            try {
                $gateway = trim($this->post('gateway'));
                if ($gateway === 'balance') {
                    OrderService::payWithBalance($order);

                    return $this->redirectTo('index/order/detail', ['id' => $order['id']]);
                }
                $purpose = $order['type'] === 'recharge' ? 'recharge' : 'order';
                $paymentOrderId = $order['id'];
                $payment = OrderService::createPayment(
                    $this->user,
                    $purpose,
                    $paymentOrderId,
                    $order['pay_amount'],
                    $gateway,
                    $this->post('channel')
                );
                $action = OrderService::startGateway($payment, $order['title'], false);
                if ($action['type'] === 'redirect') {
                    return $this->redirectTo($action['url']);
                }
                if ($action['type'] === 'html') {
                    return Response::create($action['html'], 'html');
                }

                return $this->fetch('pay/result', [
                    'title' => '支付', 'order' => $order, 'pay' => $payment, 'action' => $action,
                ]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('pay/index', [
            'title' => '订单支付', 'order' => $order, 'gateways' => PayFactory::enabled(),
        ]);
    }

    public function manual()
    {
        $payment = Db::name('payment')->where('id', (int)$this->post('payment_id'))
            ->where('user_id', $this->user['id'])->where('gateway', 'manual')->find();
        if (!$payment) {
            return $this->abort('支付流水不存在', 404);
        }
        Db::name('payment')->where('id', $payment['id'])->update([
            'remark' => mb_substr(trim($this->post('remark')), 0, 250),
        ]);
        $this->flash('付款说明已提交，请等待管理员确认。');

        return $this->redirectTo('index/pay/result', ['trade_no' => $payment['trade_no']]);
    }

    public function result()
    {
        $payment = Db::name('payment')->where('trade_no', $this->get('trade_no'))
            ->where('user_id', $this->user['id'])->find();
        if (!$payment) {
            return $this->abort('支付流水不存在', 404);
        }
        $order = $payment['order_id'] ? $this->order($payment['order_id']) : null;

        return $this->fetch('pay/result', [
            'title' => '支付结果', 'order' => $order, 'pay' => $payment,
            'action' => [],
        ]);
    }

    public function notify()
    {
        try {
            $gateway = $this->request->param('gw');
            $result = PayFactory::make($gateway)->notify([
                'get' => $this->get(), 'post' => $this->post(),
                'raw' => file_get_contents('php://input'), 'headers' => [],
            ]);
            if (!empty($result['ok']) && !empty($result['paid'])) {
                OrderService::confirmPayment(
                    $result['trade_no'],
                    $result['amount'],
                    $result['out_trade_no'],
                    $result,
                    'notify'
                );
            }

            return Response::create(isset($result['reply']) ? $result['reply'] : 'OK', 'text');
        } catch (\Exception $e) {
            return Response::create($e->getMessage(), 'text', 400);
        }
    }

    public function returned()
    {
        return $this->redirectTo('index/order/index');
    }

    public function status()
    {
        $payment = Db::name('payment')->where('trade_no', $this->get('trade_no'))
            ->where('user_id', $this->user['id'])->find();

        return $this->ok('ok', ['status' => $payment ? (int)$payment['status'] : 0]);
    }
}
