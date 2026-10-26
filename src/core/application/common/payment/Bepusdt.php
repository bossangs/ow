<?php

namespace app\common\payment;

use app\common\lib\HttpClient;

/**
 * BEpusdt（USDT-TRC20 等）收款网关
 * 文档：https://github.com/v03413/BEpusdt/blob/main/docs/api/api.md
 * - 下单 POST {api_url}/api/v1/order/create-transaction （JSON）
 * - 签名：排除空值与 signature，键名 ASCII 升序，k=v&...，末尾直接拼接 API Token，MD5 小写
 * - 回调 POST JSON：trade_id, order_id, amount, actual_amount, token, block_transaction_id, signature, status(1等待/2成功/3超时)
 *   成功需返回 HTTP 200 且内容含 ok
 */
class Bepusdt extends PayGateway
{
    public static function info()
    {
        return [
            'code' => 'bepusdt',
            'name' => 'USDT 收款（BEpusdt）',
            'fields' => [
                ['key' => 'api_url', 'label' => 'BEpusdt 地址', 'type' => 'text', 'required' => true, 'secret' => false, 'help' => '自行部署的 BEpusdt 服务地址，如 https://usdt.example.com'],
                ['key' => 'token', 'label' => 'API Token', 'type' => 'password', 'required' => true, 'secret' => true, 'help' => 'BEpusdt 配置文件中的 auth_token'],
                ['key' => 'trade_type', 'label' => '交易类型', 'type' => 'select', 'required' => false, 'secret' => false, 'help' => '默认 USDT-TRC20', 'options' => ['usdt.trc20' => 'USDT-TRC20', 'usdc.trc20' => 'USDC-TRC20', 'tron.trx' => 'TRX', 'usdt.erc20' => 'USDT-ERC20', 'usdt.bep20' => 'USDT-BEP20', 'usdt.polygon' => 'USDT-Polygon', 'usdt.solana' => 'USDT-Solana', 'usdt.ton' => 'USDT-TON']],
                ['key' => 'fiat', 'label' => '法币币种', 'type' => 'select', 'required' => false, 'secret' => false, 'help' => '订单金额的法币币种，网关按汇率换算', 'options' => ['CNY' => 'CNY 人民币', 'USD' => 'USD 美元', 'EUR' => 'EUR', 'GBP' => 'GBP', 'JPY' => 'JPY']],
                ['key' => 'timeout', 'label' => '支付超时（秒）', 'type' => 'text', 'required' => false, 'secret' => false, 'help' => '最小 120，默认 1200'],
                ['key' => 'rate', 'label' => '自定义汇率（可选）', 'type' => 'text', 'required' => false, 'secret' => false, 'help' => '如 7.3、~1.02、+0.3，留空使用网关配置'],
            ],
            'doc' => 'https://github.com/v03413/BEpusdt',
        ];
    }

    /** 与 Go fmt.Sprint 一致的数值格式（去除尾零） */
    public static function fmtNum($v)
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            $s = rtrim(rtrim(sprintf('%.8F', $v), '0'), '.');

            return $s === '' || $s === '-0' ? '0' : $s;
        }

        return (string)$v;
    }

    public function sign(array $params)
    {
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($k === 'signature' || $v === '' || $v === null || is_array($v)) {
                continue;
            }
            $parts[] = $k . '=' . self::fmtNum($v);
        }

        return md5(implode('&', $parts) . $this->cfg('token'));
    }

    public function create(array $pay, array $ctx)
    {
        $amount = round((float)$pay['amount'], 2);
        $body = [
            'order_id' => $pay['trade_no'],
            'amount' => $amount,
            'notify_url' => $ctx['notify_url'],
            'redirect_url' => $ctx['return_url'],
            'trade_type' => $this->cfg('trade_type', 'usdt.trc20'),
            'fiat' => $this->cfg('fiat', 'CNY'),
            'name' => mb_substr($ctx['subject'], 0, 60),
            'timeout' => max(120, (int)$this->cfg('timeout', 1200)),
        ];
        if ($this->cfg('rate') !== '') {
            $body['rate'] = $this->cfg('rate');
        }
        $body['signature'] = $this->sign($body);
        $r = HttpClient::postJson(rtrim($this->cfg('api_url'), '/') . '/api/v1/order/create-transaction', $body, [], 20);
        $d = HttpClient::json($r);
        if (!$d || !isset($d['status_code']) || (int)$d['status_code'] !== 200 || empty($d['data']['payment_url'])) {
            $msg = $d && isset($d['message']) ? $d['message'] : ($r['error'] ?: ('HTTP ' . $r['status']));
            throw new \Exception('USDT 下单失败：' . $msg);
        }
        // 支付链接必须与配置的网关同源，防止被篡改为钓鱼地址
        $a = parse_url($this->cfg('api_url'));
        $b = parse_url($d['data']['payment_url']);
        if (!isset($b['host']) || strtolower($b['host']) !== strtolower(isset($a['host']) ? $a['host'] : '')) {
            throw new \Exception('USDT 下单失败：网关返回的支付地址与配置不一致');
        }

        return ['type' => 'redirect', 'url' => $d['data']['payment_url'], 'extra' => [
            'trade_id' => isset($d['data']['trade_id']) ? $d['data']['trade_id'] : '',
            'actual_amount' => isset($d['data']['actual_amount']) ? $d['data']['actual_amount'] : '',
            'token' => isset($d['data']['token']) ? $d['data']['token'] : '',
        ]];
    }

    public function notify(array $req)
    {
        $p = json_decode(isset($req['raw']) ? $req['raw'] : '', true);
        if (!is_array($p)) {
            $p = !empty($req['post']) ? $req['post'] : $req['get'];
        }
        if (empty($p['order_id']) || empty($p['signature'])) {
            return $this->result(false, false, '', null, '', 'fail', '缺少参数');
        }
        if (!hash_equals($this->sign($p), strtolower((string)$p['signature']))) {
            return $this->result(false, false, $p['order_id'], null, '', 'fail', '签名错误');
        }
        $status = isset($p['status']) ? (int)$p['status'] : 0;

        return $this->result(true, $status === 2, $p['order_id'], isset($p['amount']) ? (float)$p['amount'] : null, isset($p['trade_id']) ? $p['trade_id'] : '', 'ok');
    }

    public function verifyReturn(array $req)
    {
        // BEpusdt 同步跳转不带签名，仅用于跳回页面；以异步通知为准
        return $this->result(true, false, '', null, '', '');
    }

    public function query(array $pay)
    {
        $tid = isset($pay['_trade_id']) ? $pay['_trade_id'] : '';
        if ($tid === '') {
            return null;
        }
        $r = HttpClient::postJson(rtrim($this->cfg('api_url'), '/') . '/api/v1/pay/info', ['trade_id' => $tid], [], 15);
        $d = HttpClient::json($r);
        if (!$d || !isset($d['data']['status'])) {
            return null;
        }

        return ['paid' => (int)$d['data']['status'] === 2, 'amount' => null, 'out_trade_no' => $tid];
    }
}
