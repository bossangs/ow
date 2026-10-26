<?php

namespace app\common\dns;

class DnsFactory
{
    public static function drivers()
    {
        return [
            'dnspod' => Dnspod::class,
            'aliyun' => Aliyun::class,
            'cloudflare' => Cloudflare::class,
            'huawei' => Huawei::class,
            'baidu' => Baidu::class,
            'west' => West::class,
            'volcengine' => Volcengine::class,
            'jdcloud' => Jdcloud::class,
            'dnsla' => Dnsla::class,
            'namesilo' => Namesilo::class,
            'godaddy' => Godaddy::class,
            'powerdns' => Powerdns::class,
            'digitalocean' => Digitalocean::class,
            'spaceship' => Spaceship::class,
        ];
    }

    /** @return DnsDriver */
    public static function make($code, array $config)
    {
        $m = self::drivers();
        if (!isset($m[$code])) {
            throw new DnsException('不支持的 DNS 服务商：' . $code);
        }
        $cls = $m[$code];

        return new $cls($config);
    }

    public static function infoAll()
    {
        $out = [];
        foreach (self::drivers() as $code => $cls) {
            if (class_exists($cls)) {
                $out[$code] = $cls::info();
            }
        }

        return $out;
    }
}
