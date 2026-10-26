<?php
/**
 * 部分 Windows/虚拟主机未开启 mbstring，这里提供最小兜底实现（UTF-8）
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = 'UTF-8')
    {
        return preg_match_all('/./us', (string)$s, $m);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = 'UTF-8')
    {
        preg_match_all('/./us', (string)$s, $m);
        $chars = $m[0];
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = 'UTF-8')
    {
        return strtolower($s);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s, $enc = 'UTF-8')
    {
        return strtoupper($s);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($h, $n, $o = 0, $enc = 'UTF-8')
    {
        $p = strpos($h, $n, $o);
        return $p === false ? false : mb_strlen(substr($h, 0, $p));
    }
}
if (!function_exists('mb_convert_encoding')) {
    function mb_convert_encoding($s, $to, $from = 'UTF-8')
    {
        if (function_exists('iconv')) {
            $r = @iconv(is_array($from) ? $from[0] : $from, $to . '//IGNORE', $s);
            return $r === false ? $s : $r;
        }
        return $s;
    }
}
if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding($e = null)
    {
        return true;
    }
}
