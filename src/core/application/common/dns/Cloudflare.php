<?php

namespace app\common\dns;

/**
 * Cloudflare DNS（API v4）
 * 鉴权：API Token（推荐，Authorization: Bearer）或 Global API Key + 账户邮箱（X-Auth-Email / X-Auth-Key）
 * 支持 A/AAAA/CNAME/TXT/MX/NS/CAA/SRV，A/AAAA/CNAME 可开启代理（橙色云朵）
 */
class Cloudflare extends DnsDriver
{
    protected $official = "https://api.cloudflare.com/client/v4";

    /** 可开启代理的记录类型 */
    protected static $proxyTypes = ["A", "AAAA", "CNAME"];

    public function supportsProxy()
    {
        return true;
    }

    protected function url($path, array $q = [])
    {
        $u = $this->baseUrl($this->official) . $path;

        return $q ? $u . "?" . http_build_query($q) : $u;
    }

    protected function headers()
    {
        $h = [
            "Accept" => "application/json",
            "Content-Type" => "application/json",
        ];
        if ($this->cfg("token") !== "") {
            $h["Authorization"] = "Bearer " . $this->cfg("token");
        } else {
            $h["X-Auth-Email"] = $this->cfg("email");
            $h["X-Auth-Key"] = $this->cfg("key");
        }

        return $h;
    }

