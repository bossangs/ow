<?php

namespace app\common\upstream;

class ZjmfFinance extends UpstreamDriver
{
    protected $jwt = "";
    public static function info()
    {
        return [
            "code" => "zjmf_finance",
            "name" => "魔方财务（旧版）",
            "fields" => [
                [
                    "key" => "url",
                    "label" => "接口地址",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "旧版魔方财务根地址",
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
                    "key" => "api_password",
                    "label" => "API 密码",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "API 密码",
                ],
                [
                    "key" => "path_prefix",
                    "label" => "接口前缀",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "默认 /api",
                ],
            ],
            "actions" => [
                "on" => "开机",
                "off" => "关机",
                "reboot" => "重启",
                "reinstall" => "重装系统",
                "reset_password" => "重置密码",
            ],
            "doc" => "https://docs.idcsmart.com/",
        ];
    }
    protected function prefix()
    {
        return trim(
            isset($this->config["path_prefix"])
                ? $this->config["path_prefix"]
                : "/api",
            "/"
        );
    }
    protected function call($method, $path, $data = [], $retry = true)
    {
        $h = ["Content-Type" => "application/json"];
        if ($this->jwt) {
            $h["Authorization"] = "Bearer " . $this->jwt;
        }
        $r = $this->jsonRequest(
            $method,
            $this->url(ltrim($path, "/")),
            $data,
            $h
        );
        if ($retry && isset($r["status"]) && (int) $r["status"] === 401) {
            $this->login();

            return $this->call($method, $path, $data, false);
        }

        return $r;
    }
    protected function login()
    {
        $d = $this->jsonRequest(
            "POST",
            $this->url("zjmf_api_login"),
            [
                "username" => isset($this->config["username"])
                    ? $this->config["username"]
                    : "",
                "password" => isset($this->config["api_password"])
                    ? $this->config["api_password"]
                    : "",
            ],
            ["Content-Type" => "application/json"]
        );
        if (isset($d["status"]) && $d["status"] != 200) {
            throw new UpstreamException(
                isset($d["msg"]) ? $d["msg"] : "魔方财务认证失败"
            );
        }
        $this->jwt = isset($d["jwt"])
            ? $d["jwt"]
            : (isset($d["data"]["jwt"])
                ? $d["data"]["jwt"]
                : "");
        if (!$this->jwt) {
            throw new UpstreamException("魔方财务认证失败");
        }
    }
    protected function api($path)
    {
        return $this->prefix() . "/" . ltrim($path, "/");
    }
    protected function data($d)
    {
        return isset($d["data"]) ? $d["data"] : $d;
    }
    public function testConnection()
    {
        $this->login();
        $this->call("GET", $this->api("product/list"));

        return [
            "ok" => true,
            "message" => "魔方财务连接成功",
            "balance" => null,
        ];
    }
    public function getProducts()
    {
        $d = $this->data($this->call("GET", $this->api("product/list")));
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
            "pid" => $p["pid"],
            "billingcycle" => $p["billingcycle"],
            "host" => isset($p["hostname"]) ? $p["hostname"] : "",
            "password" => isset($p["password"]) ? $p["password"] : "",
            "currencyid" => 1,
            "qty" => 1,
            "configoption" => isset($p["options"]) ? $p["options"] : [],
            "customfield" => isset($p["customfield"]) ? $p["customfield"] : [],
        ];
        $this->call("POST", "cart/add_to_shop", $cart);
        $s = $this->data($this->call("POST", "cart/settle", []));
        $sid = isset($s["hostid"][0])
            ? $s["hostid"][0]
            : (isset($s["hostid"])
                ? $s["hostid"]
                : "");
        $invoice = isset($s["invoiceid"]) ? $s["invoiceid"] : 0;
        if ($invoice) {
            $this->call("POST", "apply_credit", [
                "invoiceid" => $invoice,
                "use_credit" => 1,
                "enough" => 0,
            ]);
        }

        return [
            "service_id" => (string) $sid,
            "order_id" => (string) $invoice,
            "status" => "active",
            "raw" => $s,
        ];
    }
    public function getService($id)
    {
        $d = $this->data($this->call("GET", "host/header", ["host_id" => $id]));
        $h = isset($d["host_data"]) ? $d["host_data"] : $d;

        return [
            "status" => $this->status(
                isset($h["domainstatus"]) ? $h["domainstatus"] : ""
            ),
            "ip" => isset($h["dedicatedip"]) ? $h["dedicatedip"] : "",
            "ips" => isset($h["assignedips"]) ? $h["assignedips"] : [],
            "username" => isset($h["username"]) ? $h["username"] : "",
            "password" => isset($h["password"]) ? $h["password"] : "",
            "hostname" => isset($h["domain"]) ? $h["domain"] : "",
            "os" => "",
            "due_date" => isset($h["nextduedate"]) ? $h["nextduedate"] : "",
            "raw" => $d,
        ];
    }
    public function renewService($id, $cycle)
    {
        $d = $this->data(
            $this->call("POST", "host/renew", [
                "billingcycles" => $cycle,
                "hostid" => $id,
            ])
        );
        $invoice = isset($d["invoiceid"]) ? $d["invoiceid"] : 0;
        if ($invoice) {
            $this->call("POST", "apply_credit", [
                "invoiceid" => $invoice,
                "use_credit" => 1,
            ]);
        }

        return true;
    }
    public function suspend($id, $reason = "")
    {
        $this->call("POST", "provision/default", [
            "id" => $id,
            "func" => "suspend",
            "reason" => $reason,
            "is_api" => 1,
        ]);

        return true;
    }
    public function unsuspend($id)
    {
        $this->call("POST", "provision/default", [
            "id" => $id,
            "func" => "unsuspend",
            "is_api" => 1,
        ]);

        return true;
    }
    public function terminate($id)
    {
        $this->call("POST", "host/cancel", [
            "id" => $id,
            "type" => "Immediate",
            "reason" => "代理商删除",
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
        $p = array_merge(
            ["id" => $id, "func" => $map[$action], "is_api" => 1],
            $params
        );
        $this->call("POST", "provision/default", $p);

        return true;
    }
}
