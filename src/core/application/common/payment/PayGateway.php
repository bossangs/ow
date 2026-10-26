<?php

namespace app\common\payment;

/**
 * 支付网关基类
 * 所有参数在后台“支付设置”中配置（gateway 表 config 字段加密保存）
 */
abstract class PayGateway
{
    protected $config = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** ['code','name','fields'=>[[key,label,type(text|password|textarea|select),required,secret,help,options]],'doc'] */
    public static function info()
    {
        return ['code' => 'base', 'name' => '', 'fields' => [], 'doc' => ''];
    }

    /**
     * 发起支付
     * @param array $pay payment 表记录（trade_no, amount, ...）
     * @param array $ctx ['subject','notify_url','return_url','client_ip','is_mobile','channel']
     * @return array ['type'=>'redirect','url'=>..] | ['type'=>'html','html'=>..] | ['type'=>'qrcode','code_url'=>..,'tips'=>..]
     */
    abstract public function create(array $pay, array $ctx);

    /**
     * 处理异步通知
     * @param array $req ['get'=>[], 'post'=>[], 'raw'=>string, 'headers'=>[]]
     * @return array ['ok'=>bool,'paid'=>bool,'trade_no'=>本站单号,'amount'=>float|null,'out_trade_no'=>,'reply'=>回给网关的文本,'error'=>]
     */
    abstract public function notify(array $req);

    /** 同步跳转校验（默认与 notify 同逻辑） */
    public function verifyReturn(array $req)
    {
        return $this->notify($req);
    }

    /** 可选：主动查询（返回 null 表示不支持） */
    public function query(array $pay)
    {
        return null;
    }

    /** 可用的子渠道（如易支付 alipay/wxpay），code => 名称 */
    public function channels()
    {
        return [];
    }

    protected function cfg($k, $d = '')
    {
        return isset($this->config[$k]) && $this->config[$k] !== '' ? $this->config[$k] : $d;
    }

    protected function result($ok, $paid, $tradeNo, $amount, $outTradeNo, $reply, $error = '')
    {
        return ['ok' => $ok, 'paid' => $paid, 'trade_no' => (string)$tradeNo, 'amount' => $amount, 'out_trade_no' => (string)$outTradeNo, 'reply' => $reply, 'error' => $error];
    }

    /** 自动提交表单 */
    protected function autoForm($action, array $fields, $method = 'POST')
    {
        $h = '<!doctype html><html><head><meta charset="utf-8"><title>正在跳转支付...</title></head><body>'
            . '<p style="text-align:center;margin-top:80px;font-family:sans-serif">正在跳转到支付页面，请稍候...</p>'
            . '<form id="payform" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" method="' . $method . '">';
        foreach ($fields as $k => $v) {
            $h .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '">';
        }
        $h .= '<noscript><button type="submit">继续支付</button></noscript></form><script>document.getElementById("payform").submit();</script></body></html>';

        return $h;
    }
}
