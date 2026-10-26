<?php

namespace app\common\payment;

/**
 * 支付宝官方接口（开放平台 RSA2，电脑网站支付 / 手机网站支付）
 * - 网关：https://openapi.alipay.com/gateway.do （沙箱 https://openapi-sandbox.dl.alipaydev.com/gateway.do）
 * - 签名：除 sign 外的非空参数按键名升序，k=v&...，SHA256withRSA，Base64
 * - 异步通知验签：除 sign、sign_type 外的参数排序拼接，用支付宝公钥验签；成功输出 success
 */
class Alipay extends PayGateway
{
    public static function info()
    {
        return [
            'code' => 'alipay',
            'name' => '支付宝（官方接口）',
            'fields' => [
                ['key' => 'app_id', 'label' => 'APPID', 'type' => 'text', 'required' => true, 'secret' => false, 'help' => '支付宝开放平台应用 APPID'],
                ['key' => 'private_key', 'label' => '应用私钥', 'type' => 'textarea', 'required' => true, 'secret' => true, 'help' => '应用私钥（RSA2，PKCS#1 或 PKCS#8，可不带 BEGIN/END 头）'],
                ['key' => 'alipay_public_key', 'label' => '支付宝公钥', 'type' => 'textarea', 'required' => true, 'secret' => false, 'help' => '开放平台“接口加签方式”中的支付宝公钥（非应用公钥）'],
                ['key' => 'mode', 'label' => '支付产品', 'type' => 'select', 'required' => false, 'secret' => false, 'help' => '自动：电脑端用电脑网站支付，手机端用手机网站支付', 'options' => ['auto' => '自动识别', 'page' => '电脑网站支付', 'wap' => '手机网站支付']],
                ['key' => 'sandbox', 'label' => '沙箱环境', 'type' => 'select', 'required' => false, 'secret' => false, 'help' => '测试时可开启沙箱', 'options' => ['0' => '否（正式环境）', '1' => '是（沙箱）']],
                ['key' => 'gateway_url', 'label' => '自定义网关地址（可选）', 'type' => 'text', 'required' => false, 'secret' => false, 'help' => '留空使用官方网关'],
            ],
            'doc' => 'https://opendocs.alipay.com/open/270/105898',
        ];
    }

    protected function gateway()
    {
        if ($this->cfg('gateway_url') !== '') {
            return $this->cfg('gateway_url');
        }

        return $this->cfg('sandbox', '0') === '1' ? 'https://openapi-sandbox.dl.alipaydev.com/gateway.do' : 'https://openapi.alipay.com/gateway.do';
    }

    public static function pem($key, $type)
    {
        $key = trim($key);
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $key = preg_replace('/\s+/', '', $key);
        $label = $type === 'public' ? 'PUBLIC KEY' : 'PRIVATE KEY';
        // PKCS#1 私钥（MII...）与 PKCS#8 均可：先尝试 PKCS#8 头，失败再用 RSA 头
        $pem = "-----BEGIN {$label}-----\n" . chunk_split($key, 64, "\n") . "-----END {$label}-----";
        if ($type === 'private' && !@openssl_pkey_get_private($pem)) {
            $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($key, 64, "\n") . "-----END RSA PRIVATE KEY-----";
        }

        return $pem;
    }

    public static function signString(array $params, $exclude = ['sign'])
    {
        ksort($params);
        $s = '';
        foreach ($params as $k => $v) {
            if (in_array($k, $exclude, true) || $v === '' || $v === null || is_array($v)) {
                continue;
            }
            $s .= ($s === '' ? '' : '&') . $k . '=' . $v;
        }

        return $s;
    }

    public function rsaSign($str)
    {
        $key = openssl_pkey_get_private(self::pem($this->cfg('private_key'), 'private'));
        if (!$key) {
            throw new \Exception('支付宝应用私钥格式错误');
        }
        openssl_sign($str, $sig, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($sig);
    }

    public function rsaVerify($str, $sign)
    {
        $key = openssl_pkey_get_public(self::pem($this->cfg('alipay_public_key'), 'public'));
        if (!$key) {
            return false;
        }

        return openssl_verify($str, base64_decode($sign), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function create(array $pay, array $ctx)
    {
        $mode = $this->cfg('mode', 'auto');
        if ($mode === 'auto') {
            $mode = !empty($ctx['is_mobile']) ? 'wap' : 'page';
        }
        $biz = [
            'out_trade_no' => $pay['trade_no'],
            'total_amount' => number_format((float)$pay['amount'], 2, '.', ''),
            'subject' => mb_substr($ctx['subject'], 0, 120),
            'product_code' => $mode === 'wap' ? 'QUICK_WAP_WAY' : 'FAST_INSTANT_TRADE_PAY',
        ];
        if ($mode === 'wap') {
            $biz['quit_url'] = $ctx['return_url'];
        }
        $params = [
            'app_id' => $this->cfg('app_id'),
            'method' => $mode === 'wap' ? 'alipay.trade.wap.pay' : 'alipay.trade.page.pay',
            'format' => 'JSON',
            'return_url' => $ctx['return_url'],
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $ctx['notify_url'],
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $this->rsaSign(self::signString($params));

        return ['type' => 'html', 'html' => $this->autoForm($this->gateway() . '?charset=utf-8', $params, 'POST')];
    }

    public function notify(array $req)
    {
        $p = !empty($req['post']['sign']) ? $req['post'] : $req['get'];
        if (empty($p['out_trade_no']) || empty($p['sign'])) {
            return $this->result(false, false, '', null, '', 'fail', '缺少参数');
        }
        $str = self::signString($p, ['sign', 'sign_type']);
        if (!$this->rsaVerify($str, $p['sign'])) {
            return $this->result(false, false, $p['out_trade_no'], null, '', 'fail', '验签失败');
        }
        if (isset($p['app_id']) && $p['app_id'] !== $this->cfg('app_id')) {
            return $this->result(false, false, $p['out_trade_no'], null, '', 'fail', 'APPID 不匹配');
        }
        $st = isset($p['trade_status']) ? $p['trade_status'] : '';
        $paid = $st === 'TRADE_SUCCESS' || $st === 'TRADE_FINISHED';
        $amount = isset($p['total_amount']) ? (float)$p['total_amount'] : null;

        return $this->result(true, $paid, $p['out_trade_no'], $amount, isset($p['trade_no']) ? $p['trade_no'] : '', 'success');
    }

    public function verifyReturn(array $req)
    {
        // 同步返回只用于展示，不作为到账依据
        $p = $req['get'];
        if (empty($p['sign']) || empty($p['out_trade_no'])) {
            return $this->result(false, false, '', null, '', '');
        }
        $ok = $this->rsaVerify(self::signString($p, ['sign', 'sign_type']), $p['sign']);

        return $this->result($ok, false, $p['out_trade_no'], null, '', '');
    }
}
