<?php

namespace app\common\upstream;

class Zjmf extends UpstreamDriver
{
    protected $jwt = "";
    public static function info()
    {
        return [
            "code" => "zjmf",
            "name" => "智简魔方 V10 业务系统",
            "fields" => [
                [
                    "key" => "url",
                    "label" => "接口地址",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "V10 根地址",
                ],
                [
                    "key" => "username",
                    "label" => "用户名",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "API 用户名",
                ],
                [
                    "key" => "api_token",
                    "label" => "API Token",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "登录 API token",
                ],
                [
                    "key" => "path_prefix",
                    "label" => "接口前缀",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "默认 /api/v1",
                ],
            ],
            "actions" => [
                "on" => "开机",
                "off" => "关机",
                "reboot" => "重启",
                "reinstall" => "重装系统",
                "reset_password" => "重置密码",
            ],
            "doc" =>
                "https://docs.idcsmart.com/docs/V10%E4%B8%9A%E5%8A%A1%E7%B3%BB%E7%BB%9FAPI",
        ];
    }
    protected function prefix()
    {
        return trim(
            isset($this->config["path_prefix"])
                ? $this->config["path_prefix"]
                : "/api/v1",
            "/"
        );
    }
    protected function call($method, $path, $data = [], $retry = true)
    {
        $h = ["Content-Type" => "application/json"];
        if ($this->jwt) {
            $h["Authorization"] = "Bearer " . $this->jwt;
        }
        $r = $this->jsonRequest($method, $this->url($path), $data, $h);
        if ($retry && isset($r["code"]) && (int) $r["code"] === 401) {
            $this->login();

            return $this->call($method, $path, $data, false);
        }

        return $r;
    }
    protected function login()
    {
        $d = $this->jsonRequest(
            "POST",
            $this->url("api/v1/auth"),
            [
                "username" => isset($this->config["username"])
                    ? $this->config["username"]
                    : "",
                "password" => isset($this->config["api_token"])
                    ? $this->config["api_token"]
                    : "",
            ],
            ["Content-Type" => "application/json"]
        );
        $this->jwt = isset($d["data"]["jwt"])
            ? $d["data"]["jwt"]
            : (isset($d["jwt"])
                ? $d["jwt"]
                : "");
        if (!$this->jwt) {
            throw new UpstreamException("智简魔方认证失败");
        }
    }
    protected function data($d)
    {
        return isset($d["data"]) ? $d["data"] : $d;
    }
    protected function api($path)
    {
        return $this->prefix() . "/" . ltrim($path, "/");
    }
    protected function console($path)
    {
        return "console/v1/" . ltrim($path, "/");
    }
    public function testConnection()
    {
        $this->login();
        $this->call("GET", $this->api("product"));

        return [
            "ok" => true,
            "message" => "智简魔方连接成功",
            "balance" => null,
        ];
    }
    public function getProducts()
    {
        $d = $this->data($this->call("GET", $this->api("product")));
        $a = isset($d["list"]) ? $d["list"] : $d;
        if (isset($a["data"])) {
            $a = $a["data"];
        }
        $o = [];
        foreach ($a as $x) {
            $o[] = $this->product($x);
        }

        return $o;
    }
    public function createService(array $p)
    {
        $cart = [
            "product_id" => $p["pid"],
            "qty" => 1,
            "config_options" => isset($p["options"]) ? $p["options"] : [],
            "self_defined_field" => isset($p["customfield"])
                ? $p["customfield"]
                : [],
        ];
        $d = $this->data($this->call("POST", $this->console("cart"), $cart));
        $settle = ["positions" => [0]];
        if (isset($p["downstream_url"])) {
            $settle["downstream_url"] = $p["downstream_url"];
        }
        if (isset($p["downstream_token"])) {
            $settle["downstream_token"] = $p["downstream_token"];
        }
        if (isset($p["downstream_client_id"])) {
            $settle["downstream_client_id"] = $p["downstream_client_id"];
        }
        $s = $this->data(
            $this->call("POST", $this->console("cart/settle"), $settle)
        );
        $oid = isset($s["order_id"])
            ? $s["order_id"]
            : (isset($d["order_id"])
                ? $d["order_id"]
                : "");
        if ($oid !== "") {
            $this->call("POST", $this->console("pay"), [
                "id" => $oid,
                "gateway" => "credit",
            ]);
        }
        $sid = isset($s["host_ids"][0])
            ? $s["host_ids"][0]
            : (isset($d["host_id"])
                ? $d["host_id"]
                : $oid);

        return [
            "service_id" => (string) $sid,
            "order_id" => (string) $oid,
            "status" => "active",
            "raw" => $s,
        ];
    }
    public function getService($id)
    {
        $ip = $this->data(
            $this->call("GET", $this->console("host/" . $id . "/ip"))
        );
        $d = $this->data($this->call("GET", $this->console("host/" . $id)));
        $h = isset($d["host"]) ? $d["host"] : $d;

        return [
            "status" => $this->status(isset($h["status"]) ? $h["status"] : ""),
            "ip" => isset($ip["dedicate_ip"])
                ? $ip["dedicate_ip"]
                : (isset($h["ip"])
                    ? $h["ip"]
                    : ""),
            "ips" => isset($ip["assign_ip"]) ? $ip["assign_ip"] : [],
            "username" => isset($h["username"]) ? $h["username"] : "",
            "password" => isset($h["password"]) ? $h["password"] : "",
            "hostname" => isset($h["name"])
                ? $h["name"]
                : (isset($h["hostname"])
                    ? $h["hostname"]
                    : ""),
            "os" => isset($h["os"]) ? $h["os"] : "",
            "due_date" => isset($h["due_time"]) ? $h["due_time"] : "",
            "raw" => $d,
        ];
    }
    public function renewService($id, $cycle)
    {
        $d = $this->data(
            $this->call("POST", $this->console("host/" . $id . "/renew"), [
                "billing_cycle" => $cycle,
            ])
        );
        if (
            isset($d["code"]) &&
            $d["code"] === "Unpaid" &&
            isset($d["data"]["id"])
        ) {
            $this->call("POST", $this->console("credit"), [
                "id" => $d["data"]["id"],
                "use" => 1,
            ]);
            $this->call("POST", $this->console("pay"), [
                "id" => $d["data"]["id"],
                "gateway" => "credit",
            ]);
        }

        return true;
    }
    public function suspend($id, $reason = "")
    {
        $this->call("POST", $this->console("host/" . $id . "/module/suspend"), [
            "suspend_type" => "downstream",
            "suspend_reason" => $reason,
        ]);

        return true;
    }
    public function unsuspend($id)
    {
        $this->call(
            "POST",
            $this->console("host/" . $id . "/module/unsuspend")
        );

        return true;
    }
    public function terminate($id)
    {
        $this->call("POST", $this->console("refund"), [
            "host_id" => $id,
            "suspend_reason" => "代理商删除",
            "type" => "Immediate",
        ]);

        return true;
    }
    public function action($id, $action, array $params = [])
    {
        $map = [
            "on" => "on",
            "off" => "off",
            "reboot" => "reboot",
            "reinstall" => "reinstall",
            "reset_password" => "reset_password",
        ];
        if (!isset($map[$action])) {
            throw new UpstreamException("不支持的操作");
        }
        $module = isset($params["module"])
            ? $params["module"]
            : (isset($this->config["module"])
                ? $this->config["module"]
                : "mf_cloud");
        unset($params["module"]);
        $this->call(
            "POST",
            $this->console($module . "/" . $id . "/" . $map[$action]),
            $params
        );

        return true;
    }
}
