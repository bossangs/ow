<?php

namespace app\common\dns;

class Godaddy extends DnsDriver
{
    protected function base()
    {
        return $this->baseUrl(
            $this->cfg("env", "production") === "ote"
                ? "https://api.ote-godaddy.com"
                : "https://api.godaddy.com"
        );
    }
    protected function call($m, $p, $q = [], $b = null)
    {
        $u = $this->base() . $p;
        if ($q) {
            $u .= "?" . http_build_query($q);
        }
        $r = $this->send(
            $m,
            $u,
            [
                "Accept" => "application/json",
                "Content-Type" => "application/json",
                "Authorization" =>
                    "sso-key " . $this->cfg("key") . ":" . $this->cfg("secret"),
            ],
            $b === null ? null : json_encode($b)
        );
        if ($r["status"] < 200 || $r["status"] >= 300) {
            $d = $this->decode($r);
            $msg =
                $d && isset($d["message"]) ? $d["message"] : "接口返回无效响应";
            $this->fail(
                "GoDaddy API错误：" . $msg,
                $d && isset($d["code"]) ? $d["code"] : (string) $r["status"]
            );
        }

        return $this->decode($r);
    }
    public static function info()
    {
        return [
            "code" => "godaddy",
            "name" => "GoDaddy",
            "fields" => [
                [
                    "key" => "key",
                    "label" => "API Key",
                    "type" => "text",
                    "required" => true,
                    "secret" => true,
                    "help" => "GoDaddy Developer Portal 生成的 API Key",
                ],
                [
                    "key" => "secret",
                    "label" => "API Secret",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "GoDaddy Developer Portal 生成的 API Secret",
                ],
                [
                    "key" => "env",
                    "label" => "环境",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "production 或 ote",
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
            "doc" => "https://developer.godaddy.com/doc/endpoint/domains",
        ];
    }
    public function check()
    {
        if ($this->cfg("key") === "" || $this->cfg("secret") === "") {
            $this->fail("GoDaddy 凭据不完整");
        }
        $this->call("GET", "/v1/domains", ["statuses" => "ACTIVE"]);

        return true;
    }
    public function getDomains($keyword = "")
    {
        $a = $this->call("GET", "/v1/domains", ["statuses" => "ACTIVE"]);
        $o = [];
        foreach ((array) $a as $x) {
            $n = $x["domain"];
            if ($keyword === "" || stripos($n, $keyword) !== false) {
                $o[] = ["id" => $n, "domain" => $n, "record_count" => null];
            }
        }

        return $o;
    }
    protected function rid($t, $n, $v)
    {
        return substr(
            hash("sha256", strtoupper($t) . "|" . strtolower($n) . "|" . $v),
            0,
            32
        );
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $a = $this->call(
            "GET",
            "/v1/domains/" . rawurlencode($domain) . "/records"
        );
        $o = [];
        foreach ((array) $a as $x) {
            $t = strtoupper($x["type"]);
            $n = $x["name"] === "" ? "@" : $x["name"];
            $v = $this->cleanValue($x["data"], $t);
            $o[] = [
                "id" => $this->rid($t, $n, $v),
                "name" => $n,
                "type" => $t,
                "value" => $v,
                "line" => "default",
                "ttl" => (int) $x["ttl"],
                "mx" => (int) (isset($x["priority"]) ? $x["priority"] : 0),
                "status" => "enable",
            ];
        }

        return $this->filterSub($o, $subName);
    }
    protected function one($name, $type, $value, $ttl, $mx)
    {
        $x = ["data" => $value, "ttl" => max(600, (int) $ttl)];
        if (strtoupper($type) === "MX") {
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
        $n = $name === "@" ? "" : $name;
        $path = "/v1/domains/" . rawurlencode($domain) . "/records";
        $this->call(
            "PATCH",
            $path,
            [],
            [
                [
                    "type" => strtoupper($type),
                    "name" => $n,
                    "data" => $value,
                    "ttl" => max(600, (int) $ttl),
                ] +
                (strtoupper($type) === "MX" ? ["priority" => (int) $mx] : []),
            ]
        );

        return $this->rid($type, $name, $value);
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
        $n = $name === "@" ? "" : $name;
        $this->call(
            "PUT",
            "/v1/domains/" .
                rawurlencode($domain) .
                "/records/" .
                rawurlencode(strtoupper($type)) .
                "/" .
                rawurlencode($n),
            [],
            [$this->one($name, $type, $value, $ttl, $mx)]
        );

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $rows = $this->getRecords($domain, $domainId);
        $found = null;
        foreach ($rows as $x) {
            if ($x["id"] === $recordId) {
                $found = $x;
            }
        }
        if (!$found) {
            $this->fail("GoDaddy 记录不存在");
        }
        $remain = [];
        foreach ($rows as $x) {
            if (
                $x["id"] !== $recordId &&
                $x["type"] === $found["type"] &&
                $x["name"] === $found["name"]
            ) {
                $remain[] = $x;
            }
        }
        $n = $found["name"] === "@" ? "" : $found["name"];
        $path =
            "/v1/domains/" .
            rawurlencode($domain) .
            "/records/" .
            rawurlencode($found["type"]) .
            "/" .
            rawurlencode($n);
        if ($remain) {
            $b = [];
            foreach ($remain as $x) {
                $b[] = $this->one(
                    $x["name"],
                    $x["type"],
                    $x["value"],
                    $x["ttl"],
                    $x["mx"]
                );
            }
            $this->call("PUT", $path, [], $b);
        } else {
            $this->call("DELETE", $path);
        }

        return true;
    }
}
