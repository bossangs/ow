<?php

namespace app\common\payment;

/**
 * 线下转账 / 人工确认：展示收款说明，用户提交付款备注后由管理员在后台确认到账
 */
class Manual extends PayGateway
{
    public static function info()
    {
        return [
            'code' => 'manual',
            'name' => '线下转账（人工确认）',
            'fields' => [
                ['key' => 'instructions', 'label' => '付款说明', 'type' => 'textarea', 'required' => true, 'secret' => false, 'help' => '展示给用户的收款账号、转账备注要求等，支持换行'],
                ['key' => 'qrcode_url', 'label' => '收款码图片地址（可选）', 'type' => 'text', 'required' => false, 'secret' => false, 'help' => '如 https://example.com/qr.png'],
            ],
            'doc' => '',
        ];
    }

    public function create(array $pay, array $ctx)
    {
        return ['type' => 'manual', 'instructions' => $this->cfg('instructions'), 'qrcode_url' => $this->cfg('qrcode_url')];
    }

    public function notify(array $req)
    {
        return $this->result(false, false, '', null, '', 'fail', '人工确认网关不支持异步通知');
    }
}
