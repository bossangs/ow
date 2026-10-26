<?php

namespace app\common\lib;

/**
 * 轻量 HTTP 客户端（兼容 PHP 7.0；优先 cURL，缺失时回退 stream）
 * 返回: ['status'=>int, 'headers'=>array(小写键), 'body'=>string, 'error'=>string]
 */
class HttpClient
{
    /** 全局默认超时（秒） */
    public static $defaultTimeout = 20;
    /** 是否校验 SSL 证书（虚拟主机 CA 缺失时可在后台关闭） */
    public static $verifySsl = true;
    /** 可选代理，如 http://127.0.0.1:7890 */
    public static $proxy = '';

    public static function get($url, array $query = [], array $headers = [], $timeout = null)
    {
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return self::request('GET', $url, $headers, null, $timeout);
    }

    public static function postForm($url, array $data, array $headers = [], $timeout = null)
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return self::request('POST', $url, $headers, http_build_query($data), $timeout);
    }

    public static function postJson($url, $data, array $headers = [], $timeout = null)
    {
        $headers['Content-Type'] = 'application/json';

        return self::request('POST', $url, $headers, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
    }

    /**
     * @param string $method GET/POST/PUT/PATCH/DELETE
     * @param string $url
     * @param array $headers 关联数组 ['Name'=>'value']
     * @param string|null $body 原始请求体
     * @param int|null $timeout
     * @return array
     */
    public static function request($method, $url, array $headers = [], $body = null, $timeout = null)
    {
        $timeout = $timeout ?: self::$defaultTimeout;
        $method = strtoupper($method);
        $lines = [];
        $hasUa = false;
        foreach ($headers as $k => $v) {
            if (is_int($k)) {
                $lines[] = $v;
                if (stripos($v, 'user-agent:') === 0) {
                    $hasUa = true;
                }
            } else {
                $lines[] = $k . ': ' . $v;
                if (strtolower($k) === 'user-agent') {
                    $hasUa = true;
                }
            }
        }
        if (!$hasUa) {
            $lines[] = 'User-Agent: IDCDNS/1.0';
        }
        if (function_exists('curl_init')) {
            return self::viaCurl($method, $url, $lines, $body, $timeout);
        }

        return self::viaStream($method, $url, $lines, $body, $timeout);
    }

    protected static function viaCurl($method, $url, array $lines, $body, $timeout)
    {
        $ch = curl_init();
        $respHeaders = [];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if (!self::$verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        if (self::$proxy) {
            curl_setopt($ch, CURLOPT_PROXY, self::$proxy);
        }
        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$respHeaders) {
            $len = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) == 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $len;
        });
        $resp = curl_exec($ch);
        $err = $resp === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $respHeaders, 'body' => $resp === false ? '' : $resp, 'error' => $err];
    }

    protected static function viaStream($method, $url, array $lines, $body, $timeout)
    {
        $opts = ['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]];
        if ($body !== null && $method !== 'GET') {
            $opts['http']['content'] = $body;
        }
        if (!self::$verifySsl) {
            $opts['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
        }
        if (self::$proxy) {
            $opts['http']['proxy'] = str_replace(['http://', 'https://'], 'tcp://', self::$proxy);
            $opts['http']['request_fulluri'] = true;
        }
        $ctx = stream_context_create($opts);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        $headers = [];
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $status = (int)$m[1];
                    $headers = [];
                } else {
                    $p = explode(':', $h, 2);
                    if (count($p) == 2) {
                        $headers[strtolower(trim($p[0]))] = trim($p[1]);
                    }
                }
            }
        }
        $err = '';
        if ($resp === false) {
            $e = error_get_last();
            $err = $e ? $e['message'] : 'request failed';
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $resp === false ? '' : $resp, 'error' => $err];
    }

    /** 解析 JSON 响应体 */
    public static function json(array $resp)
    {
        $d = json_decode($resp['body'], true);

        return is_array($d) ? $d : null;
    }
}
