<?php

namespace app\common\dns;

class Dnspod extends DnsDriver
{
    const OFFICIAL = "https://dnspod.tencentcloudapi.com";
    protected $service = "dnspod";
    protected $version = "2021-03-23";
    protected $lines = [
        "default" => "默认",
        "telecom" => "电信",
        "unicom" => "联通",
        "mobile" => "移动",
        "edu" => "教育网",
        "oversea" => "境外",
        "search" => "搜索引擎",
    ];

    public static function info()
    {
        return [
            "code" => "dnspod",
            "name" => "腾讯云 DNSPod",
            "fields" => [
                [
                    "key" => "secret_id",
                    "label" => "SecretId",
                    "type" => "text",
                    "required" => true,
                    "secret" => true,
                    "help" => "腾讯云控制台访问管理 API 密钥的 SecretId",
                ],
                [
                    "key" => "secret_key",
                    "label" => "SecretKey",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "腾讯云控制台访问管理 API 密钥的 SecretKey",
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
            "doc" => "https://cloud.tencent.com/document/product/1427",
        ];
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
    protected function signKey($key, $date, $service)
    {
        return hash_hmac("sha256", $date, "TC3" . $key, true);
    }
    public function signRequest($action, $payload, $timestamp = null)
    {
        $timestamp = $timestamp === null ? $this->now() : (int) $timestamp;
        $date = gmdate("Y-m-d", $timestamp);
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($body === false) {
            $body = "{}";
        }
        $ct = "application/json; charset=utf-8";
        $host = $this->hostOf($this->baseUrl(self::OFFICIAL));
        $canonicalHeaders =
            "content-type:" . $ct . "\n" . "host:" . $host . "\n";
        $signed = "content-type;host";
        $canonicalRequest =
            "POST\n/\n\n" .
            $canonicalHeaders .
            "\n" .
            $signed .
            "\n" .
            hash("sha256", $body);
        $algorithm = "TC3-HMAC-SHA256";
        $credential =
            $this->cfg("secret_id") .
            "/" .
            $date .
            "/" .
            $this->service .
            "/tc3_request";
        $string =
            $algorithm .
            "\n" .
            $timestamp .
            "\n" .
            $date .
            "/" .
            $this->service .
            "/tc3_request\n" .
            hash("sha256", $canonicalRequest);
        $kDate = $this->signKey($this->cfg("secret_key"), $date, "");
        $kService = hash_hmac("sha256", $this->service, $kDate, true);
        $kSigning = hash_hmac("sha256", "tc3_request", $kService, true);
        $sig = hash_hmac("sha256", $string, $kSigning);

        return [
            "body" => $body,
            "timestamp" => $timestamp,
            "headers" => [
                "Content-Type" => $ct,
                "Host" => $host,
                "X-TC-Action" => $action,
                "X-TC-Version" => $this->version,
                "X-TC-Timestamp" => (string) $timestamp,
                "Authorization" =>
                    $algorithm .
                    " Credential=" .
                    $credential .
                    ", SignedHeaders=" .
                    $signed .
                    ", Signature=" .
                    $sig,
            ],
        ];
    }
    protected function call($action, array $p)
    {
        $u = $this->baseUrl(self::OFFICIAL) . "/";
        $s = $this->signRequest($action, $p);
        $r = $this->send("POST", $u, $s["headers"], $s["body"]);
        $d = $this->decode($r);
        if (!is_array($d) || !isset($d["Response"])) {
            $this->fail("DNSPod 接口响应无效", "InvalidResponse");
        }
        if (isset($d["Response"]["Error"])) {
            $e = $d["Response"]["Error"];
            $this->fail(
                "DNSPod 请求失败：" .
                    (isset($e["Message"]) ? $e["Message"] : "unknown"),
                isset($e["Code"]) ? $e["Code"] : "Error"
            );
        }

        return $d["Response"];
    }
    public function check()
    {
        $this->call("DescribeDomainList", ["Limit" => 1, "Offset" => 0]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $o = 0;
        $out = [];
        for ($i = 0; $i < 20; $i++) {
            $p = ["Limit" => 100, "Offset" => $o];
            if ($keyword !== "") {
                $p["Domain"] = $keyword;
            }
            $r = $this->call("DescribeDomainList", $p);
            $a = isset($r["DomainList"]) ? $r["DomainList"] : [];
            foreach ($a as $x) {
                $out[] = [
                    "id" => (string) $x["DomainId"],
                    "domain" => rtrim($x["Name"], "."),
                    "record_count" => isset($x["RecordCount"])
                        ? (int) $x["RecordCount"]
                        : null,
                ];
            }
            if (count($a) < 100) {
                break;
            }
            $o += 100;
        }

        return $out;
    }
    protected function domainName($domain)
    {
        return rtrim($domain, ".");
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $o = 0;
        $out = [];
        for ($i = 0; $i < 20; $i++) {
            $p = [
                "Domain" => $this->domainName($domain),
                "Limit" => 100,
                "Offset" => $o,
            ];
            if ($subName !== null && $subName !== "") {
                $p["Subdomain"] = $this->toFqdn($subName, $domain);
            }
            try {
                $r = $this->call("DescribeRecordList", $p);
            } catch (DnsException $e) {
                if (strpos($e->getMessage(), "NoDataOfRecord") !== false) {
                    return [];
                }
                throw $e;
            }
            $a = isset($r["RecordList"]) ? $r["RecordList"] : [];
            foreach ($a as $x) {
                $type = strtoupper($x["Type"]);
                $name = $this->toRelative($x["Name"], $domain);
                $out[] = [
                    "id" => (string) $x["RecordId"],
                    "name" => $name,
                    "type" => $type,
                    "value" => $this->cleanValue($x["Value"], $type),
                    "line" => $this->lineFrom(
                        isset($x["Line"]) ? $x["Line"] : "默认",
                        $this->lines
                    ),
                    "ttl" => (int) $x["TTL"],
                    "mx" => isset($x["MX"]) ? (int) $x["MX"] : 0,
                    "status" =>
                        isset($x["Status"]) && $x["Status"] == 1
                            ? "disable"
                            : "enable",
                ];
            }
            if (count($a) < 100) {
                break;
            }
            $o += 100;
        }

        return $this->filterSub($out, $subName);
    }
    protected function recordParams(
        $domain,
        $name,
        $type,
        $value,
        $line,
        $ttl,
        $mx
    ) {
        $p = [
            "Domain" => $this->domainName($domain),
            "SubDomain" => $this->toFqdn($name, $domain),
            "RecordType" => strtoupper($type),
            "RecordLine" => $this->lineTo($line, $this->lines),
            "Value" => $type === "TXT" ? '"' . $value . '"' : $value,
            "TTL" => (int) $ttl,
        ];
        if (strtoupper($type) === "MX") {
            $p["MX"] = (int) $mx;
        }

        return $p;
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
        $r = $this->call(
            "CreateRecord",
            $this->recordParams(
                $domain,
                $name,
                $type,
                $value,
                $line,
                $ttl,
                $mx
            )
        );

        return (string) $r["RecordId"];
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
        $p = $this->recordParams(
            $domain,
            $name,
            $type,
            $value,
            $line,
            $ttl,
            $mx
        );
        $p["RecordId"] = (int) $recordId;
        $this->call("ModifyRecord", $p);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->call("DeleteRecord", [
            "Domain" => $this->domainName($domain),
            "RecordId" => (int) $recordId,
        ]);

        return true;
    }
}
