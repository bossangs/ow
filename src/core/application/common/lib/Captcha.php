<?php

namespace app\common\lib;

/**
 * SVG 图形验证码（纯 PHP，不依赖 GD 等扩展，兼容 PHP 7.0+ 和所有虚拟主机）
 *
 * 字符不以文字形式出现在 SVG 里：每个字符用内置的单笔画矢量字形画成 <path>，
 * 坐标在服务端算好（随机旋转、倾斜、缩放、逐点抖动、整体波浪扭曲），
 * 再混入干扰笔画、曲线和噪点，所有元素打乱顺序输出；没有 <text>、title、desc、id、注释。
 */
class Captcha
{
    /** 前台允许的验证码场景：登录、注册、找回密码、充值卡 */
    const FRONT_SCENES = ['login', 'register', 'reset', 'card'];
    /** 去掉了容易混淆的 0/O、1/I/L */
    const CHARS = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    const W = 120;
    const H = 42;

    /**
     * 单笔画字形，坐标网格 宽 10 × 高 14（y 向下）。
     * 每个字形是若干笔画：['l', 点...] 折线；['s', 点...] 平滑曲线（经过首尾点）。
     */
    protected static function glyphs()
    {
        static $g = null;
        if ($g !== null) {
            return $g;
        }
        $six = [[8.5, 1], [5, 0], [1.6, 2.6], [1, 7.5], [1.6, 12.3], [5, 14], [8.4, 12.4], [9, 9.6], [7.6, 7.2], [5, 6.6], [2.2, 7.8], [1.1, 10]];
        $nine = [];
        foreach ($six as $p) {
            $nine[] = [10 - $p[0], 14 - $p[1]];
        }
        $pTop = [['l', [1, 14], [1, 0], [6, 0]], ['s', [6, 0], [9, 1.4], [9, 6.1], [6, 7.5]], ['l', [6, 7.5], [1, 7.5]]];
        $g = [
            '2' => [['s', [1, 3.4], [2, 0.9], [5, 0], [8, 0.9], [9, 3.5], [8, 6.5], [1, 14]], ['l', [1, 14], [9.2, 14]]],
            '3' => [['s', [1, 1.6], [5, 0], [8.6, 1.5], [8.6, 5], [4.4, 6.9]], ['s', [4.4, 6.9], [9.2, 8.8], [9.2, 12.4], [5, 14], [0.6, 12.4]]],
            '4' => [['l', [7, 14], [7, 0], [0.6, 10], [9.6, 10]]],
            '5' => [['l', [9, 0], [2, 0], [1.4, 6.2]], ['s', [1.4, 6.2], [5, 5], [9, 7], [9.2, 11.6], [5, 14], [1, 12.6]]],
            '6' => [array_merge(['s'], $six)],
            '7' => [['l', [0.6, 0], [9.4, 0], [3.8, 14]]],
            '8' => [['s', [5, 6.9], [1.6, 5.4], [1.6, 1.5], [5, 0], [8.4, 1.5], [8.4, 5.4], [5, 6.9], [1, 8.9], [1, 12.5], [5, 14], [9, 12.5], [9, 8.9], [5, 6.9]]],
            '9' => [array_merge(['s'], $nine)],
            'A' => [['l', [0.4, 14], [5, 0], [9.6, 14]], ['l', [2.3, 9], [7.7, 9]]],
            'B' => [['l', [1, 14], [1, 0], [5.8, 0]], ['s', [5.8, 0], [8.6, 1.4], [8.6, 5.4], [5.8, 6.9]], ['l', [1, 6.9], [6, 6.9]],
                    ['s', [6, 6.9], [9.2, 8.4], [9.2, 12.6], [6, 14]], ['l', [6, 14], [1, 14]]],
            'C' => [['s', [9, 2], [7, 0.3], [5, 0], [2, 1], [1, 4], [1, 10], [2, 13], [5, 14], [7, 13.7], [9, 12]]],
            'D' => [['l', [1, 0], [1, 14]], ['s', [1, 0], [5, 0], [8.6, 2], [9.4, 7], [8.6, 12], [5, 14], [1, 14]]],
            'E' => [['l', [9, 0], [1, 0], [1, 14], [9, 14]], ['l', [1, 7], [7, 7]]],
            'F' => [['l', [9, 0], [1, 0], [1, 14]], ['l', [1, 7], [7, 7]]],
            'G' => [['s', [9, 2], [7, 0.3], [5, 0], [2, 1], [1, 4], [1, 10], [2, 13], [5, 14], [8, 13], [9, 11], [9, 8]], ['l', [9, 8], [5.4, 8]]],
            'H' => [['l', [1, 0], [1, 14]], ['l', [9, 0], [9, 14]], ['l', [1, 7], [9, 7]]],
            'J' => [['l', [8, 0], [8, 10]], ['s', [8, 10], [7.6, 13], [5, 14], [2.4, 13], [1.4, 10.4]]],
            'K' => [['l', [1, 0], [1, 14]], ['l', [9, 0], [1, 8.6]], ['l', [4, 5.4], [9.6, 14]]],
            'M' => [['l', [0.4, 14], [1, 0], [5, 9], [9, 0], [9.6, 14]]],
            'N' => [['l', [1, 14], [1, 0], [9, 14], [9, 0]]],
            'P' => $pTop,
            'Q' => [['s', [5, 0], [8.6, 1.5], [9.6, 7], [8.6, 12.5], [5, 14], [1.4, 12.5], [0.4, 7], [1.4, 1.5], [5, 0]], ['l', [5.4, 9.4], [9.8, 14.6]]],
            'R' => array_merge($pTop, [['l', [5, 7.5], [9.6, 14]]]),
            'S' => [['s', [8.6, 1.6], [5, 0], [1.6, 1], [1, 4], [3, 6], [7, 7.6], [9, 10], [8.6, 13], [5, 14], [1, 12.6]]],
            'T' => [['l', [0.4, 0], [9.6, 0]], ['l', [5, 0], [5, 14]]],
            'U' => [['s', [1, 0], [1, 10], [2, 13], [5, 14], [8, 13], [9, 10], [9, 0]]],
            'V' => [['l', [0.4, 0], [5, 14], [9.6, 0]]],
            'W' => [['l', [0, 0], [2.5, 14], [5, 4], [7.5, 14], [10, 0]]],
            'X' => [['l', [1, 0], [9, 14]], ['l', [9, 0], [1, 14]]],
            'Y' => [['l', [0.4, 0], [5, 7], [9.6, 0]], ['l', [5, 7], [5, 14]]],
            'Z' => [['l', [1, 0], [9, 0], [1, 14], [9, 14]]],
        ];

        return $g;
    }

