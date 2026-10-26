<?php

namespace app\common\lib;

/**
 * 本地二维码生成器（ISO/IEC 18004，字节模式，版本 1-40，自动选掩码），输出 SVG。
 * 用于两步验证绑定：密钥只在本机生成二维码，绝不发送给任何外部二维码接口。
 * 算法参照 Project Nayuki 的 QR Code generator（MIT）思路用 PHP 7.0 重写。
 */
class QrCode
{
    const ECL_L = 0;
    const ECL_M = 1;
    const ECL_Q = 2;
    const ECL_H = 3;

    private static $eccPerBlock = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    private static $numBlocks = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];
    // 格式信息里的纠错等级编码：L=1 M=0 Q=3 H=2
    private static $eclBits = [1, 0, 3, 2];

    private $version;
    private $size;
    private $ecl;
    private $modules = [];
    private $isFunction = [];

    /** 生成矩阵：返回 bool[行][列]，true 为深色 */
    public static function matrix($text, $ecl = self::ECL_M)
    {
        $qr = new self();
        $qr->encode((string)$text, $ecl);

        return $qr->modules;
    }

    /**
     * 生成 SVG 字符串（可直接内嵌到页面）。
     * $px 为输出边长（像素），$margin 为静区宽度（模块数，规范要求 ≥4）。
     */
    public static function svg($text, $px = 220, $margin = 4, $ecl = self::ECL_M)
    {
        $m = self::matrix($text, $ecl);
        $n = count($m);
        $total = $n + 2 * $margin;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $path .= 'M' . ($x + $margin) . ' ' . ($y + $margin) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$px . '" height="' . (int)$px
            . '" viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    private function encode($text, $ecl)
    {
        $data = array_values(unpack('C*', $text) ?: []);
        $len = count($data);
        $version = 0;
        for ($v = 1; $v <= 40; $v++) {
            $capacityBits = self::numDataCodewords($v, $ecl) * 8;
            $used = 4 + ($v < 10 ? 8 : 16) + $len * 8;
            if ($used <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new \InvalidArgumentException('二维码内容过长');
        }
        // 在不增大版本的前提下尽量提高纠错等级
        foreach ([self::ECL_M, self::ECL_Q, self::ECL_H] as $e) {
            if ($e > $ecl && 4 + ($version < 10 ? 8 : 16) + $len * 8 <= self::numDataCodewords($version, $e) * 8) {
                $ecl = $e;
            }
        }
        $this->version = $version;
        $this->ecl = $ecl;
        $this->size = $version * 4 + 17;

        // 比特流：模式 0100 + 长度 + 数据
        $bits = [];
        self::appendBits($bits, 4, 4);
        self::appendBits($bits, $len, $version < 10 ? 8 : 16);
        foreach ($data as $b) {
            self::appendBits($bits, $b, 8);
        }
        $capacity = self::numDataCodewords($version, $ecl) * 8;
        self::appendBits($bits, 0, min(4, $capacity - count($bits)));
        self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($bits, $pad, 8);
        }
        $codewords = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) {
                $v = ($v << 1) | $bits[$i + $j];
            }
            $codewords[] = $v;
        }

        $s = $this->size;
        $this->modules = array_fill(0, $s, array_fill(0, $s, false));
        $this->isFunction = array_fill(0, $s, array_fill(0, $s, false));
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($codewords));

        // 选惩罚分最低的掩码
        $best = 0;
        $minPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $p = $this->penalty();
            if ($p < $minPenalty) {
                $best = $mask;
                $minPenalty = $p;
            }
            $this->applyMask($mask); // 异或两次 = 撤销
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private static function appendBits(array &$bits, $val, $len)
    {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    }

    private static function numRawDataModules($ver)
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function numDataCodewords($ver, $ecl)
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::$eccPerBlock[$ecl][$ver] * self::$numBlocks[$ecl][$ver];
    }

    private function setFunction($x, $y, $dark)
    {
        $this->modules[$y][$x] = (bool)$dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns()
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($s - 4, 3);
        $this->drawFinder(3, $s - 4);
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                $this->drawAlignment($pos[$i], $pos[$j]);
            }
        }
        $this->drawFormatBits(0); // 先占位，最终掩码确定后重画
        $this->drawVersion();
    }

    private function drawFinder($x, $y)
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $d = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunction($xx, $yy, $d !== 2 && $d !== 4);
                }
            }
        }
    }

    private function drawAlignment($x, $y)
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function alignmentPositions()
    {
        $ver = $this->version;
        if ($ver === 1) {
            return [];
        }
        $numAlign = intdiv($ver, 7) + 2;
        $step = $ver === 32 ? 26 : (int)ceil(($ver * 4 + 4) / ($numAlign * 2 - 2)) * 2;
        $result = [6];
        $tail = [];
        for ($pos = $this->size - 7; count($result) + count($tail) < $numAlign; $pos -= $step) {
            array_unshift($tail, $pos);
        }

        return array_merge($result, $tail);
    }

    private function drawFormatBits($mask)
    {
        $data = (self::$eclBits[$this->ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, ($bits >> $i) & 1);
        }
        $this->setFunction(8, 7, ($bits >> 6) & 1);
        $this->setFunction(8, 8, ($bits >> 7) & 1);
        $this->setFunction(7, 8, ($bits >> 8) & 1);
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, ($bits >> $i) & 1);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($s - 1 - $i, 8, ($bits >> $i) & 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $s - 15 + $i, ($bits >> $i) & 1);
        }
        $this->setFunction(8, $s - 8, true);
    }

    private function drawVersion()
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | ($rem & 0xFFF);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $bit);
            $this->setFunction($b, $a, $bit);
        }
    }

    private static function gfMul($x, $y)
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    private static function rsDivisor($degree)
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }

        return $result;
    }

    private static function rsRemainder(array $data, array $divisor)
    {
        $n = count($divisor);
        $result = array_fill(0, $n, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $n; $i++) {
                $result[$i] ^= self::gfMul($divisor[$i], $factor);
            }
        }

        return $result;
    }

    private function addEccAndInterleave(array $data)
    {
        $ver = $this->version;
        $numBlocks = self::$numBlocks[$this->ecl][$ver];
        $eccLen = self::$eccPerBlock[$this->ecl][$ver];
        $rawCodewords = intdiv(self::numRawDataModules($ver), 8);
        $numShort = $numBlocks - $rawCodewords % $numBlocks;
        $shortLen = intdiv($rawCodewords, $numBlocks);
        $div = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $datLen = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::rsRemainder($dat, $div);
            if ($i < $numShort) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $blk) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $result[] = $blk[$i];
                }
            }
        }

        return $result;
    }

    private function drawCodewords(array $data)
    {
        $s = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask($mask)
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                switch ($mask) {
                    case 0: $inv = ($x + $y) % 2 === 0; break;
                    case 1: $inv = $y % 2 === 0; break;
                    case 2: $inv = $x % 3 === 0; break;
                    case 3: $inv = ($x + $y) % 3 === 0; break;
                    case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $inv = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
                    case 6: $inv = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                    default: $inv = (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                }
                if ($inv) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** 惩罚分（规范的 4 条规则；只影响掩码选择，不影响能否识别） */
    private function penalty()
    {
        $s = $this->size;
        $m = $this->modules;
        $score = 0;
        // 规则 1：同色连续 ≥5
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $s; $a++) {
                $run = 1;
                for ($b = 1; $b < $s; $b++) {
                    $cur = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    if ($cur === $prev) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                    }
                }
            }
        }
        // 规则 2：2×2 同色块
        for ($y = 0; $y < $s - 1; $y++) {
            for ($x = 0; $x < $s - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // 规则 3：类似定位图形 1011101 且一侧有 4 个浅色
        $p1 = [true, false, true, true, true, false, true, false, false, false, false];
        $p2 = array_reverse($p1);
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $s; $a++) {
                for ($b = 0; $b + 11 <= $s; $b++) {
                    $m1 = true;
                    $m2 = true;
                    for ($k = 0; $k < 11; $k++) {
                        $v = $pass === 0 ? $m[$a][$b + $k] : $m[$b + $k][$a];
                        if ($v !== $p1[$k]) {
                            $m1 = false;
                        }
                        if ($v !== $p2[$k]) {
                            $m2 = false;
                        }
                    }
                    if ($m1) {
                        $score += 40;
                    }
                    if ($m2) {
                        $score += 40;
                    }
                }
            }
        }
        // 规则 4：深浅比例
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $v) {
                if ($v) {
                    $dark++;
                }
            }
        }
        $total = $s * $s;
        $k = (int)ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        $score += max(0, $k) * 10;

        return $score;
    }
}
