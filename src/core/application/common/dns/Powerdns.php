<?php

namespace app\common\dns;

class Powerdns extends DnsDriver
{
    public static function info()
    {
        return [
            "code" => "powerdns",
            "name" => "PowerDNS",
            "fields" => [
                [
                    "key" => "api_url",
                    "label" => "API 地址",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" =>
                        "PowerDNS Authoritative HTTP API 的地址，例如 http://127.0.0.1:8081",
                ],
                [
                    "key" => "api_key",
                    "label" => "API Key",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "在 PowerDNS 配置 api-key 中获取",
                ],
                [
                    "key" => "server_id",
                    "label" => "服务器 ID",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "PowerDNS API 的 server-id，默认 localhost",
                ],
                [
                    "key" => "endpoint",
                    "label" => "自定义 API 地址（可选）",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "留空使用 api_url；可填 API 反向代理地址",
                ],
            ],
            "lines" => false,
            "types" => ["A", "AAAA", "CNAME", "TXT", "MX", "NS"],
            "doc" =>
                "https://doc.powerdns.com/authoritative/http-api/index.html",
        ];
    }
    private function base()
    {
        $u = trim((string) $this->cfg("api_url"));
        if ($u === "") {
            $this->fail("PowerDNS API 地址不能为空");
        }

        return $this->baseUrl($u);
    }
    private function headers()
    {
        return [
            "X-API-Key" => (string) $this->cfg("api_key"),
            "Accept" => "application/json",
            "Content-Type" => "application/json",
        ];
    }
    private function request($method, $path, $body = null)
    {
        $r = $this->send(
            $method,
            $this->base() . $path,
            $this->headers(),
            $body === null ? null : json_encode($body)
        );
        $d = $this->decode($r);
        if ($r["status"] < 200 || $r["status"] >= 300) {
            $this->fail(
                "PowerDNS 请求失败",
                is_array($d) && isset($d["error"])
                    ? $d["error"]
                    : (string) $r["status"]
            );
        }

        return $d;
    }
    private function zonePath($zone)
    {
        $sid = rawurlencode((string) $this->cfg("server_id", "localhost"));
        $z = rtrim((string) $zone, ".") . ".";

        return "/api/v1/servers/" . $sid . "/zones/" . rawurlencode($z);
    }
    public function check()
    {
        $this->request(
            "GET",
            "/api/v1/servers/" .
                rawurlencode((string) $this->cfg("server_id", "localhost")) .
                "/zones"
        );

        return true;
    }
    public function getDomains($keyword = "")
    {
        $d = $this->request(
            "GET",
            "/api/v1/servers/" .
                rawurlencode((string) $this->cfg("server_id", "localhost")) .
                "/zones"
        );
        $out = [];
        foreach ((array) $d as $z) {
            if (!is_array($z)) {
                continue;
            }
            $name = rtrim(
                isset($z["name"])
                    ? $z["name"]
                    : (isset($z["id"])
                        ? $z["id"]
                        : ""),
                "."
            );
            if ($keyword !== "" && stripos($name, $keyword) === false) {
                continue;
            }
            $out[] = [
                "id" => $name . ".",
                "domain" => $name,
                "record_count" => isset($z["rrsets"])
                    ? count($z["rrsets"])
                    : null,
            ];
        }

        return $out;
    }
    private function rrsets($domain)
    {
        $d = $this->request("GET", $this->zonePath($domain));

        return isset($d["rrsets"]) && is_array($d["rrsets"])
            ? $d["rrsets"]
            : [];
    }
    private function val($type, $value, $mx = 10)
    {
        $type = strtoupper($type);
        $v = (string) $value;
        if ($type === "TXT") {
            if (!(strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"')) {
                $v = '"' . str_replace('"', '\\"', $v) . '"';
            }
        } elseif ($type === "MX") {
            $v = (int) $mx . " " . rtrim($v, ".") . ".";
        } elseif (in_array($type, ["CNAME", "NS"], true)) {
            $v = rtrim($v, ".") . ".";
        }

        return $v;
    }
    private function rid($type, $name, $content)
    {
        return rtrim(
            strtr(
                base64_encode(
                    json_encode([strtoupper($type), $name, $content])
                ),
                "+/",
                "-_"
            ),
            "="
        );
    }
    private function parse($domain, $sets)
    {
        $out = [];
        foreach ($sets as $s) {
            if (!isset($s["name"], $s["type"])) {
                continue;
            }
            $type = strtoupper($s["type"]);
            $name = $this->toRelative($s["name"], $domain);
            $ttl = isset($s["ttl"]) ? (int) $s["ttl"] : 600;
            foreach (
                (array) (isset($s["records"]) ? $s["records"] : []) as $rec
            ) {
                if (!is_array($rec) || !isset($rec["content"])) {
                    continue;
                }
                $c = (string) $rec["content"];
                $disabled = !empty($rec["disabled"]);
                $mx = 0;
                if (
                    $type === "MX" &&
                    preg_match('/^([0-9]+)\s+(.+)$/', $c, $m)
                ) {
                    $mx = (int) $m[1];
                    $c = $m[2];
                }
                $out[] = [
                    "id" => $this->rid($type, $name, $rec["content"]),
                    "name" => $name,
                    "type" => $type,
                    "value" => $this->cleanValue($c, $type),
                    "line" => "default",
                    "ttl" => $ttl,
                    "mx" => $mx,
                    "status" => $disabled ? "disable" : "enable",
                ];
            }
        }

        return $out;
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        return $this->filterSub(
            $this->parse($domain, $this->rrsets($domainId ?: $domain)),
            $subName
        );
    }
    private function change($domain, $zone, $changes)
    {
        $this->request("PATCH", $this->zonePath($zone), ["rrsets" => $changes]);

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
        $zone = $domainId ?: $domain;
        $fq = $this->toFqdn($name, $domain);
        $t = strtoupper($type);
        $c = $this->val($t, $value, $mx);
        $sets = $this->rrsets($zone);
        $found = false;
        foreach ($sets as &$s) {
            if (
                strtolower(rtrim($s["name"], ".")) === strtolower($fq) &&
                strtoupper($s["type"]) === $t
            ) {
                $s["ttl"] = (int) $ttl;
                $s["records"][] = ["content" => $c, "disabled" => false];
                $found = true;
                break;
            }
        }
        unset($s);
        if (!$found) {
            $sets[] = [
                "name" => rtrim($fq, ".") . ".",
                "type" => $t,
                "ttl" => (int) $ttl,
                "changetype" => "REPLACE",
                "records" => [["content" => $c, "disabled" => false]],
            ];
        }
        $target = [];
        foreach ($sets as $s) {
            if (isset($s["changetype"]) || $s["records"]) {
                $s["changetype"] = "REPLACE";
                $target[] = [
                    "name" => $s["name"],
                    "type" => $s["type"],
                    "ttl" => (int) $s["ttl"],
                    "changetype" => "REPLACE",
                    "records" => $s["records"],
                ];
            }
        }
        $this->change($domain, $zone, $target);

        return $this->rid($t, $name, $c);
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
        $zone = $domainId ?: $domain;
        $all = $this->parse($domain, $this->rrsets($zone));
        $old = null;
        foreach ($all as $r) {
            if ($r["id"] === $recordId) {
                $old = $r;
                break;
            }
        }
        if ($old === null) {
            $this->fail("PowerDNS 记录不存在");
        }
        $sets = $this->rrsets($zone);
        $oldF = $this->toFqdn($old["name"], $domain);
        $oldC = $this->val($old["type"], $old["value"], $old["mx"]);
        $newT = strtoupper($type);
        $newF = $this->toFqdn($name, $domain);
        $newC = $this->val($newT, $value, $mx);
        $changes = [];
        foreach ($sets as $s) {
            $same =
                strtolower(rtrim($s["name"], ".")) === strtolower($oldF) &&
                strtoupper($s["type"]) === strtoupper($old["type"]);
            if ($same) {
                $keep = [];
                foreach ((array) $s["records"] as $x) {
                    if ((string) $x["content"] !== $oldC) {
                        $keep[] = $x;
                    }
                }
                $changes[] = [
                    "name" => $s["name"],
                    "type" => $s["type"],
                    "ttl" => (int) $s["ttl"],
                    "changetype" => count($keep) ? "REPLACE" : "DELETE",
                    "records" => $keep,
                ];
            }
        }
        $changes[] = [
            "name" => rtrim($newF, ".") . ".",
            "type" => $newT,
            "ttl" => (int) $ttl,
            "changetype" => "REPLACE",
            "records" => [["content" => $newC, "disabled" => false]],
        ];
        $this->change($domain, $zone, $changes);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $zone = $domainId ?: $domain;
        $all = $this->parse($domain, $this->rrsets($zone));
        $old = null;
        foreach ($all as $r) {
            if ($r["id"] === $recordId) {
                $old = $r;
                break;
            }
        }
        if ($old === null) {
            $this->fail("PowerDNS 记录不存在");
        }
        $sets = $this->rrsets($zone);
        $changes = [];
        foreach ($sets as $s) {
            if (
                strtolower($this->toRelative($s["name"], $domain)) !==
                    strtolower($old["name"]) ||
                strtoupper($s["type"]) !== strtoupper($old["type"])
            ) {
                continue;
            }
            $c = $this->val($old["type"], $old["value"], $old["mx"]);
            $keep = [];
            foreach ((array) $s["records"] as $x) {
                if ((string) $x["content"] !== $c) {
                    $keep[] = $x;
                }
            }
            $changes[] = [
                "name" => $s["name"],
                "type" => $s["type"],
                "ttl" => (int) $s["ttl"],
                "changetype" => count($keep) ? "REPLACE" : "DELETE",
                "records" => $keep,
            ];
        }
        $this->change($domain, $zone, $changes);

        return true;
    }
}
