<?php

namespace app\common\dns;

class Huawei extends DnsDriver
{
    private $official = "https://dns.myhuaweicloud.com";
    private $lines = [
        "default" => "default_view",
        "telecom" => "Dianxin",
        "unicom" => "Liantong",
        "mobile" => "Yidong",
        "edu" => "Jiaoyuwang",
        "oversea" => "Abroad",
        "search" => "Search",
    ];

    public static function info()
    {
        return [
            "code" => "huawei",
            "name" => "华为云云解析 DNS",
            "fields" => [
                [
                    "key" => "access_key",
                    "label" => "访问密钥 AK",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "华为云控制台“我的凭证”中的访问密钥 ID",
                ],
                [
                    "key" => "secret_key",
                    "label" => "秘密访问密钥 SK",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "创建访问密钥时下载或保存的 Secret Access Key",
                ],
                [
                    "key" => "project_id",
                    "label" => "项目 ID（可选）",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "华为云项目 ID；公共 DNS 通常无需填写",
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
                "https://support.huaweicloud.com/intl/en-us/api-dns/dns_api_60000.html",
        ];
    }
    private function url($path, $query = "")
    {
        return $this->baseUrl($this->official) .
            $path .
            ($query !== "" ? "?" . $query : "");
    }
    private function enc($s)
    {
        return str_replace("%7E", "~", rawurlencode((string) $s));
    }
    private function query($a)
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
        $ts = $fixed !== null ? $fixed : gmdate("Ymd\\THis\\Z", $this->now());
        if (is_int($ts) || ctype_digit((string) $ts)) {
            $ts = gmdate("Ymd\\THis\\Z", (int) $ts);
        }
        $host = $this->hostOf($this->baseUrl($this->official));
        $bodyHash = hash("sha256", (string) $body);
        $headers = [
            "content-type" => "application/json",
            "host" => $host,
            "x-sdk-date" => $ts,
        ];
        ksort($headers);
        $ch = "";
        foreach ($headers as $k => $v) {
            $ch .=
                $k .
                ":" .
                trim(preg_replace("/\\s+/", " ", (string) $v) . "") .
                "\n";
        }
        $signed = implode(";", array_keys($headers));
        $canonicalUri = $path === "" ? "/" : $path;
        if (substr($canonicalUri, -1) !== "/") {
            $canonicalUri .= "/";
        }
        $cr =
            strtoupper($method) .
            "\n" .
            $canonicalUri .
            "\n" .
            $query .
            "\n" .
            $ch .
            "\n" .
            $signed .
            "\n" .
            $bodyHash;
        $string = "SDK-HMAC-SHA256\n" . $ts . "\n" . hash("sha256", $cr);
        $sig = hash_hmac("sha256", $string, $this->cfg("secret_key"));

        return [
            "content-type" => "application/json",
            "host" => $host,
            "x-sdk-date" => $ts,
            "Authorization" =>
                "SDK-HMAC-SHA256 Access=" .
                $this->cfg("access_key") .
                ", SignedHeaders=" .
                $signed .
                ", Signature=" .
                $sig,
        ];
    }
    private function request($method, $path, $params = [], $body = null)
    {
        $q = $this->query($params);
        $raw = $body === null ? "" : json_encode($body);
        $u = $this->url($path, $q);
        $h = $this->sign($method, $path, $q, $raw);
        $r = $this->send($method, $u, $h, $raw);
        $d = $this->decode($r);
        if (!is_array($d)) {
            $this->fail("华为云返回格式错误");
        }
        if (
            $r["status"] < 200 ||
            $r["status"] >= 300 ||
            isset($d["error_code"])
        ) {
            $this->fail(
                "华为云 DNS 请求失败",
                isset($d["error_code"])
                    ? $d["error_code"]
                    : (isset($d["code"])
                        ? $d["code"]
                        : (string) $r["status"])
            );
        }

        return $d;
    }
    public function check()
    {
        $this->request("GET", "/v2/zones", ["type" => "public", "limit" => 1]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $out = [];
        $offset = 0;
        for ($p = 0; $p < 20; $p++) {
            $d = $this->request("GET", "/v2/zones", [
                "type" => "public",
                "limit" => 100,
                "offset" => $offset,
            ]);
            $rows = isset($d["zones"]) ? $d["zones"] : [];
            foreach ($rows as $z) {
                $n = rtrim(isset($z["name"]) ? $z["name"] : "", " .");
                if ($keyword !== "" && stripos($n, $keyword) === false) {
                    continue;
                }
                $out[] = [
                    "id" => (string) $z["id"],
                    "domain" => $n,
                    "record_count" => isset($z["record_num"])
                        ? (int) $z["record_num"]
                        : null,
                ];
            }
            if (count($rows) < 100) {
                break;
            }
            $offset += 100;
        }

        return $out;
    }
    private function records($domain, $id, $sub = null)
    {
        $out = [];
        $offset = 0;
        for ($p = 0; $p < 20; $p++) {
            $d = $this->request(
                "GET",
                "/v2.1/zones/" . rawurlencode($id) . "/recordsets",
                ["limit" => 100, "offset" => $offset]
            );
            $rows = isset($d["recordsets"]) ? $d["recordsets"] : [];
            foreach ($rows as $r) {
                $type = strtoupper($r["type"]);
                $nm = $this->toRelative($r["name"], $domain);
                $vals = isset($r["records"]) ? $r["records"] : [];
                foreach ($vals as $v) {
                    $out[] = [
                        "id" => (string) $r["id"],
                        "name" => $nm,
                        "type" => $type,
                        "value" => $this->cleanValue($v, $type),
                        "line" => $this->lineFrom(
                            isset($r["line"]) ? $r["line"] : "default_view",
                            $this->lines
                        ),
                        "ttl" => isset($r["ttl"]) ? (int) $r["ttl"] : 600,
                        "mx" =>
                            $type === "MX"
                                ? (int) preg_replace(
                                    "/^([0-9]+)\\s+.*/",
                                    '$1',
                                    $v
                                )
                                : 10,
                        "status" => "enable",
                    ];
                }
            }
            if (count($rows) < 100) {
                break;
            }
            $offset += 100;
        }

        return $this->filterSub($out, $sub);
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        return $this->records($domain, $domainId, $subName);
    }
    private function payload($domain, $name, $type, $value, $line, $ttl, $mx)
    {
        $v =
            $type === "TXT"
                ? '"' . str_replace('"', '\\"', $value) . '"'
                : ($type === "MX"
                    ? ((int) $mx) . " " . rtrim($value, ".")
                    : $value);

        return [
            "name" => $this->toFqdn($name, $domain) . ".",
            "type" => strtoupper($type),
            "records" => [$v],
            "ttl" => (int) $ttl,
            "line" => $this->lineTo($line, $this->lines),
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
        $d = $this->request(
            "POST",
            "/v2.1/zones/" . rawurlencode($domainId) . "/recordsets",
            [],
            $this->payload($domain, $name, $type, $value, $line, $ttl, $mx)
        );

        return isset($d["id"])
            ? (string) $d["id"]
            : (isset($d["recordset"]["id"])
                ? (string) $d["recordset"]["id"]
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
        $this->request(
            "PUT",
            "/v2.1/zones/" .
                rawurlencode($domainId) .
                "/recordsets/" .
                rawurlencode($recordId),
            [],
            $this->payload($domain, $name, $type, $value, $line, $ttl, $mx)
        );

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->request(
            "DELETE",
            "/v2.1/zones/" .
                rawurlencode($domainId) .
                "/recordsets/" .
                rawurlencode($recordId)
        );

        return true;
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
}
