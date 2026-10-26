<?php

namespace app\common\dns;

class West extends DnsDriver
{
    private $official = "https://api.west.cn/api/v2";
    private $lines = [
        "default" => "",
        "telecom" => "LTEL",
        "unicom" => "LCNC",
        "mobile" => "LMOB",
        "edu" => "LEDU",
        "oversea" => "LFOR",
    ];
    public static function info()
    {
        return [
            "code" => "west",
            "name" => "西部数码",
            "fields" => [
                [
                    "key" => "username",
                    "label" => "用户名",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "西部数码代理商/会员账号",
                ],
                [
                    "key" => "api_password",
                    "label" => "API 密码",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" =>
                        "西部数码后台“代理商管理-API接口配置”中的 API 密码",
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
            "doc" => "https://www.west.cn/CustomerCenter/doc/apiv2.html",
        ];
    }
    public function token($time = null)
    {
        $t = $time === null ? (string) $this->now() : (string) $time;
        if (strlen($t) < 13) {
            $t .= "000";
        }

        return md5($this->cfg("username") . $this->cfg("api_password") . $t);
    }
    private function decodeWest($body)
    {
        $s = $body;
        if (
            function_exists("mb_detect_encoding") &&
            function_exists("mb_convert_encoding")
        ) {
            $e = mb_detect_encoding($s, ["UTF-8", "GBK", "GB2312"], true);
            if ($e && $e !== "UTF-8") {
                $s = mb_convert_encoding($s, "UTF-8", $e);
            }
        } elseif (function_exists("iconv")) {
            $x = @iconv("GBK", "UTF-8//IGNORE", $s);
            if ($x !== false) {
                $s = $x;
            }
        }
        $d = json_decode($s, true);

        return is_array($d) ? $d : null;
    }
    private function req($action, $params = [], $method = "POST")
    {
        $time = $this->cfg("_fixed_time")
            ? (string) $this->cfg("_fixed_time")
            : (string) round(microtime(true) * 1000);
        if (strlen($time) < 13) {
            $time .= "000";
        }
        $params["username"] = $this->cfg("username");
        $params["time"] = $time;
        $params["token"] = $this->token($time);
        $path = "/domain/?act=" . $action;
        $url = $this->baseUrl($this->official) . $path;
        $body = http_build_query($params, "", "&");
        $h = [
            "Content-Type" => "application/x-www-form-urlencoded; charset=GBK",
        ];
        $r = $this->send($method, $url, $h, $body);
        $d = $this->decodeWest($r["body"]);
        if (!is_array($d)) {
            $this->fail("西部数码返回格式错误");
        }
        if (
            $r["status"] < 200 ||
            $r["status"] >= 300 ||
            (!isset($d["result"]) || (string) $d["result"] !== "200")
        ) {
            $this->fail(
                "西部数码 DNS 请求失败",
                isset($d["errcode"])
                    ? $d["errcode"]
                    : (isset($d["result"])
                        ? $d["result"]
                        : (string) $r["status"])
            );
        }

        return $d;
    }
    public function check()
    {
        $this->req("getdomains", []);

        return true;
    }
    private function rows($d)
    {
        if (isset($d["data"]) && is_array($d["data"])) {
            return $d["data"];
        }

        return [];
    }
    public function getDomains($keyword = "")
    {
        $d = $this->req("getdomains");
        $o = [];
        foreach ($this->rows($d) as $z) {
            $n = rtrim(
                isset($z["domain"])
                    ? $z["domain"]
                    : (isset($z["name"])
                        ? $z["name"]
                        : ""),
                "."
            );
            if ($keyword !== "" && stripos($n, $keyword) === false) {
                continue;
            }
            $o[] = ["id" => $n, "domain" => $n, "record_count" => null];
        }

        return $o;
    }
    public function getRecords($domain, $domainId, $subName = null)
    {
        $p = ["domain" => $domain];
        if ($subName !== null && $subName !== "") {
            $p["host"] = $subName;
        }
        $d = $this->req("getdnsrecord", $p);
        $o = [];
        foreach ($this->rows($d) as $r) {
            $type = strtoupper(isset($r["type"]) ? $r["type"] : "A");
            $name = isset($r["host"])
                ? $r["host"]
                : (isset($r["rr"])
                    ? $r["rr"]
                    : "@");
            if ($name === "" || $name === "@") {
                $name = "@";
            }
            $v = isset($r["value"]) ? $r["value"] : "";
            $o[] = [
                "id" => (string) (isset($r["id"])
                    ? $r["id"]
                    : (isset($r["recordid"])
                        ? $r["recordid"]
                        : "")),
                "name" => $name,
                "type" => $type,
                "value" => $this->cleanValue($v, $type),
                "line" => $this->lineFrom(
                    isset($r["line"]) ? $r["line"] : "",
                    $this->lines
                ),
                "ttl" => isset($r["ttl"]) ? (int) $r["ttl"] : 600,
                "mx" =>
                    $type === "MX" && isset($r["level"])
                        ? (int) $r["level"]
                        : 10,
                "status" => "enable",
            ];
        }

        return $this->filterSub($o, $subName);
    }
    private function params($domain, $name, $type, $value, $line, $ttl, $mx)
    {
        return [
            "domain" => $domain,
            "host" => $name === "@" ? "" : $name,
            "type" => strtoupper($type),
            "value" =>
                $type === "TXT"
                    ? '"' . str_replace('"', '\\"', $value) . '"'
                    : $value,
            "ttl" => (int) $ttl,
            "line" => $this->lineTo($line, $this->lines),
            "level" => (int) $mx,
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
        $d = $this->req(
            "adddnsrecord",
            $this->params($domain, $name, $type, $value, $line, $ttl, $mx)
        );
        $x = $this->rows($d);
        if (isset($d["data"]["id"])) {
            return (string) $d["data"]["id"];
        }
        if (isset($d["id"])) {
            return (string) $d["id"];
        }
        if (isset($x[0]["id"])) {
            return (string) $x[0]["id"];
        }

        return "";
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
        $p = $this->params($domain, $name, $type, $value, $line, $ttl, $mx);
        $p["id"] = $recordId;
        $p["recordid"] = $recordId;
        $this->req("moddnsrecord", $p);

        return true;
    }
    public function deleteRecord($domain, $domainId, $recordId)
    {
        $this->req("deldnsrecord", [
            "domain" => $domain,
            "id" => $recordId,
            "recordid" => $recordId,
        ]);

        return true;
    }
    public function getLines()
    {
        return $this->labelsFor($this->lines);
    }
}
