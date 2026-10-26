<?php

namespace app\common\payment;

use think\Db;

class PayFactory
{
    public static function drivers()
    {
        return [
            'epay' => Epay::class,
            'bepusdt' => Bepusdt::class,
            'alipay' => Alipay::class,
            'manual' => Manual::class,
        ];
    }

    public static function infoAll()
    {
        $o = [];
        foreach (self::drivers() as $c => $cls) {
            $o[$c] = $cls::info();
        }

        return $o;
    }

    /** @return PayGateway */
    public static function make($code, array $config = null)
    {
        $d = self::drivers();
        if (!isset($d[$code])) {
            throw new \Exception('不支持的支付方式');
        }
        if ($config === null) {
            $row = Db::name('gateway')->where('code', $code)->find();
            $config = $row ? decrypt_json($row['config']) : [];
        }
        $cls = $d[$code];

        return new $cls($config);
    }

    /** 已启用网关列表（含余额支付） */
    public static function enabled($withBalance = true)
    {
        $list = [];
        if ($withBalance && setting('pay_balance_enable', '1') === '1') {
            $list[] = ['code' => 'balance', 'name' => '余额支付', 'channels' => []];
        }
        $rows = Db::name('gateway')->where('enabled', 1)->order('sort asc')->select();
        foreach ($rows as $r) {
            if (!isset(self::drivers()[$r['code']])) {
                continue;
            }
            $g = self::make($r['code'], decrypt_json($r['config']));
            $list[] = ['code' => $r['code'], 'name' => $r['name'], 'channels' => $g->channels(), 'fee_rate' => (float)$r['fee_rate'], 'min' => (float)$r['min_amount'], 'max' => (float)$r['max_amount']];
        }

        return $list;
    }
}
