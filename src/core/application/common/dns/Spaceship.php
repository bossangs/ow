<?php

namespace app\common\dns;

class Spaceship extends DnsDriver
{
    public static function info()
    {
        return [
            "code" => "spaceship",
            "name" => "Spaceship DNS",
            "fields" => [
                [
                    "key" => "api_key",
                    "label" => "API Key",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "在 Spaceship Developer API 控制台获取",
                ],
                [
                    "key" => "api_secret",
                    "label" => "API Secret",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "在 Spaceship Developer API 控制台获取",
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
            "types" => ["A", "AAAA", "CNAME", "TXT", "MX", "NS"],
            "doc" => "https://docs.spaceship.dev/",
        ];
    }
    private function base()
    {
        return $this->baseUrl("https://spaceship.dev/api");
    }
    private function req($method, $path, $body = null)
    {
        $h = [
            "X-API-Key" => (string) $this->cfg("api_key"),
            "X-API-Secret" => (string) $this->cfg("api_secret"),
            "Accept" => "application/json",
            "Content-Type" => "application/json",
        ];
        $r = $this->send(
            $method,
            $this->base() . $path,
            $h,
            $body === null ? null : json_encode($body)
        );
        $d = $this->decode($r);
        if ($r["status"] < 200 || $r["status"] >= 300) {
            $detail =
                is_array($d) && isset($d["detail"]) ? $d["detail"] : "请求失败";
            $this->fail(
                "Spaceship 请求失败：" .
                    (is_string($detail) ? $detail : json_encode($detail)),
                is_array($d) && isset($d["data"])
                    ? json_encode($d["data"])
                    : "HTTP " . $r["status"]
            );
        }

        return $d;
    }
    public function check()
    {
        $this->req("GET", "/v1/domains?take=1&skip=0");

        return true;
    }
    public function getDomains($keyword = "")
    {
        $out = [];
        $skip = 0;
        for ($i = 0; $i < 20; $i++) {
            $d = $this->req("GET", "/v1/domains?take=100&skip=" . $skip);
            $items = isset($d["items"])
                ? $d["items"]
                : (isset($d["domains"])
                    ? $d["domains"]
                    : []);
            if (!is_array($items) || !count($items)) {
                break;
            }
            foreach ($items as $x) {
                $n = is_array($x)
                    ? (isset($x["name"])
                        ? $x["name"]
                        : (isset($x["domain"])
                            ? $x["domain"]
                            : ""))
                    : (string) $x;
                if (
                    $n === "" ||
                    ($keyword !== "" && stripos($n, $keyword) === false)
                ) {
                    continue;
                }
                $out[] = ["id" => $n, "domain" => $n, "record_count" => null];
            }
            if (count($items) < 100) {
                break;
            }
            $skip += 100;
        }

        return $out;
    }
    private function items($domain)
    {
        $d = $this->req(
            "GET",
            "/v1/dns/records/" . rawurlencode($domain) . "?take=100&skip=0"
        );

        return isset($d["items"])
            ? $d["items"]
            : (isset($d["records"])
                ? $d["records"]
                : []);
    }
    private function typeValue($r, $type)
    {
        $t = strtoupper($type);
        if ($t === "A" || $t === "AAAA") {
            return isset($r["address"]) ? $r["address"] : "";
        }
        if ($t === "CNAME") {
            return isset($r["cname"]) ? $r["cname"] : "";
        }
        if ($t === "TXT") {
            return isset($r["value"]) ? $r["value"] : "";
        }
        if ($t === "MX") {
            return (isset($r["exchange"]) ? $r["exchange"] : "") .
                "|" .
                (isset($r["preference"]) ? $r["preference"] : 10);
        }
        if ($t === "NS") {
            return isset($r["nameserver"]) ? $r["nameserver"] : "";
        }

        return "";
    }
    private function rid($r, $domain)
    {
        $t = strtoupper(isset($r["type"]) ? $r["type"] : "");

        return rtrim(
            strtr(
                base64_encode(
                    json_encode([
                        $t,
                        isset($r["name"]) ? $r["name"] : "",
                        $this->typeValue($r, $t),
                    ])
                ),
                "+/",
                "-_"
            ),
            "="
        );
    }
    private function norm($r, $domain)
    {
        $t = strtoupper(isset($r["type"]) ? $r["type"] : "");
        $name = isset($r["name"]) ? $r["name"] : "@";
        $v = $this->typeValue($r, $t);
        $mx = 0;
        if ($t === "MX" && strpos($v, "|") !== false) {
            $p = explode("|", $v, 2);
            $v = $p[0];
            $mx = (int) $p[1];
        }

        return [
            "id" => $this->rid($r, $domain),
            "name" =>
                $name === ""
                    ? "@"
                    : $this->toRelative($name . "." . $domain, $domain),
            "type" => $t,
            "value" => $this->cleanValue($v, $t),
            "line" => "default",
            "ttl" => isset($r["ttl"]) ? (int) $r["ttl"] : 600,
            "mx" => $mx,
            "status" =>
                isset($r["disabled"]) && $r["disabled"] ? "disable" : "enable",
        ];
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $z = $domainId ?: $domain;
        $out = [];
        foreach ((array) $this->items($z) as $r) {
            if (is_array($r) && isset($r["type"])) {
                $out[] = $this->norm($r, $z);
            }
        }

        return $this->filterSub($out, $subName);
    }
    private function item($domain, $name, $type, $value, $ttl, $mx)
    {
        $t = strtoupper($type);
        $n = $name === "" ? "@" : $name;
        $x = ["type" => $t, "name" => $n, "ttl" => (int) $ttl];
        if ($t === "A" || $t === "AAAA") {
            $x["address"] = $value;
        } elseif ($t === "CNAME") {
            $x["cname"] = rtrim($value, ".") . ".";
        } elseif ($t === "TXT") {
            $x["value"] =
                strlen($value) >= 2 && $value[0] === '"'
                    ? $value
                    : '"' . str_replace('"', '\\"', $value) . '"';
        } elseif ($t === "MX") {
            $x["exchange"] = rtrim($value, ".") . ".";
            $x["preference"] = (int) $mx;
        } elseif ($t === "NS") {
            $x["nameserver"] = rtrim($value, ".") . ".";
        } else {
            $x["value"] = $value;
        }

        return $x;
    }
    private function put($domain, $items)
    {
        $this->req("PUT", "/v1/dns/records/" . rawurlencode($domain), [
            "force" => true,
            "items" => $items,
        ]);

        return true;
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
        $z = $domainId ?: $domain;
        $this->put($z, [$this->item($z, $name, $type, $value, $ttl, $mx)]);

        return $this->rid($this->item($z, $name, $type, $value, $ttl, $mx), $z);
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
        $z = $domainId ?: $domain;
        $old = null;
        foreach ((array) $this->items($z) as $r) {
            if (is_array($r) && $this->rid($r, $z) === $recordId) {
                $old = $r;
                break;
            }
        }
        if ($old === null) {
            $this->fail("Spaceship 记录不存在");
        }
        $this->deleteRecord($domain, $z, $recordId);
        $this->addRecord($domain, $z, $name, $type, $value, $line, $ttl, $mx);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $z = $domainId ?: $domain;
        $old = null;
        foreach ((array) $this->items($z) as $r) {
            if (is_array($r) && $this->rid($r, $z) === $recordId) {
                $old = $r;
                break;
            }
        }
        if ($old === null) {
            $this->fail("Spaceship 记录不存在");
        }
        $this->req("DELETE", "/v1/dns/records/" . rawurlencode($z), [$old]);

        return true;
    }
}
