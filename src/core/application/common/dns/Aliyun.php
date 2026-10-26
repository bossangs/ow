<?php

namespace app\common\dns;

class Aliyun extends DnsDriver
{
    const OFFICIAL = "https://alidns.aliyuncs.com";
    protected $lines = [
        "default" => "default",
        "telecom" => "telecom",
        "unicom" => "unicom",
        "mobile" => "mobile",
        "edu" => "edu",
        "oversea" => "oversea",
        "search" => "search",
    ];
    public static function info()
    {
        return [
            "code" => "aliyun",
            "name" => "阿里云云解析",
            "fields" => [
                [
                    "key" => "access_key_id",
                    "label" => "AccessKey ID",
                    "type" => "text",
                    "required" => true,
                    "secret" => true,
                    "help" => "阿里云 RAM/API 密钥 AccessKey ID",
                ],
                [
                    "key" => "access_key_secret",
                    "label" => "AccessKey Secret",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "阿里云 RAM/API 密钥 AccessKey Secret",
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
            "doc" => "https://help.aliyun.com/document_detail/29739.html",
        ];
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
    protected function enc($v)
    {
        return str_replace(
            ["+", "*", "%7E"],
            ["%20", "%2A", "~"],
            rawurlencode((string) $v)
        );
    }
    public function signRequest(
        $action,
        array $params,
        $nonce = null,
        $timestamp = null
    ) {
        $nonce = $nonce ?: "4f2b8f5c-2b7e-4f3a-9f8d-000000000001";
        $timestamp =
            $timestamp === null
                ? gmdate("Y-m-d\TH:i:s\Z", $this->now())
                : $timestamp;
        $p = array_merge(
            [
                "Format" => "JSON",
                "Version" => "2015-01-09",
                "AccessKeyId" => $this->cfg("access_key_id"),
                "SignatureMethod" => "HMAC-SHA1",
                "SignatureNonce" => $nonce,
                "SignatureVersion" => "1.0",
                "Signature" => $this->cfg("access_key_secret"),
                "Timestamp" => $timestamp,
                "Action" => $action,
            ],
            $params
        );
        ksort($p);
        $q = [];
        foreach ($p as $k => $v) {
            if ($k !== "Signature") {
                $q[] = $this->enc($k) . "=" . $this->enc($v);
            }
        }
        $canonical = implode("&", $q);
        $sts = "GET&%2F&" . $this->enc($canonical);
        $sig = base64_encode(
            hash_hmac(
                "sha1",
                $sts,
                $this->cfg("access_key_secret") . "&",
                true
            )
        );
        $p["Signature"] = $sig;

        return ["params" => $p, "string_to_sign" => $sts, "signature" => $sig];
    }
    protected function call($action, $params)
    {
        $s = $this->signRequest($action, $params);
        $q = [];
        foreach ($s["params"] as $k => $v) {
            $q[] = $this->enc($k) . "=" . $this->enc($v);
        }
        $r = $this->send(
            "GET",
            $this->baseUrl(self::OFFICIAL) . "?" . implode("&", $q),
            []
        );
        $d = $this->decode($r);
        if (!is_array($d)) {
            $this->fail("阿里云响应无效", "InvalidResponse");
        }
        if (isset($d["Code"])) {
            $this->fail(
                "阿里云请求失败：" .
                    (isset($d["Message"]) ? $d["Message"] : "unknown"),
                $d["Code"]
            );
        }

        return $d;
    }
    public function check()
    {
        $this->call("DescribeDomains", ["PageNumber" => 1, "PageSize" => 1]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $o = [];
        for ($p = 1; $p <= 20; $p++) {
            $x = $this->call("DescribeDomains", [
                "PageNumber" => $p,
                "PageSize" => 100,
            ]);
            $a = isset($x["Domains"]["Domain"]) ? $x["Domains"]["Domain"] : [];
            foreach ($a as $d) {
                if (
                    $keyword === "" ||
                    stripos($d["DomainName"], $keyword) !== false
                ) {
                    $o[] = [
                        "id" => (string) $d["DomainId"],
                        "domain" => rtrim($d["DomainName"], "."),
                        "record_count" => isset($d["RecordCount"])
                            ? (int) $d["RecordCount"]
                            : null,
                    ];
                }
            }
            if (count($a) < 100) {
                break;
            }
        }

        return $o;
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $o = [];
        for ($p = 1; $p <= 20; $p++) {
            $pa = [
                "DomainName" => rtrim($domain, "."),
                "PageNumber" => $p,
                "PageSize" => 100,
            ];
            if ($subName !== null && $subName !== "") {
                $pa["RRKeyWord"] = $subName;
            }
            $x = $this->call("DescribeDomainRecords", $pa);
            $a = isset($x["DomainRecords"]["Record"])
                ? $x["DomainRecords"]["Record"]
                : [];
            foreach ($a as $r) {
                $t = strtoupper($r["Type"]);
                $o[] = [
                    "id" => (string) $r["RecordId"],
                    "name" => $r["RR"] === "@" ? "@" : $r["RR"],
                    "type" => $t,
                    "value" => $this->cleanValue($r["Value"], $t),
                    "line" => $this->lineFrom(
                        isset($r["Line"]) ? $r["Line"] : "default",
                        $this->lines
                    ),
                    "ttl" => (int) $r["TTL"],
                    "mx" => isset($r["Priority"]) ? (int) $r["Priority"] : 0,
                    "status" => "enable",
                ];
            }
            if (count($a) < 100) {
                break;
            }
        }

        return $this->filterSub($o, $subName);
    }
    protected function base($domain, $name, $type, $value, $line, $ttl, $mx)
    {
        $v = $type === "TXT" ? '"' . $value . '"' : $value;

        return [
            "DomainName" => rtrim($domain, "."),
            "RR" => $name === "@" ? "@" : $name,
            "Type" => strtoupper($type),
            "Value" => $v,
            "TTL" => (int) $ttl,
            "Line" => $this->lineTo($line, $this->lines),
            "Priority" => (int) $mx,
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
            "AddDomainRecord",
            $this->base($domain, $name, $type, $value, $line, $ttl, $mx)
        );

        return (string) $x["RecordId"];
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
        $p = $this->base($domain, $name, $type, $value, $line, $ttl, $mx);
        $p["RecordId"] = $recordId;
        $this->call("UpdateDomainRecord", $p);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->call("DeleteDomainRecord", ["RecordId" => $recordId]);

        return true;
    }
}
