<?php

namespace app\common\dns;

class Baidu extends DnsDriver
{
    private $official = "https://dns.baidubce.com";
    private $lines = [
        "default" => "default",
        "telecom" => "ct",
        "unicom" => "cnc",
        "mobile" => "cmnet",
        "edu" => "edu",
        "oversea" => "oversea",
        "search" => "search",
    ];
    public static function info()
    {
        return [
            "code" => "baidu",
            "name" => "百度智能云 DNS",
            "fields" => [
                [
                    "key" => "access_key",
                    "label" => "访问密钥 AK",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "百度智能云控制台安全认证中的 Access Key",
                ],
                [
                    "key" => "secret_key",
                    "label" => "秘密访问密钥 SK",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "百度智能云控制台创建 AK/SK 时生成的 Secret Key",
                ],
                [
                    "key" => "endpoint",
                    "label" => "自定义 API 地址（可选）",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "留空使用官方地址；可填 API 反向代理地址",
                ],
            ],
            "lines" => true,
            "types" => ["A", "AAAA", "CNAME", "TXT", "MX"],
            "doc" =>
                "https://intl.cloud.baidu.com/en/doc/DNS/s/Skk5e9in2-intl-en",
        ];
    }
    private function enc($s)
    {
        return str_replace("%7E", "~", rawurlencode((string) $s));
    }
    private function qs($a)
    {
        ksort($a);
        $o = [];
        foreach ($a as $k => $v) {
            $o[] = $this->enc($k) . "=" . $this->enc($v);
        }

        return implode("&", $o);
    }
    public function sign($method, $path, $query, $body, $fixed = null)
    {
        $t =
            $fixed !== null ? $fixed : gmdate("Y-m-d\\TH:i:s\\Z", $this->now());
        if (is_int($t) || ctype_digit((string) $t)) {
            $t = gmdate("Y-m-d\\TH:i:s\\Z", (int) $t);
        }
        $host = $this->hostOf($this->baseUrl($this->official));
        $h = [
            "host" => $host,
            "x-bce-date" => $t,
            "content-type" => "application/json",
        ];
        ksort($h);
        $canonLines = [];
        $signed = [];
        foreach ($h as $k => $v) {
            $canonLines[] =
                strtolower($k) .
                ":" .
                $this->enc(trim(preg_replace("/\\s+/", " ", (string) $v)));
            $signed[] = strtolower($k);
        }
        $canon = implode("\n", $canonLines);
        $signed = implode(";", $signed);
        $cr =
            strtoupper($method) .
            "\n" .
            ($path === "" ? "/" : $path) .
            "\n" .
            $query .
            "\n" .
            $canon;
        $signKeyInfo =
            "bce-auth-v1/" .
            $this->cfg("access_key") .
            "/" .
            $t .
            "/1800";
        $prefix = $signKeyInfo . "/" . $signed;
        $key = hash_hmac("sha256", $signKeyInfo, $this->cfg("secret_key"));
        $sig = hash_hmac("sha256", $cr, $key);

        return [
            "host" => $host,
            "x-bce-date" => $t,
            "content-type" => "application/json",
            "Authorization" => $prefix . "/" . $sig,
        ];
    }
    private function req($method, $path, $params = [], $body = null)
    {
        $q = $this->qs($params);
        $raw = $body === null ? "" : json_encode($body);
        $r = $this->send(
            $method,
            $this->baseUrl($this->official) .
                $path .
                ($q !== "" ? "?" . $q : ""),
            $this->sign($method, $path, $q, $raw),
            $raw
        );
        $d = $this->decode($r);
        if (!is_array($d)) {
            $this->fail("百度云返回格式错误");
        }
        if (
            $r["status"] < 200 ||
            $r["status"] >= 300 ||
            (isset($d["code"]) && $d["code"] !== "SUCCESS")
        ) {
            $this->fail(
                "百度 DNS 请求失败",
                isset($d["code"]) ? $d["code"] : (string) $r["status"]
            );
        }

        return $d;
    }
    public function check()
    {
        $this->req("GET", "/v1/dns/zone", ["maxKeys" => 1]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $o = [];
        $marker = "";
        for ($p = 0; $p < 20; $p++) {
            $q = ["maxKeys" => 100];
            if ($marker !== "") {
                $q["marker"] = $marker;
            }
            $d = $this->req("GET", "/v1/dns/zone", $q);
            $rows = isset($d["zones"])
                ? $d["zones"]
                : (isset($d["result"])
                    ? $d["result"]
                    : []);
            foreach ($rows as $z) {
                $n = rtrim(
                    isset($z["name"])
                        ? $z["name"]
                        : (isset($z["zoneName"])
                            ? $z["zoneName"]
                            : ""),
                    "."
                );
                if ($keyword !== "" && stripos($n, $keyword) === false) {
                    continue;
                }
                $o[] = [
                    "id" => $n,
                    "domain" => $n,
                    "record_count" => isset($z["recordCount"])
                        ? (int) $z["recordCount"]
                        : null,
                ];
            }
            if (
                count($rows) < 100 ||
                !isset($d["nextMarker"]) ||
                $d["nextMarker"] === ""
            ) {
                break;
            }
            $marker = $d["nextMarker"];
        }

        return $o;
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $o = [];
        $marker = "";
        for ($p = 0; $p < 20; $p++) {
            $q = ["maxKeys" => 100];
            if ($subName !== null && $subName !== "") {
                $q["rr"] = $subName;
            }
            if ($marker !== "") {
                $q["marker"] = $marker;
            }
            $d = $this->req(
                "GET",
                "/v1/dns/zone/" . rawurlencode($domain) . "/record",
                $q
            );
            $rows = isset($d["records"])
                ? $d["records"]
                : (isset($d["result"])
                    ? $d["result"]
                    : []);
            foreach ($rows as $r) {
                $type = strtoupper($r["type"]);
                $val = isset($r["value"]) ? $r["value"] : "";
                $o[] = [
                    "id" => (string) (isset($r["id"]) ? $r["id"] : ""),
                    "name" => $r["rr"] === "" ? "@" : $r["rr"],
                    "type" => $type,
                    "value" => $this->cleanValue($val, $type),
                    "line" => $this->lineFrom(
                        isset($r["line"]) ? $r["line"] : "default",
                        $this->lines
                    ),
                    "ttl" => isset($r["ttl"]) ? (int) $r["ttl"] : 600,
                    "mx" =>
                        $type === "MX" && isset($r["priority"])
                            ? (int) $r["priority"]
                            : 10,
                    "status" => "enable",
                ];
            }
            if (
                count($rows) < 100 ||
                !isset($d["nextMarker"]) ||
                $d["nextMarker"] === ""
            ) {
                break;
            }
            $marker = $d["nextMarker"];
        }

        return $this->filterSub($o, $subName);
    }
    private function body($name, $type, $value, $line, $ttl, $mx)
    {
        $v =
            $type === "TXT"
                ? '"' . str_replace('"', '\\"', $value) . '"'
                : $value;

        return [
            "rr" => $name === "@" ? "" : $name,
            "type" => strtoupper($type),
            "value" => $type === "MX" ? (string) $v : $v,
            "ttl" => (int) $ttl,
            "line" => $this->lineTo($line, $this->lines),
            "priority" => (int) $mx,
        ];
    }
    public function addRecord(
        $domain,
        $domainId,
        $name,
        $type,
        $value,
        $line = "default",
        $ttl = 600,
        $mx = 10
    ) {
        $q = ["clientToken" => md5(uniqid("", true))];
        $d = $this->req(
            "POST",
            "/v1/dns/zone/" . rawurlencode($domain) . "/record",
            $q,
            $this->body($name, $type, $value, $line, $ttl, $mx)
        );

        return isset($d["id"])
            ? (string) $d["id"]
            : (isset($d["record"]["id"])
                ? (string) $d["record"]["id"]
                : "");
    }
    public function updateRecord(
        $domain,
        $domainId,
        $recordId,
        $name,
        $type,
        $value,
        $line = "default",
        $ttl = 600,
        $mx = 10
    ) {
        $this->req(
            "PUT",
            "/v1/dns/zone/" .
                rawurlencode($domain) .
                "/record/" .
                rawurlencode($recordId),
            ["clientToken" => md5(uniqid("", true))],
            $this->body($name, $type, $value, $line, $ttl, $mx)
        );

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->req(
            "DELETE",
            "/v1/dns/zone/" .
                rawurlencode($domain) .
                "/record/" .
                rawurlencode($recordId),
            ["clientToken" => md5(uniqid("", true))]
        );

        return true;
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
}
