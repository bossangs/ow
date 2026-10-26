<?php

namespace app\common\dns;

class Volcengine extends DnsDriver
{
    const OFFICIAL = "https://open.volcengineapi.com";
    protected $service = "DNS";
    protected $region = "cn-north-1";
    protected $version = "2018-08-01";
    protected $lines = [
        "default" => "default",
        "telecom" => "telecom",
        "unicom" => "unicom",
        "mobile" => "mobile",
        "edu" => "edu",
        "oversea" => "oversea",
    ];
    public static function info()
    {
        return [
            "code" => "volcengine",
            "name" => "火山引擎云解析 DNS",
            "fields" => [
                [
                    "key" => "access_key_id",
                    "label" => "Access Key ID",
                    "type" => "text",
                    "required" => true,
                    "secret" => true,
                    "help" => "火山引擎访问控制控制台的 Access Key ID",
                ],
                [
                    "key" => "secret_access_key",
                    "label" => "Secret Access Key",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "火山引擎访问控制控制台的 Secret Access Key",
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
            "doc" => "https://www.volcengine.com/docs/6291",
        ];
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
    protected function hv($k, $s)
    {
        return hash_hmac("sha256", $s, $k, true);
    }
    public function signRequest($action, array $body, $timestamp = null)
    {
        $ts = $timestamp === null ? $this->now() : (int) $timestamp;
        $date = gmdate("Ymd", $ts);
        $xdate = gmdate("Ymd\THis\Z", $ts);
        $raw = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($raw === false) {
            $raw = "{}";
        }
        $payload = hash("sha256", $raw);
        $path = "/";
        $host = $this->hostOf($this->baseUrl(self::OFFICIAL));
        $signed = "content-type;host;x-content-sha256;x-date";
        $headers =
            "content-type:application/json\n" .
            "host:" .
            $host .
            "\n" .
            "x-content-sha256:" .
            $payload .
            "\n" .
            "x-date:" .
            $xdate .
            "\n";
        $query =
            "Action=" .
            rawurlencode($action) .
            "&Version=" .
            rawurlencode($this->version);
        $cr =
            "POST\n" .
            $path .
            "\n" .
            $query .
            "\n" .
            $headers .
            "\n" .
            $signed .
            "\n" .
            $payload;
        $scope =
            $date . "/" . $this->region . "/" . $this->service . "/request";
        $st =
            "HMAC-SHA256\n" .
            $xdate .
            "\n" .
            $scope .
            "\n" .
            hash("sha256", $cr);
        $kDate = $this->hv($this->cfg("secret_access_key"), $date);
        $kRegion = $this->hv($kDate, $this->region);
        $kService = $this->hv($kRegion, $this->service);
        $kSigning = $this->hv($kService, "request");
        $sig = hash_hmac("sha256", $st, $kSigning);
        $auth =
            "HMAC-SHA256 Credential=" .
            $this->cfg("access_key_id") .
            "/" .
            $scope .
            ", SignedHeaders=" .
            $signed .
            ", Signature=" .
            $sig;

        return [
            "body" => $raw,
            "headers" => [
                "Content-Type" => "application/json",
                "Host" => $host,
                "X-Date" => $xdate,
                "X-Content-Sha256" => $payload,
                "Authorization" => $auth,
            ],
        ];
    }
    protected function call($action, array $p)
    {
        $s = $this->signRequest($action, $p);
        $u =
            $this->baseUrl(self::OFFICIAL) .
            "?Action=" .
            rawurlencode($action) .
            "&Version=" .
            $this->version;
        $r = $this->send("POST", $u, $s["headers"], $s["body"]);
        $d = $this->decode($r);
        if (!is_array($d)) {
            $this->fail("火山引擎响应无效", "InvalidResponse");
        }
        if (isset($d["ResponseMetadata"]["Error"])) {
            $e = $d["ResponseMetadata"]["Error"];
            $this->fail(
                "火山引擎请求失败：" .
                    (isset($e["Message"]) ? $e["Message"] : "unknown"),
                isset($e["Code"]) ? $e["Code"] : "Error"
            );
        }

        return isset($d["Result"]) ? $d["Result"] : $d;
    }
    public function check()
    {
        $this->call("ListZones", ["PageNumber" => 1, "PageSize" => 1]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $o = [];
        for ($p = 1; $p <= 20; $p++) {
            $x = $this->call("ListZones", [
                "PageNumber" => $p,
                "PageSize" => 100,
            ]);
            $a = isset($x["Zones"])
                ? $x["Zones"]
                : (isset($x["ZoneList"])
                    ? $x["ZoneList"]
                    : []);
            foreach ($a as $z) {
                $n = isset($z["Name"])
                    ? $z["Name"]
                    : (isset($z["ZoneName"])
                        ? $z["ZoneName"]
                        : "");
                if ($keyword === "" || stripos($n, $keyword) !== false) {
                    $o[] = [
                        "id" => (string) (isset($z["ZID"])
                            ? $z["ZID"]
                            : $z["Id"]),
                        "domain" => rtrim($n, "."),
                        "record_count" => null,
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
            $pa = ["ZID" => $domainId, "PageNumber" => $p, "PageSize" => 100];
            if ($subName !== null && $subName !== "") {
                $pa["Host"] = $this->toFqdn($subName, $domain);
            }
            $x = $this->call("ListRecords", $pa);
            $a = isset($x["Records"])
                ? $x["Records"]
                : (isset($x["RecordList"])
                    ? $x["RecordList"]
                    : []);
            foreach ($a as $r) {
                $t = strtoupper($r["Type"]);
                $n = isset($r["Host"])
                    ? $r["Host"]
                    : (isset($r["Name"])
                        ? $r["Name"]
                        : "@");
                $o[] = [
                    "id" => (string) (isset($r["RecordID"])
                        ? $r["RecordID"]
                        : $r["Id"]),
                    "name" => $this->toRelative($n, $domain),
                    "type" => $t,
                    "value" => $this->cleanValue(
                        isset($r["Value"]) ? $r["Value"] : "",
                        $t
                    ),
                    "line" => $this->lineFrom(
                        isset($r["Line"]) ? $r["Line"] : "default",
                        $this->lines
                    ),
                    "ttl" => (int) (isset($r["TTL"]) ? $r["TTL"] : 600),
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
        return [
            "ZID" => $this->cfg("_zid"),
            "Host" => $this->toFqdn($name, $domain),
            "Type" => strtoupper($type),
            "Value" => $value,
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
        $p = $this->base($domain, $name, $type, $value, $line, $ttl, $mx);
        $p["ZID"] = $domainId;
        $x = $this->call("CreateRecord", $p);

        return (string) (isset($x["RecordID"]) ? $x["RecordID"] : $x["Id"]);
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
        $p["ZID"] = $domainId;
        $p["RecordID"] = $recordId;
        $this->call("UpdateRecord", $p);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->call("DeleteRecord", [
            "ZID" => $domainId,
            "RecordID" => $recordId,
        ]);

        return true;
    }
}
