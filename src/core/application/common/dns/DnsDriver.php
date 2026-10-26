<?php

namespace app\common\dns;

use app\common\lib\HttpClient;

/**
 * DNS 服务商驱动基类（PHP 7.0 兼容）
 *
 * 统一约定：
 * - $name 为相对主域(zone)的主机记录：'@' 表示根，'abc'、'www.abc' 等
 * - 线路使用统一代码：default/telecom/unicom/mobile/edu/oversea/search
 * - config['endpoint'] 非空时，所有请求发往该基地址（API 反代/本地 mock），
 *   签名中参与计算的 Host 使用实际请求地址的 host（与 HTTP Host 头一致）
 */
abstract class DnsDriver
{
    protected $config = [];

    public static $lineLabels = [
        'default' => '默认',
        'telecom' => '电信',
        'unicom' => '联通',
        'mobile' => '移动',
        'edu' => '教育网',
        'oversea' => '境外',
        'search' => '搜索引擎',
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 驱动描述
     * ['code','name','fields'=>[['key','label','type','required','secret','help','options'?]], 'lines'=>bool, 'types'=>[], 'doc'=>url]
     */
    public static function info()
    {
        return ['code' => 'base', 'name' => 'DNS', 'fields' => [], 'lines' => false, 'types' => ['A', 'AAAA', 'CNAME', 'TXT', 'MX'], 'doc' => ''];
    }

    /** 校验凭据，成功返回 true，失败抛 DnsException */
    abstract public function check();

    /** @return array [['id'=>string,'domain'=>string,'record_count'=>int|null]] */
    abstract public function getDomains($keyword = '');

    /** @return array [['id','name','type','value','line','ttl','mx','status']] */
    abstract public function getRecords($domain, $domainId, $subName = null);

    /** @return string 服务商记录 ID */
    abstract public function addRecord($domain, $domainId, $name, $type, $value, $line = 'default', $ttl = 600, $mx = 10);

    /** @return bool */
    abstract public function updateRecord($domain, $domainId, $recordId, $name, $type, $value, $line = 'default', $ttl = 600, $mx = 10);

    /** @return bool */
    abstract public function deleteRecord($domain, $domainId, $recordId);

    /** 本次增改记录是否开启 CDN 代理（仅支持代理的服务商使用，如 Cloudflare 橙云） */
    public $proxied = false;

    /** 是否支持 CDN 代理开关 */
    public function supportsProxy()
    {
        return false;
    }

    /** @return array 统一线路代码 => 中文名 */
    public function getLines()
    {
        return ['default' => '默认'];
    }

    // ---------------- 工具方法 ----------------

    protected function cfg($key, $default = '')
    {
        return isset($this->config[$key]) && $this->config[$key] !== null ? $this->config[$key] : $default;
    }

    /** 实际请求基地址（官方地址或自定义 endpoint），不带末尾斜杠 */
    protected function baseUrl($official)
    {
        $e = trim((string)$this->cfg('endpoint'));

        return rtrim($e !== '' ? $e : $official, '/');
    }

    /** 从 URL 取 host[:port]（端口为非默认端口时保留） */
    protected function hostOf($url)
    {
        $p = parse_url($url);
        $h = isset($p['host']) ? $p['host'] : '';
        if (isset($p['port'])) {
            $h .= ':' . $p['port'];
        }

        return $h;
    }

    /** 从 URL 取路径前缀（endpoint 可能带路径，如 http://127.0.0.1:18990/dnspod） */
    protected function pathOf($url)
    {
        $p = parse_url($url);

        return isset($p['path']) ? rtrim($p['path'], '/') : '';
    }

    protected function timeout()
    {
        $t = (int)$this->cfg('timeout', 20);

        return $t > 0 ? $t : 20;
    }

    /**
     * 发送请求，网络错误抛异常；不对 HTTP 状态码做判断（由驱动按各家格式解析错误）
     */
    protected function send($method, $url, array $headers = [], $body = null)
    {
        $r = HttpClient::request($method, $url, $headers, $body, $this->timeout());
        if ($r['status'] === 0) {
            throw new DnsException('无法连接 DNS 服务商接口：' . ($r['error'] !== '' ? $r['error'] : 'unknown error'));
        }

        return $r;
    }

    protected function decode(array $r)
    {
        $d = json_decode($r['body'], true);

        return is_array($d) ? $d : null;
    }

    /** 根据子域名过滤：name 等于 sub 或以 .sub 结尾 */
    protected function filterSub(array $records, $subName)
    {
        if ($subName === null || $subName === '') {
            return array_values($records);
        }
        $subName = strtolower($subName);
        $out = [];
        foreach ($records as $r) {
            $n = strtolower($r['name']);
            if ($n === $subName || substr($n, -strlen('.' . $subName)) === '.' . $subName) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** 相对名 -> FQDN（不带末尾点） */
    protected function toFqdn($name, $domain)
    {
        $name = trim($name);
        if ($name === '' || $name === '@') {
            return $domain;
        }

        return $name . '.' . $domain;
    }

    /** FQDN -> 相对名 */
    protected function toRelative($fqdn, $domain)
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));
        $domain = strtolower(rtrim($domain, '.'));
        if ($fqdn === $domain || $fqdn === '') {
            return '@';
        }
        $suffix = '.' . $domain;
        if (substr($fqdn, -strlen($suffix)) === $suffix) {
            return substr($fqdn, 0, -strlen($suffix));
        }

        return $fqdn;
    }

    /** 统一规范化记录值 */
    protected function cleanValue($value, $type)
    {
        $type = strtoupper($type);
        $value = (string)$value;
        if (in_array($type, ['CNAME', 'MX', 'NS', 'PTR'], true)) {
            return rtrim($value, '.');
        }
        if ($type === 'TXT' && strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            return str_replace('" "', '', substr($value, 1, -1));
        }

        return $value;
    }

    /** 线路映射：统一代码 -> 服务商代码 */
    protected function lineTo($line, array $map)
    {
        return isset($map[$line]) ? $map[$line] : $map['default'];
    }

    /** 线路映射：服务商代码 -> 统一代码 */
    protected function lineFrom($vendorLine, array $map)
    {
        $k = array_search((string)$vendorLine, array_map('strval', $map), true);

        return $k === false ? 'default' : $k;
    }

    /** 线路标签 */
    protected function labelsFor(array $map)
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[$k] = isset(self::$lineLabels[$k]) ? self::$lineLabels[$k] : $k;
        }

        return $out;
    }

    protected function fail($msg, $code = '')
    {
        throw new DnsException($code !== '' ? ($msg . '（' . $code . '）') : $msg);
    }

    /** 当前时间戳；测试可通过 config['_fixed_time'] 固定，用于签名向量校验 */
    protected function now()
    {
        $t = $this->cfg('_fixed_time');

        return $t ? (int)$t : time();
    }
}
