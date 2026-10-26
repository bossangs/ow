<?php

namespace app\common\upstream;

class UpstreamFactory
{
    public static function drivers()
    {
        return array('whmcs' => 'Whmcs', 'zjmf' => 'Zjmf', 'zjmf_finance' => 'ZjmfFinance', 'natnode' => 'NatNode');
    }
    public static function make($code, array $config)
    {
        $a = self::drivers();
        if (!isset($a[$code])) {
            throw new UpstreamException('不支持的上游类型');
        }$c = 'app\\common\\upstream\\' . $a[$code];

        return new $c($config);
    }
    public static function infoAll()
    {
        $o = array();
        foreach (self::drivers() as $c => $n) {
            $x = 'app\\common\\upstream\\' . $n;
            $o[$c] = $x::info();
        }

        return $o;
    }
}
