<?php

namespace app\common\payment;

use app\common\lib\HttpClient;

/**
 * 易支付（彩虹易支付 / v03413 Epay 兼容协议，jupay 等平台可直接使用）
 * - 页面跳转：{api_url}/submit.php
 * - API 下单：{api_url}/mapi.php（返回 payurl / qrcode）
 * - 签名：参数按键名 ASCII 升序，排除空值、sign、sign_type，k=v&...，末尾拼接商户密钥后 MD5（小写）
 * - 异步通知：GET/POST 回调 notify_url，trade_status=TRADE_SUCCESS，需输出 success
 */
class Epay extends PayGateway
{
    public static function info()
    {
        return [
            'code' => 'epay',
            'name' => '易支付（彩虹易支付/jupay 兼容）',
            'fields' => [
                ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'secret' => false, 'help' => '易支付平台地址，如 https://pay.example.com/（不含 submit.php）'],
                ['key' => 'pid', 'label' => '商户 ID (pid)', 'type' => 'text', 'required' => true, 'secret' => false, 'help' => '易支付商户后台的商户 ID'],
                ['key' => 'key', 'label' => '商户密钥 (key)', 'type' => 'password', 'required' => true, 'secret' => true, 'help' => '易支付商户后台的 MD5 密钥'],
                ['key' => 'channels', 'label' => '启用的支付方式', 'type' => 'text', 'required' => false, 'secret' => false, 'help' => '逗号分隔，格式 代码:名称，如 alipay:支付宝,wxpay:微信支付,qqpay:QQ钱包,usdt:USDT；留空则跳转平台收银台自选'],
                ['key' => 'mode', 'label' => '下单方式', 'type' => 'select', 'required' => false, 'secret' => false, 'help' => '页面跳转兼容性最好；API 下单可获取二维码在本站展示', 'options' => ['submit' => '页面跳转 submit.php', 'mapi' => 'API 下单 mapi.php']],
            ],
            'doc' => 'https://github.com/v03413/Epay',
        ];
    }

    public function channels()
    {
        $out = [];
        $s = trim($this->cfg('channels', ''));
        if ($s === '') {
            return $out;
        }
        foreach (preg_split('/[,，\n]+/', $s) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $p = preg_split('/[:：]/', $item, 2);
            $code = trim($p[0]);
            if (preg_match('/^[a-z0-9_]{1,20}$/i', $code)) {
                $out[$code] = isset($p[1]) && trim($p[1]) !== '' ? trim($p[1]) : $code;
            }
        }

        return $out;
    }

    /** MD5 签名 */
    public function sign(array $params)
    {
        ksort($params);
        $s = '';
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || is_array($v) || $v === '' || $v === null) {
                continue;
            }
            $s .= ($s === '' ? '' : '&') . $k . '=' . $v;
        }

        return md5($s . $this->cfg('key'));
    }

    protected function base()
    {
        $u = rtrim($this->cfg('api_url'), '/');
        $u = preg_replace('#/(submit|mapi)\.php$#i', '', $u);

        return $u . '/';
    }

    public function create(array $pay, array $ctx)
    {
        $params = [
            'pid' => $this->cfg('pid'),
            'type' => isset($ctx['channel']) ? (string)$ctx['channel'] : '',
            'out_trade_no' => $pay['trade_no'],
            'notify_url' => $ctx['notify_url'],
            'return_url' => $ctx['return_url'],
            'name' => mb_substr(preg_replace('/[\r\n&=]/', ' ', $ctx['subject']), 0, 60),
            'money' => number_format((float)$pay['amount'], 2, '.', ''),
        ];
        if ($this->cfg('mode', 'submit') === 'mapi' && $params['type'] !== '') {
            $params['clientip'] = isset($ctx['client_ip']) ? $ctx['client_ip'] : '127.0.0.1';
            $params['device'] = !empty($ctx['is_mobile']) ? 'mobile' : 'pc';
            $params['sign'] = $this->sign($params);
            $params['sign_type'] = 'MD5';
            $r = HttpClient::postForm($this->base() . 'mapi.php', $params, [], 20);
            $d = HttpClient::json($r);
            if (!$d || !isset($d['code']) || (int)$d['code'] !== 1) {
                $msg = $d && isset($d['msg']) ? $d['msg'] : ($r['error'] ?: ('HTTP ' . $r['status']));
                throw new \Exception('易支付下单失败：' . $msg);
            }
            if (!empty($d['payurl'])) {
                return ['type' => 'redirect', 'url' => $d['payurl']];
            }
            if (!empty($d['qrcode'])) {
                return ['type' => 'qrcode', 'code_url' => $d['qrcode'], 'tips' => '请使用对应 App 扫码支付'];
            }
            if (!empty($d['urlscheme'])) {
                return ['type' => 'redirect', 'url' => $d['urlscheme']];
            }
            throw new \Exception('易支付下单失败：未返回支付地址');
        }
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';

        return ['type' => 'html', 'html' => $this->autoForm($this->base() . 'submit.php', $params, 'POST')];
    }

    public function notify(array $req)
    {
        $p = !empty($req['get']['out_trade_no']) ? $req['get'] : $req['post'];
        if (empty($p['out_trade_no']) || empty($p['sign'])) {
            return $this->result(false, false, '', null, '', 'fail', '缺少参数');
        }
        if (!hash_equals($this->sign($p), strtolower((string)$p['sign']))) {
            return $this->result(false, false, $p['out_trade_no'], null, '', 'fail', '签名错误');
        }
        if ((string)(isset($p['pid']) ? $p['pid'] : '') !== (string)$this->cfg('pid')) {
            return $this->result(false, false, $p['out_trade_no'], null, '', 'fail', '商户号不匹配');
        }
        $paid = isset($p['trade_status']) && $p['trade_status'] === 'TRADE_SUCCESS';

        return $this->result(true, $paid, $p['out_trade_no'], isset($p['money']) ? (float)$p['money'] : null, isset($p['trade_no']) ? $p['trade_no'] : '', 'success');
    }

    /** 订单查询 api.php?act=order */
    public function query(array $pay)
    {
        $r = HttpClient::get($this->base() . 'api.php', ['act' => 'order', 'pid' => $this->cfg('pid'), 'key' => $this->cfg('key'), 'out_trade_no' => $pay['trade_no']], [], 15);
        $d = HttpClient::json($r);
        if (!$d || !isset($d['code']) || (int)$d['code'] !== 1) {
            return null;
        }

        return ['paid' => isset($d['status']) && (int)$d['status'] === 1, 'amount' => isset($d['money']) ? (float)$d['money'] : null, 'out_trade_no' => isset($d['trade_no']) ? $d['trade_no'] : ''];
    }
}
