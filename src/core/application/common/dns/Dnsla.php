<?php

namespace app\common\dns;

class Dnsla extends DnsDriver
{
    protected $official = "https://api.dns.la";
    protected $types = [
        "A" => 1,
        "NS" => 2,
        "CNAME" => 5,
        "MX" => 15,
        "TXT" => 16,
        "AAAA" => 28,
        "SRV" => 33,
        "CAA" => 257,
        "URL" => 256,
    ];
    protected $lines = [
        "default" => "",
        "telecom" => "1",
        "unicom" => "2",
        "mobile" => "3",
        "edu" => "4",
        "oversea" => "5",
    ];
    protected function url($p, $q = [])
    {
        $u = $this->baseUrl($this->official) . $p;

        return $q ? $u . "?" . http_build_query($q) : $u;
    }
    protected function head()
    {
        return [
            "Accept" => "application/json",
            "Content-Type" => "application/json",
            "Authorization" =>
                "Basic " .
                base64_encode(
                    $this->cfg("api_id") . ":" . $this->cfg("api_secret")
                ),
        ];
    }
    protected function call($m, $p, $q = [], $b = null)
    {
        $r = $this->send(
            $m,
            $this->url($p, $q),
            $this->head(),
            $b === null ? null : json_encode($b)
        );
        $d = $this->decode($r);
        if (!$d || (!isset($d["code"]) || (int) $d["code"] !== 200)) {
            $msg = $d && isset($d["msg"]) ? $d["msg"] : "接口返回无效响应";
            $this->fail(
                "DNS.LA API错误：" . $msg,
                $d && isset($d["code"])
                    ? (string) $d["code"]
                    : (string) $r["status"]
            );
        }

        return $d;
    }
    public static function info()
    {
        return [
            "code" => "dnsla",
            "name" => "DNS.LA",
            "fields" => [
                [
                    "key" => "api_id",
                    "label" => "API ID",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "DNS.LA 控制台 API 设置中的 API ID",
                ],
                [
                    "key" => "api_secret",
                    "label" => "API Secret",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "DNS.LA 控制台 API 设置中的 API Secret",
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
            "types" => array_keys((new self([]))->types),
            "doc" => "https://www.dns.la/docs/ApiDoc",
        ];
    }
    public function check()
    {
        if ($this->cfg("api_id") === "" || $this->cfg("api_secret") === "") {
            $this->fail("DNS.LA 凭据不完整");
        }
        $this->call("GET", "/api/domainList", [
            "pageIndex" => 1,
            "pageSize" => 1,
        ]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $o = [];
        $p = 1;
        do {
            $d = $this->call("GET", "/api/domainList", [
                "pageIndex" => $p,
                "pageSize" => 100,
            ]);
            $rows = isset($d["data"]["list"])
                ? $d["data"]["list"]
                : (isset($d["data"]) && is_array($d["data"])
                    ? $d["data"]
                    : []);
            foreach ($rows as $x) {
                $n = isset($x["domain"])
                    ? $x["domain"]
                    : (isset($x["name"])
                        ? $x["name"]
                        : "");
                if ($keyword === "" || stripos($n, $keyword) !== false) {
                    $o[] = [
                        "id" => (string) (isset($x["id"])
                            ? $x["id"]
                            : $x["domainId"]),
                        "domain" => $n,
                        "record_count" => null,
                    ];
                }
            }
            $p++;
        } while (count($rows) >= 100 && $p <= 20);

        return $o;
    }
    protected function domainRows($d)
    {
        $x = isset($d["data"]["list"])
            ? $d["data"]["list"]
            : (isset($d["data"]) && is_array($d["data"])
                ? $d["data"]
                : []);

        return $x;
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $o = [];
        $p = 1;
        do {
            $d = $this->call("GET", "/api/recordList", [
                "domainId" => $domainId,
                "pageIndex" => $p,
                "pageSize" => 100,
                "host" => $subName === null ? "" : $subName,
            ]);
            $rows = $this->domainRows($d);
            foreach ($rows as $x) {
                $t = isset($x["type"]) ? (int) $x["type"] : 1;
                $tn = array_search($t, $this->types, true);
                if ($tn === false) {
                    $tn = "A";
                }
                $host = isset($x["host"])
                    ? $x["host"]
                    : (isset($x["name"])
                        ? $x["name"]
                        : "@");
                $o[] = [
                    "id" => (string) $x["id"],
                    "name" => $host === "" ? "@" : $host,
                    "type" => $tn,
                    "value" => $this->cleanValue(
                        isset($x["data"]) ? $x["data"] : "",
                        $tn
                    ),
                    "line" => $this->lineFrom(
                        isset($x["lineId"]) ? $x["lineId"] : "",
                        $this->lines
                    ),
                    "ttl" => (int) (isset($x["ttl"]) ? $x["ttl"] : 600),
                    "mx" => (int) (isset($x["preference"])
                        ? $x["preference"]
                        : 0),
                    "status" => "enable",
                ];
            }
            $p++;
        } while (count($rows) >= 100 && $p <= 20);

        return $this->filterSub($o, $subName);
    }
    protected function body($domainId, $name, $type, $value, $ttl, $mx, $line)
    {
        $b = [
            "domainId" => $domainId,
            "type" => $this->types[strtoupper($type)],
            "host" => $name === "@" ? "" : $name,
            "data" => $value,
            "ttl" => (int) $ttl,
            "preference" => strtoupper($type) === "MX" ? (int) $mx : 0,
            "lineId" => $this->lineTo($line, $this->lines),
        ];

        return $b;
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
        $d = $this->call(
            "POST",
            "/api/record",
            [],
            $this->body($domainId, $name, $type, $value, $ttl, $mx, $line)
        );

        return (string) (isset($d["data"]["id"])
            ? $d["data"]["id"]
            : $d["data"]);
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
        $b = $this->body($domainId, $name, $type, $value, $ttl, $mx, $line);
        $b["id"] = $recordId;
        $this->call("PUT", "/api/record", [], $b);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->call("DELETE", "/api/record", ["id" => $recordId]);

        return true;
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
}
