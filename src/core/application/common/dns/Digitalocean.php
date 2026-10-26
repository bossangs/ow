<?php

namespace app\common\dns;

class Digitalocean extends DnsDriver
{
    public static function info()
    {
        return [
            "code" => "digitalocean",
            "name" => "DigitalOcean DNS",
            "fields" => [
                [
                    "key" => "token",
                    "label" => "API Token",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" =>
                        "在 DigitalOcean API 页面创建 Personal Access Token",
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
            "doc" =>
                "https://docs.digitalocean.com/reference/api/api-reference/",
        ];
    }
    private function base()
    {
        return $this->baseUrl("https://api.digitalocean.com/v2");
    }
    private function req($method, $path, $body = null)
    {
        $h = [
            "Authorization" => "Bearer " . (string) $this->cfg("token"),
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
            $code =
                is_array($d) && isset($d["id"])
                    ? $d["id"]
                    : (string) $r["status"];
            $msg =
                is_array($d) && isset($d["message"])
                    ? $d["message"]
                    : "请求失败";
            $this->fail("DigitalOcean 请求失败：" . $msg, $code);
        }

        return $d;
    }
    public function check()
    {
        $this->req("GET", "/account");

        return true;
    }
    public function getDomains($keyword = "")
    {
        $out = [];
        $url = "/domains?per_page=200";
        $n = 0;
        while ($url !== "" && $n++ < 20) {
            $d = $this->req("GET", $url);
            foreach (
                (array) (isset($d["domains"]) ? $d["domains"] : []) as $z
            ) {
                if (!isset($z["name"])) {
                    continue;
                }
                if (
                    $keyword !== "" &&
                    stripos($z["name"], $keyword) === false
                ) {
                    continue;
                }
                $out[] = [
                    "id" => $z["name"],
                    "domain" => $z["name"],
                    "record_count" => null,
                ];
            }
            $url = isset($d["links"]["pages"]["next"])
                ? $d["links"]["pages"]["next"]
                : "";
            if ($url !== "" && strpos($url, "http") === 0) {
                $p = parse_url($url);
                $url =
                    (isset($p["path"]) ? $p["path"] : "") .
                    (isset($p["query"]) ? "?" . $p["query"] : "");
                if (strpos($url, "/v2") === 0) {
                    $url = substr($url, 3);
                }
            }
        }

        return $out;
    }
    private function name($n, $domain)
    {
        return $n === "@" || $n === ""
            ? "@"
            : (strpos($n, ".") === false
                ? $n
                : rtrim($n, "."));
    }
    private function records($domain)
    {
        $out = [];
        $url = "/domains/" . rawurlencode($domain) . "/records?per_page=200";
        $n = 0;
        while ($url !== "" && $n++ < 20) {
            $d = $this->req("GET", $url);
            foreach (
                (array) (isset($d["domain_records"])
                    ? $d["domain_records"]
                    : []) as $r
            ) {
                $out[] = $r;
            }
            $url = isset($d["links"]["pages"]["next"])
                ? $d["links"]["pages"]["next"]
                : "";
            if ($url !== "" && strpos($url, "http") === 0) {
                $p = parse_url($url);
                $url =
                    (isset($p["path"]) ? $p["path"] : "") .
                    (isset($p["query"]) ? "?" . $p["query"] : "");
                if (strpos($url, "/v2") === 0) {
                    $url = substr($url, 3);
                }
            }
        }

        return $out;
    }
    private function norm($r, $domain)
    {
        $type = strtoupper($r["type"]);
        $name =
            $r["name"] === "@" || $r["name"] === ""
                ? "@"
                : $this->toRelative($r["name"] . "." . $domain, $domain);
        $v = $this->cleanValue(isset($r["data"]) ? $r["data"] : "", $type);

        return [
            "id" => (string) $r["id"],
            "name" => $name,
            "type" => $type,
            "value" => $v,
            "line" => "default",
            "ttl" => isset($r["ttl"]) ? (int) $r["ttl"] : 600,
            "mx" => isset($r["priority"]) ? (int) $r["priority"] : 0,
            "status" => "enable",
        ];
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $out = [];
        foreach ($this->records($domainId ?: $domain) as $r) {
            if (!is_array($r) || !isset($r["id"])) {
                continue;
            }
            $out[] = $this->norm($r, $domainId ?: $domain);
        }

        return $this->filterSub($out, $subName);
    }
    private function payload($domain, $name, $type, $value, $ttl, $mx)
    {
        $t = strtoupper($type);
        $v = (string) $value;
        if (in_array($t, ["CNAME", "MX", "NS"], true)) {
            $v = rtrim($v, ".") . ".";
        }
        $x = [
            "type" => $t,
            "name" => $this->name($name, $domain),
            "data" => $v,
            "ttl" => (int) $ttl,
        ];
        if ($t === "MX") {
            $x["priority"] = (int) $mx;
        }

        return $x;
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
        $d = $this->req(
            "POST",
            "/domains/" . rawurlencode($z) . "/records",
            $this->payload($z, $name, $type, $value, $ttl, $mx)
        );

        return isset($d["domain_record"]["id"])
            ? (string) $d["domain_record"]["id"]
            : "";
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
        $this->req(
            "PUT",
            "/domains/" .
                rawurlencode($z) .
                "/records/" .
                rawurlencode($recordId),
            $this->payload($z, $name, $type, $value, $ttl, $mx)
        );

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $z = $domainId ?: $domain;
        $this->req(
            "DELETE",
            "/domains/" .
                rawurlencode($z) .
                "/records/" .
                rawurlencode($recordId)
        );

        return true;
    }
}
