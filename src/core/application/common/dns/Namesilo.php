<?php

namespace app\common\dns;

class Namesilo extends DnsDriver
{
    protected $official = "https://www.namesilo.com/api";
    protected function call($op, $p = [])
    {
        $p["version"] = 1;
        $p["type"] = "json";
        $p["key"] = $this->cfg("api_key");
        $u =
            $this->baseUrl($this->official) .
            "/" .
            $op .
            "?" .
            http_build_query($p);
        $r = $this->send("GET", $u, ["Accept" => "application/json"]);
        $d = $this->decode($r);
        if (!$d || !isset($d["reply"]) || (int) $d["reply"]["code"] !== 300) {
            $msg =
                $d && isset($d["reply"]["detail"])
                    ? $d["reply"]["detail"]
                    : "接口返回无效响应";
            $this->fail(
                "Namesilo API错误：" . $msg,
                $d && isset($d["reply"]["code"])
                    ? (string) $d["reply"]["code"]
                    : (string) $r["status"]
            );
        }

        return $d["reply"];
    }
    public static function info()
    {
        return [
            "code" => "namesilo",
            "name" => "NameSilo",
            "fields" => [
                [
                    "key" => "api_key",
                    "label" => "API Key",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "NameSilo 控制台 API Manager 中生成的 API Key",
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
            "lines" => false,
            "types" => ["A", "AAAA", "CNAME", "TXT", "MX", "NS", "CAA", "SRV"],
            "doc" => "https://www.namesilo.com/api_reference",
        ];
    }
    public function check()
    {
        if ($this->cfg("api_key") === "") {
            $this->fail("Namesilo 凭据不完整");
        }
        $this->call("listDomains", ["page" => 1, "pageSize" => 1]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $r = $this->call("listDomains");
        $a = isset($r["domains"]["domain"]) ? $r["domains"]["domain"] : [];
        if (isset($a["domain"])) {
            $a = [$a];
        }
        $o = [];
        foreach ($a as $x) {
            $n = is_array($x) ? $x["domain"] : $x;
            if ($keyword === "" || stripos($n, $keyword) !== false) {
                $o[] = ["id" => $n, "domain" => $n, "record_count" => null];
            }
        }

        return $o;
    }
    protected function records($r)
    {
        $a = isset($r["resource_record"]) ? $r["resource_record"] : [];
        if (isset($a["rrid"])) {
            $a = [$a];
        }

        return $a;
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $r = $this->call("dnsListRecords", ["domain" => $domain]);
        $o = [];
        foreach ($this->records($r) as $x) {
            $t = strtoupper($x["type"]);
            $n = isset($x["host"]) ? $x["host"] : "";
            $rel = $this->toRelative($n === "" ? $domain : $n, $domain);
            $o[] = [
                "id" => (string) $x["record_id"],
                "name" => $rel,
                "type" => $t,
                "value" => $this->cleanValue(
                    isset($x["value"]) ? $x["value"] : "",
                    $t
                ),
                "line" => "default",
                "ttl" => (int) $x["ttl"],
                "mx" => (int) (isset($x["distance"]) ? $x["distance"] : 0),
                "status" => "enable",
            ];
        }

        return $this->filterSub($o, $subName);
    }
    protected function q($domain, $name, $type, $value, $mx, $ttl)
    {
        return [
            "domain" => $domain,
            "rrtype" => strtoupper($type),
            "rrhost" => $name === "@" ? "" : $name,
            "rrvalue" => $value,
            "rrdistance" => strtoupper($type) === "MX" ? (int) $mx : 0,
            "rrttl" => max(3600, (int) $ttl),
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
        $r = $this->call(
            "dnsAddRecord",
            $this->q($domain, $name, $type, $value, $mx, $ttl)
        );

        return (string) $r["resource_record"]["record_id"];
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
        $p = $this->q($domain, $name, $type, $value, $mx, $ttl);
        $p["rrid"] = $recordId;
        $this->call("dnsUpdateRecord", $p);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->call("dnsDeleteRecord", [
            "domain" => $domain,
            "rrid" => $recordId,
        ]);

        return true;
    }
}
