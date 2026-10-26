<?php

namespace app\common\dns;

class Jdcloud extends DnsDriver
{
    const OFFICIAL = "https://domainservice.jdcloud-api.com";
    protected $region = "cn-north-1";
    protected $service = "domainservice";
    protected $lines = [
        "default" => "-1",
        "telecom" => "1",
        "unicom" => "2",
        "mobile" => "3",
        "edu" => "4",
        "oversea" => "5",
    ];
    public static function info()
    {
        return [
            "code" => "jdcloud",
            "name" => "京东云 DNS 云解析",
            "fields" => [
                [
                    "key" => "access_key",
                    "label" => "Access Key",
                    "type" => "text",
                    "required" => true,
                    "secret" => true,
                    "help" => "京东云 IAM 访问密钥 Access Key",
                ],
                [
                    "key" => "secret_key",
                    "label" => "Secret Key",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "京东云 IAM 访问密钥 Secret Key",
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
            "doc" => "https://docs.jdcloud.com/cn/jd-cloud-dns",
        ];
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
    protected function enc($v)
    {
        return rawurlencode((string) $v);
    }
    public function signRequest(
        $method,
        $path,
        $query = [],
        $body = "",
        $timestamp = null,
        $nonce = "00000000-0000-0000-0000-000000000001"
    ) {
        $timestamp = $timestamp === null ? $this->now() : (int) $timestamp;
        $date = gmdate("Ymd\THis\Z", $timestamp);
        $host = $this->hostOf($this->baseUrl(self::OFFICIAL));
        $h = [
            "content-type" => "application/json",
            "host" => $host,
            "x-jdcloud-date" => $date,
            "x-jdcloud-nonce" => $nonce,
        ];
        ksort($h);
        $canon = "";
        foreach ($h as $k => $v) {
            $canon .= strtolower($k) . ":" . trim($v) . "\n";
        }
        $qs = [];
        foreach ($query as $k => $v) {
            $qs[] = $this->enc($k) . "=" . $this->enc($v);
        }
        sort($qs);
        $signed = implode(";", array_keys($h));
        $cr =
            strtoupper($method) .
            "\n" .
            $path .
            "\n" .
            implode("&", $qs) .
            "\n" .
            $canon .
            "\n" .
            $signed .
            "\n" .
            hash("sha256", $body);
        $scope =
            gmdate("Ymd", $timestamp) .
            "/" .
            $this->region .
            "/" .
            $this->service .
            "/jdcloud3_request";
        $st =
            "JDCLOUD3-HMAC-SHA256\n" .
            $date .
            "\n" .
            $scope .
            "\n" .
            hash("sha256", $cr);
        $kDate = hash_hmac(
            "sha256",
            gmdate("Ymd", $timestamp),
            "JDCLOUD3" . $this->cfg("secret_key"),
            true
        );
        $kRegion = hash_hmac("sha256", $this->region, $kDate, true);
        $kSign = hash_hmac("sha256", $this->service, $kRegion, true);
        $kSign = hash_hmac("sha256", "jdcloud3_request", $kSign, true);
        $sig = hash_hmac("sha256", $st, $kSign);
        $h["Authorization"] =
            "JDCLOUD3-HMAC-SHA256 Credential=" .
            $this->cfg("access_key") .
            "/" .
            $scope .
            ", SignedHeaders=" .
            $signed .
            ", Signature=" .
            $sig;

        return ["headers" => $h, "signature" => $sig, "canonical" => $cr];
    }
    protected function call($method, $path, $query = [], $body = null)
    {
        $raw =
            $body === null
                ? ""
                : json_encode(
                    $body,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
        if ($raw === false) {
            $raw = "";
        }
        $s = $this->signRequest($method, $path, $query, $raw);
        $u = $this->baseUrl(self::OFFICIAL) . $path;
        if ($query) {
            $q = [];
            foreach ($query as $k => $v) {
                $q[] = $this->enc($k) . "=" . $this->enc($v);
            }
            $u .= "?" . implode("&", $q);
        }
        $r = $this->send($method, $u, $s["headers"], $raw === "" ? null : $raw);
        $d = $this->decode($r);
        if (!is_array($d)) {
            $this->fail("京东云响应无效", "InvalidResponse");
        }
        if (isset($d["error"])) {
            $this->fail(
                "京东云请求失败：" .
                    (isset($d["error"]["message"])
                        ? $d["error"]["message"]
                        : "unknown"),
                isset($d["error"]["code"]) ? $d["error"]["code"] : "Error"
            );
        }

        return isset($d["result"]) ? $d["result"] : $d;
    }
    protected function path($domainId, $recordId = "")
    {
        return "/v2/regions/" .
            $this->region .
            "/domain/" .
            $domainId .
            "/ResourceRecord" .
            ($recordId !== "" ? "/" . $recordId : "");
    }
    public function check()
    {
        $this->call("GET", "/v2/regions/" . $this->region . "/domain");

        return true;
    }
    public function getDomains($keyword = "")
    {
        $x = $this->call("GET", "/v2/regions/" . $this->region . "/domain");
        $a = isset($x["domainList"])
            ? $x["domainList"]
            : (isset($x["domains"])
                ? $x["domains"]
                : []);
        $o = [];
        foreach ($a as $d) {
            $n = isset($d["domainName"])
                ? $d["domainName"]
                : (isset($d["name"])
                    ? $d["name"]
                    : "");
            if ($keyword === "" || stripos($n, $keyword) !== false) {
                $o[] = [
                    "id" => (string) (isset($d["domainId"])
                        ? $d["domainId"]
                        : $d["id"]),
                    "domain" => rtrim($n, "."),
                    "record_count" => null,
                ];
            }
        }

        return $o;
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $x = $this->call("GET", $this->path($domainId));
        $a = isset($x["resourceRecordList"])
            ? $x["resourceRecordList"]
            : (isset($x["records"])
                ? $x["records"]
                : []);
        $o = [];
        foreach ($a as $r) {
            $t = strtoupper($r["type"]);
            $n = isset($r["hostRecord"]) ? $r["hostRecord"] : "@";
            $o[] = [
                "id" => (string) $r["resourceRecordId"],
                "name" => $n,
                "type" => $t,
                "value" => $this->cleanValue($r["hostValue"], $t),
                "line" => $this->lineFrom(
                    isset($r["viewValue"]) ? $r["viewValue"] : "-1",
                    $this->lines
                ),
                "ttl" => (int) $r["ttl"],
                "mx" => isset($r["mx"]) ? (int) $r["mx"] : 0,
                "status" => "enable",
            ];
        }

        return $this->filterSub($o, $subName);
    }
    protected function body($name, $type, $value, $line, $ttl, $mx)
    {
        return [
            "hostRecord" => $name,
            "hostValue" => $value,
            "type" => strtoupper($type),
            "ttl" => (int) $ttl,
            "viewValue" => $this->lineTo($line, $this->lines),
            "mx" => (int) $mx,
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
        $x = $this->call(
            "POST",
            $this->path($domainId),
            [],
            $this->body($name, $type, $value, $line, $ttl, $mx)
        );

        return (string) (isset($x["resourceRecordId"])
            ? $x["resourceRecordId"]
            : $x["id"]);
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
        $this->call(
            "PUT",
            $this->path($domainId, $recordId),
            [],
            $this->body($name, $type, $value, $line, $ttl, $mx)
        );

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->call("DELETE", $this->path($domainId, $recordId));

        return true;
    }
}
