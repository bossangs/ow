<?php

namespace app\common\lib;

/**
 * TOTP（RFC 6238）：6 位、30 秒、HMAC-SHA1，兼容 Google Authenticator / Microsoft Authenticator 等。
 * 纯 PHP 7.0 实现，只依赖 hash 扩展与 random_bytes。
 */
class Totp
{
    const PERIOD = 30;
    const DIGITS = 6;
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 新密钥：160 位随机数，Base32 编码（32 个字符） */
    public static function newSecret()
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode($bin)
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($bin[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    public static function base32Decode($text)
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', (string)$text));
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $v = strpos(self::ALPHABET, $text[$i]);
            if ($v === false) {
                return '';
            }
            $buffer = ($buffer << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 255);
            }
            $buffer &= (1 << $bits) - 1;
        }

        return $out;
    }

    /** 当前时间对应的步数 */
    public static function step($time = null)
    {
        return (int)floor(($time === null ? time() : $time) / self::PERIOD);
    }

    /** 计算某一步的验证码（$digits 仅供 RFC 测试向量使用） */
    public static function code($secret, $step, $digits = self::DIGITS)
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $step = (int)$step;
        $msg = pack('N', ($step >> 32) & 0xFFFFFFFF) . pack('N', $step & 0xFFFFFFFF);
        $h = hash_hmac('sha1', $msg, $key, true);
        $o = ord($h[19]) & 0x0F;
        $bin = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string)($bin % (int)pow(10, $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * 校验验证码，允许前后各 $window 步的时钟误差。
     * 返回匹配到的步数（用于防重放），不匹配返回 false。
     */
    public static function matchStep($secret, $code, $time = null, $window = 1)
    {
        $code = preg_replace('/\s+/', '', (string)$code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $now = self::step($time);
        $found = false;
        // 全部比较一遍再返回，避免按位置提前返回造成的时间差
        for ($i = -$window; $i <= $window; $i++) {
            $c = self::code($secret, $now + $i);
            if ($c !== '' && hash_equals($c, $code) && $found === false) {
                $found = $now + $i;
            }
        }

        return $found;
    }

    /** otpauth:// 链接（扫码绑定用） */
    public static function uri($issuer, $account, $secret)
    {
        $issuer = str_replace(':', '', (string)$issuer);
        $account = str_replace(':', '', (string)$account);

        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** 手动输入用：每 4 位一组 */
    public static function groupSecret($secret)
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