    protected function call($m, $p, $q = [], $b = null)
    {
        $r = $this->send(
            $m,
            $this->url($p, $q),
            $this->headers(),
            $b === null ? null : json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $d = $this->decode($r);
        if (!$d || empty($d["success"])) {
            $status = isset($r["status"]) ? (int) $r["status"] : 0;
            $code = "";
            $msgs = [];
            if ($d && !empty($d["errors"]) && is_array($d["errors"])) {
                foreach ($d["errors"] as $e) {
                    if ($code === "" && isset($e["code"])) {
                        $code = (string) $e["code"];
                    }
                    $msg = isset($e["message"]) ? $e["message"] : "";
                    if (!empty($e["error_chain"]) && is_array($e["error_chain"])) {
                        foreach ($e["error_chain"] as $c) {
                            if (isset($c["message"])) {
                                $msg .= "；" . $c["message"];
                            }
                        }
                    }
                    $msgs[] = $msg;
                }
            }
            if ($code === "") {
                $code = (string) $status;
            }
            $message = "Cloudflare API错误：" . $this->friendly($code, $status, $msgs ? implode(" | ", $msgs) : "接口返回无效响应");
            /* DnsException extends Exception; preserve the vendor numeric code for callers. */
            /* A transport-level 404 is separately meaningful for idempotent delete. */
            $exceptionCode = $status === 404 ? 404 : $code;
            throw new DnsException($code !== "" ? ($message . "（" . $code . "）") : $message, (int) $exceptionCode);
        }

        return $d;
    }

    /** 常见错误码转中文提示 */
    protected function friendly($code, $status, $msg)
    {
        $map = [
            "9103" => "Global API Key 或邮箱不正确",
            "9106" => "缺少鉴权信息",
            "9109" => "API Token 无效或已过期",
            "10000" => "鉴权失败，请检查 Token/Key 及其权限（需要 Zone:Read 与 DNS:Edit）",
            "6003" => "请求头无效（Token/Key 格式错误）",
            "7003" => "Zone 或记录 ID 不存在",
            "81053" => "已存在同名 A/AAAA/CNAME 记录",
            "81057" => "相同的记录已存在",
            "81044" => "记录不存在或已删除",
            "9005" => "内容格式错误",
            "1004" => "DNS 校验失败",
        ];
        if ($status === 429 || $code === "971") {
            return "请求过于频繁，已被 Cloudflare 限流，请稍后重试";
        }
        if (isset($map[$code])) {
            return $map[$code] . "（" . $msg . "）";
        }

        return $msg;
    }

    public static function info()
    {
        return [
            "code" => "cloudflare",
            "name" => "Cloudflare",
            "fields" => [
                [
                    "key" => "token",
                    "label" => "API Token",
                    "type" => "password",
                    "required" => false,
                    "secret" => true,
                    "help" => "推荐。My Profile → API Tokens → 创建令牌，权限 Zone:Read + DNS:Edit；与“邮箱 + Global API Key”二选一",
                ],
                [
                    "key" => "email",
                    "label" => "账户邮箱",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "使用 Global API Key 时填写",
                ],
                [
                    "key" => "key",
                    "label" => "Global API Key",
                    "type" => "password",
                    "required" => false,
                    "secret" => true,
                    "help" => "My Profile → API Tokens → Global API Key（与邮箱配套）",
                ],
                [
                    "key" => "account_id",
                    "label" => "账户 ID（可选）",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "一个 Token 能访问多个账户时，只列出该账户下的域名",
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
            "proxy" => true,
            "types" => ["A", "AAAA", "CNAME", "TXT", "MX", "NS", "CAA", "SRV"],
            "doc" => "https://developers.cloudflare.com/api/resources/dns/subresources/records/",
        ];
    }

    public function check()
    {
        if ($this->cfg("token") === "") {
            if ($this->cfg("email") === "" || $this->cfg("key") === "") {
                $this->fail("Cloudflare 凭据不完整：请填写 API Token，或填写账户邮箱 + Global API Key");
            }
            $this->call("GET", "/user");
        } else {
            $d = $this->call("GET", "/user/tokens/verify");
            if (isset($d["result"]["status"]) && $d["result"]["status"] !== "active") {
                $this->fail("API Token 状态为 " . $d["result"]["status"] . "，不可用");
            }
        }

        return true;
    }

    public function getDomains($keyword = "")
    {
        $out = [];
        $page = 1;
        do {
            $q = ["page" => $page, "per_page" => 50];
            if ($keyword !== "") {
                $q["name"] = "contains:" . $keyword;
            }
            if ($this->cfg("account_id") !== "") {
                $q["account.id"] = $this->cfg("account_id");
            }
            $d = $this->call("GET", "/zones", $q);
            foreach ((array) $d["result"] as $z) {
                $out[] = [
                    "id" => (string) $z["id"],
                    "domain" => strtolower($z["name"]),
                    "record_count" => null,
                    "status" => isset($z["status"]) ? $z["status"] : "",
                ];
            }
            $ri = isset($d["result_info"]) ? $d["result_info"] : [];
            $pages = isset($ri["total_pages"]) ? (int) $ri["total_pages"] : $page;
            $page++;
        } while ($page <= $pages && $page <= 50);

        return $out;
    }

    public function getRecords($domain, $domainId, $subName = null)
    {
        $out = [];
        $page = 1;
        do {
            $d = $this->call(
                "GET",
                "/zones/" . rawurlencode($domainId) . "/dns_records",
                ["page" => $page, "per_page" => 100]
            );
            foreach ((array) $d["result"] as $x) {
                $type = strtoupper($x["type"]);
                $value = isset($x["content"]) ? $x["content"] : "";
                if ($type === "SRV" && isset($x["data"]) && is_array($x["data"])) {
                    $value = (int) $x["data"]["weight"] . " " . (int) $x["data"]["priority"] . " " . (int) $x["data"]["port"] . " " . $x["data"]["target"];
                }
                $out[] = [
                    "id" => (string) $x["id"],
                    "name" => $this->toRelative($x["name"], $domain),
                    "type" => $type,
                    "value" => $this->cleanValue($value, $type),
                    "line" => "default",
                    "ttl" => (int) $x["ttl"],
                    "mx" => isset($x["priority"]) ? (int) $x["priority"] : 0,
                    "proxied" => !empty($x["proxied"]),
                    "status" => "enable",
                ];
            }
            $ri = isset($d["result_info"]) ? $d["result_info"] : [];
            $pages = isset($ri["total_pages"]) ? (int) $ri["total_pages"] : $page;
            $page++;
        } while ($page <= $pages && $page <= 100);

        return $this->filterSub($out, $subName);
    }

    protected function body($domain, $name, $type, $value, $ttl, $mx)
    {
        $type = strtoupper($type);
        $proxied = $this->proxied && in_array($type, self::$proxyTypes, true);
        $ttl = (int) $ttl;
        // Cloudflare：1 = 自动；非企业版最小 60 秒；开启代理时 TTL 固定为自动
        if ($proxied || $ttl <= 1) {
            $ttl = 1;
        } else {
            $ttl = max(60, min(86400, $ttl));
        }
        $b = [
            "type" => $type,
            "name" => $this->toFqdn($name, $domain),
            "ttl" => $ttl,
        ];
        if (in_array($type, self::$proxyTypes, true)) {
            $b["proxied"] = $proxied;
        }
        if ($type === "MX") {
            $b["content"] = rtrim($value, ".");
            $b["priority"] = max(0, min(65535, (int) $mx));
        } elseif ($type === "SRV") {
            $p = preg_split('/\s+/', trim($value));
            $b["data"] = [
                "weight" => isset($p[0]) ? (int) $p[0] : 0,
                "priority" => isset($p[1]) ? (int) $p[1] : 0,
                "port" => isset($p[2]) ? (int) $p[2] : 0,
                "target" => isset($p[3]) ? rtrim($p[3], ".") : "",
            ];
        } elseif ($type === "CAA") {
            if (preg_match('/^(\d+)\s+(\S+)\s+"?([^"]*)"?$/', trim($value), $m)) {
                $b["data"] = ["flags" => (int) $m[1], "tag" => $m[2], "value" => $m[3]];
            } else {
                $b["content"] = $value;
            }
        } else {
            $b["content"] = $value;
        }

        return $b;
    }

    public function addRecord($domain, $domainId, $name, $type, $value, $line = "default", $ttl = 600, $mx = 10)
    {
        $d = $this->call(
            "POST",
            "/zones/" . rawurlencode($domainId) . "/dns_records",
            [],
            $this->body($domain, $name, $type, $value, $ttl, $mx)
        );
        if (empty($d["result"]["id"])) {
            $this->fail("Cloudflare 未返回记录 ID");
        }

        return (string) $d["result"]["id"];
    }

    public function updateRecord($domain, $domainId, $recordId, $name, $type, $value, $line = "default", $ttl = 600, $mx = 10)
    {
        $this->call(
            "PUT",
            "/zones/" . rawurlencode($domainId) . "/dns_records/" . rawurlencode($recordId),
            [],
            $this->body($domain, $name, $type, $value, $ttl, $mx)
        );

        return true;
    }

    public function deleteRecord($domain, $domainId, $recordId)
    {
        try {
            $this->call(
                "DELETE",
                "/zones/" . rawurlencode($domainId) . "/dns_records/" . rawurlencode($recordId)
            );
        } catch (DnsException $e) {
            // 记录已不存在视为删除成功
            if (in_array((string) $e->getCode(), ["81044", "7003", "404"], true) || strpos($e->getMessage(), "记录不存在") !== false) {
                return true;
            }
            throw $e;
        }

        return true;
    }
}
