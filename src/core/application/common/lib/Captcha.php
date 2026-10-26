<?php

namespace app\common\lib;

/**
 * SVG 图形验证码（不依赖 GD 扩展，兼容所有虚拟主机）
 */
class Captcha
{
    public static function create($scene = 'default', $len = 4)
    {
        $chars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        session('captcha_' . $scene, ['code' => password_hash(strtolower($code), PASSWORD_DEFAULT), 'time' => time()]);
        $w = 120;
        $hgt = 42;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $hgt . '" viewBox="0 0 ' . $w . ' ' . $hgt . '">';
        $svg .= '<rect width="100%" height="100%" fill="#eef2ff"/>';
        for ($i = 0; $i < 6; $i++) {
            $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', random_int(0, $w), random_int(0, $hgt), random_int(0, $w), random_int(0, $hgt), self::color(150));
        }
        for ($i = 0; $i < 30; $i++) {
            $svg .= sprintf('<circle cx="%d" cy="%d" r="1" fill="%s"/>', random_int(0, $w), random_int(0, $hgt), self::color(120));
        }
        $step = ($w - 20) / $len;
        for ($i = 0; $i < $len; $i++) {
            $x = 12 + $i * $step + random_int(-3, 3);
            $y = 30 + random_int(-4, 4);
            $rot = random_int(-25, 25);
            $svg .= sprintf('<text x="%d" y="%d" font-size="%d" font-family="Verdana,Arial" font-weight="bold" fill="%s" transform="rotate(%d %d %d)">%s</text>', $x, $y, random_int(22, 27), self::color(20, 110), $rot, $x, $y, $code[$i]);
        }
        $svg .= '<path d="M0 ' . random_int(10, 30) . ' Q ' . ($w / 2) . ' ' . random_int(0, 42) . ' ' . $w . ' ' . random_int(10, 30) . '" stroke="' . self::color(60, 140) . '" fill="none" stroke-width="1.5"/>';

        return $svg . '</svg>';
    }

    protected static function color($min = 0, $max = 255)
    {
        return sprintf('#%02x%02x%02x', random_int($min, $max), random_int($min, $max), random_int($min, $max));
    }

    /** 校验并销毁（一次性） */
    public static function check($input, $scene = 'default')
    {
        if (setting('captcha_enable', '1') !== '1') {
            return true; // 后台「系统设置」可关闭图形验证码
        }
        $data = session('captcha_' . $scene);
        session('captcha_' . $scene, null);
        if (!$data || !is_array($data) || time() - $data['time'] > 600 || !is_string($input) || $input === '') {
            return false;
        }

        return password_verify(strtolower(trim($input)), $data['code']);
    }
}