    /**
     * 输出验证码图片。必须用 Response 设置类型：直接 header() 会被框架默认的
     * text/html 覆盖，浏览器就不显示 <img> 里的 SVG。
     *
     * @return \think\Response
     */
    public static function response($scene = 'default')
    {
        return \think\Response::create(self::create($scene), 'html', 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ])->contentType('image/svg+xml', 'utf-8');
    }

    public static function create($scene = 'default', $len = 4)
    {
        $chars = self::CHARS;
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        session('captcha_' . $scene, ['code' => password_hash(strtolower($code), PASSWORD_DEFAULT), 'time' => time()]);
        self::testHook($scene, $code);

        return self::render($code);
    }

    /** 把验证码字符串画成 SVG（只含路径和图形，不含任何文字） */
    public static function render($code)
    {
        $w = self::W;
        $h = self::H;
        $glyphs = self::glyphs();
        // 整体波浪扭曲（字形和干扰线共用）
        $wave = ['a' => self::rf(0.8, 1.8), 'f' => self::rf(0.045, 0.085), 'p' => self::rf(0, 6.283)];
        $items = [];
        $len = strlen($code);
        $slot = ($w - 14) / max(1, $len);
        $dark = self::rgb(random_int(0, 3));
        for ($i = 0; $i < $len; $i++) {
            $ch = $code[$i];
            if (!isset($glyphs[$ch])) {
                continue;
            }
            $sx = self::rf(1.6, 1.9);
            $sy = self::rf(1.75, 2.0);
            $m = self::matrix(
                self::rf(-0.32, 0.32), // 旋转（约 ±18°）
                self::rf(-0.25, 0.25), // 倾斜
                $sx,
                $sy,
                7 + $i * $slot + ($slot - 10 * $sx) / 2 + self::rf(-2, 2),
                ($h - 14 * $sy) / 2 + self::rf(-2, 2)
            );
            $color = random_int(0, 3) === 0 ? self::rgb(random_int(0, 3)) : $dark;
            $sw = self::rf(2.2, 2.7);
            foreach ($glyphs[$ch] as $stroke) {
                $items[] = self::strokePath($stroke, $m, $wave, 0.32, $color, $sw, 1);
            }
        }
        // 干扰笔画：随机取某个字形的一笔，变形、变淡后放到随机位置
        $keys = array_keys($glyphs);
        $decoys = random_int(5, 8);
        for ($i = 0; $i < $decoys; $i++) {
            $g = $glyphs[$keys[random_int(0, count($keys) - 1)]];
            $stroke = $g[random_int(0, count($g) - 1)];
            $s = self::rf(0.9, 1.6);
            $m = self::matrix(self::rf(-1.2, 1.2), self::rf(-0.4, 0.4), $s, $s, self::rf(-4, $w - 8), self::rf(-4, $h - 12));
            $items[] = self::strokePath($stroke, $m, $wave, 0.4, self::rgb(random_int(4, 6)), self::rf(1.1, 1.7), self::rf(0.55, 0.85));
        }
        // 贯穿曲线
        $lines = random_int(2, 3);
        for ($i = 0; $i < $lines; $i++) {
            $pts = [[self::rf(-6, 10), self::rf(6, $h - 6)], [self::rf(25, 50), self::rf(0, $h)], [self::rf(70, 95), self::rf(0, $h)], [self::rf($w - 10, $w + 6), self::rf(6, $h - 6)]];
            $items[] = self::strokePath(array_merge(['s'], $pts), null, $wave, 0, self::rgb(random_int($i === 0 ? 2 : 4, 6)), $i === 0 ? self::rf(1.1, 1.4) : self::rf(0.8, 1.1), self::rf(0.6, 0.9));
        }
        // 噪点
        $dots = random_int(28, 40);
        for ($i = 0; $i < $dots; $i++) {
            $items[] = sprintf('<circle cx="%.1F" cy="%.1F" r="%.1F" fill="%s" fill-opacity="%.2F"/>', self::rf(0, $w), self::rf(0, $h), self::rf(0.5, 1.3), self::rgb(random_int(3, 6)), self::rf(0.4, 0.9));
        }
        shuffle($items);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
            . '<rect width="' . $w . '" height="' . $h . '" fill="' . self::bg() . '"/>'
            . implode('', $items) . '</svg>';
    }

    /** 仿射变换参数：缩放、倾斜后绕字形中心旋转，再平移 */
    protected static function matrix($rot, $skew, $sx, $sy, $tx, $ty)
    {
        return ['c' => cos($rot), 's' => sin($rot), 'a' => $sx, 'b' => $skew * $sy, 'd' => $sy,
            'tx' => $tx, 'ty' => $ty, 'cx' => 5 * $sx, 'cy' => 7 * $sy];
    }

    protected static function apply($p, $m, $wave, $jit)
    {
        $x = $p[0] + ($jit ? self::rf(-$jit, $jit) : 0);
        $y = $p[1] + ($jit ? self::rf(-$jit, $jit) : 0);
        if ($m) {
            $x1 = $m['a'] * $x + $m['b'] * ($y - 7) - $m['cx'];
            $y1 = $m['d'] * $y - $m['cy'];
            $x = $m['c'] * $x1 - $m['s'] * $y1 + $m['cx'] + $m['tx'];
            $y = $m['s'] * $x1 + $m['c'] * $y1 + $m['cy'] + $m['ty'];
        }
        $y += $wave['a'] * sin($x * $wave['f'] + $wave['p']);

        return [$x, $y];
    }

    protected static function strokePath($stroke, $m, $wave, $jit, $color, $width, $opacity)
    {
        $type = $stroke[0];
        $pts = [];
        $n = count($stroke);
        for ($i = 1; $i < $n; $i++) {
            $pts[] = self::apply($stroke[$i], $m, $wave, $jit);
        }
        $d = sprintf('M%.1F %.1F', $pts[0][0], $pts[0][1]);
        $cnt = count($pts);
        if ($type === 's' && $cnt > 2) {
            // 二次贝塞尔平滑：以中间点为控制点，经过相邻点的中点，最后落到终点
            for ($i = 1; $i < $cnt - 1; $i++) {
                if ($i === $cnt - 2) {
                    $mx = $pts[$i + 1][0];
                    $my = $pts[$i + 1][1];
                } else {
                    $mx = ($pts[$i][0] + $pts[$i + 1][0]) / 2;
                    $my = ($pts[$i][1] + $pts[$i + 1][1]) / 2;
                }
                $d .= sprintf('Q%.1F %.1F %.1F %.1F', $pts[$i][0], $pts[$i][1], $mx, $my);
            }
        } else {
            for ($i = 1; $i < $cnt; $i++) {
                $d .= sprintf('L%.1F %.1F', $pts[$i][0], $pts[$i][1]);
            }
        }
        $op = $opacity < 1 ? sprintf(' stroke-opacity="%.2F"', $opacity) : '';

        return '<path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="' . sprintf('%.1F', $width) . '"' . $op
            . ' stroke-linecap="round" stroke-linejoin="round"/>';
    }

    /** 颜色：0-3 深色（字符），4-6 中浅色（干扰） */
    protected static function rgb($tone)
    {
        $ranges = [[10, 70], [20, 90], [30, 100], [15, 80], [110, 170], [130, 185], [150, 200]];
        $r = $ranges[max(0, min(6, (int) $tone))];
        $c = [random_int($r[0], $r[1]), random_int($r[0], $r[1]), random_int($r[0], $r[1])];
        $hue = random_int(0, 2);
        $c[$hue] = min(255, $c[$hue] + random_int(20, 60));

        return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
    }

    protected static function bg()
    {
        return sprintf('#%02x%02x%02x', random_int(234, 246), random_int(238, 248), random_int(244, 255));
    }

    protected static function rf($min, $max)
    {
        return $min + ($max - $min) * random_int(0, 100000) / 100000;
    }

    /**
     * 仅供本地自动化测试：只有在 PHP 内置开发服务器（php -S，PHP_SAPI 为 cli-server）里、
     * 并且设置了环境变量 O2O_TEST_CAPTCHA_DIR 时，才把本次验证码写到该目录供测试脚本读取。
     * 生产环境（php-fpm / Apache / IIS / CGI）不会是 cli-server，这里直接返回。
     */
    protected static function testHook($scene, $code)
    {
        if (PHP_SAPI !== 'cli-server') {
            return;
        }
        $dir = getenv('O2O_TEST_CAPTCHA_DIR');
        if (!$dir || !is_dir($dir) || !is_writable($dir)) {
            return;
        }
        @file_put_contents(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $scene) . '.txt', $code);
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
