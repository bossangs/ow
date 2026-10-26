<?php

namespace app\common\upstream;

class Whmcs extends UpstreamDriver
{
    public static function info()
    {
        return [
            "code" => "whmcs",
            "name" => "WHMCS",
            "fields" => [
                [
                    "key" => "url",
                    "label" => "接口地址",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "WHMCS 根地址",
                ],
                [
                    "key" => "identifier",
                    "label" => "API Identifier",
                    "type" => "text",
                    "required" => true,
                    "secret" => false,
                    "help" => "API 凭据",
                ],
                [
                    "key" => "secret",
                    "label" => "API Secret",
                    "type" => "password",
                    "required" => true,
                    "secret" => true,
                    "help" => "API 凭据",
                ],
                [
                    "key" => "accesskey",
                    "label" => "Access Key",
                    "type" => "password",
                    "required" => false,
                    "secret" => true,
                    "help" => "可选访问密钥",
                ],
                [
                    "key" => "client_id",
                    "label" => "上游客户 ID",
                    "type" => "number",
                    "required" => false,
                    "secret" => false,
                    "help" => "统一客户 ID",
                ],
                [
                    "key" => "auto_create_client",
                    "label" => "自动创建客户",
                    "type" => "select",
                    "required" => false,
                    "secret" => false,
                    "options" => ["yes" => "是", "no" => "否"],
                    "help" => "按邮箱查找或创建",
                ],
                [
                    "key" => "payment_method",
                    "label" => "支付方式",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "例如 mailin",
                ],
                [
                    "key" => "currency",
                    "label" => "价格币种",
                    "type" => "text",
                    "required" => false,
                    "secret" => false,
                    "help" => "导入上游商品时读取的币种代码，如 CNY、USD；留空取第一个币种",
                ],
                [
                    "key" => "renew_mode",
                    "label" => "续费模式",
                    "type" => "select",
                    "required" => false,
                    "secret" => false,
                    "options" => [
                        "extend_duedate" => "直接延长",
                        "invoice" => "账单支付",
                    ],
                    "help" => "续费策略",
                ],
                [
                    "key" => "custom_commands",
                    "label" => "自定义命令",
                    "type" => "textarea",
                    "required" => false,
                    "secret" => false,
                    "help" => "on=boot,off=shutdown,reboot=reboot",
                ],
            ],
            "actions" => [
                "on" => "开机",
                "off" => "关机",
                "reboot" => "重启",
                "reinstall" => "重装系统",
                "reset_password" => "重置密码",
            ],
            "doc" => "https://developers.whmcs.com/api/",
        ];
    }
    protected function api($action, $p = [])
    {
        $p["identifier"] = isset($this->config["identifier"])
            ? $this->config["identifier"]
            : "";
        $p["secret"] = isset($this->config["secret"])
            ? $this->config["secret"]
            : "";
        $p["action"] = $action;
        $p["responsetype"] = "json";
        if (!empty($this->config["accesskey"])) {
            $p["accesskey"] = $this->config["accesskey"];
        }
        $r = \app\common\lib\HttpClient::postForm(
            $this->url("/includes/api.php"),
            $p,
            [],
            $this->timeout
        );
        if ($r["error"]) {
            throw new UpstreamException("WHMCS 连接失败");
        }
        $d = \app\common\lib\HttpClient::json($r);
        if (!is_array($d)) {
            throw new UpstreamException("WHMCS 返回格式错误");
        }
        if (isset($d["result"]) && $d["result"] !== "success") {
            throw new UpstreamException($this->safeError($d, "WHMCS 请求失败"));
        }

        return $d;
    }
    public function testConnection()
    {
        $d = $this->api("GetProducts", ["limitnum" => 1]);

        return ["ok" => true, "message" => "WHMCS 连接成功", "balance" => null];
    }
    public function getProducts()
    {
        $d = $this->api("GetProducts");
        $out = [];
        $a = isset($d["products"]["product"]) ? $d["products"]["product"] : [];
        foreach ($a as $x) {
            $x["pid"] = isset($x["pid"]) ? $x["pid"] : (isset($x["id"]) ? $x["id"] : "");
            // WHMCS 价格按币种分组：优先使用配置的币种，否则取第一个币种
            $pr = isset($x["pricing"]) && is_array($x["pricing"]) ? $x["pricing"] : [];
            $cur = isset($this->config["currency"]) ? strtoupper($this->config["currency"]) : "";
            if ($cur !== "" && isset($pr[$cur])) {
                $x["pricing"] = $pr[$cur];
            } elseif ($pr && !isset($pr["monthly"])) {
                $first = reset($pr);
                $x["pricing"] = is_array($first) ? $first : [];
            } else {
                $x["pricing"] = $pr;
            }
            $out[] = $this->product($x);
        }

        return $out;
    }
    protected function client($c)
    {
        if (!empty($this->config["client_id"])) {
            return (int) $this->config["client_id"];
        }
        $email = isset($c["email"]) ? $c["email"] : "";
        $d = $this->api("GetClients", ["search" => $email]);
        if (!empty($d["clients"]["client"][0])) {
            return (int) $d["clients"]["client"][0]["id"];
        }
        if (
            isset($this->config["auto_create_client"]) &&
            $this->config["auto_create_client"] === "no"
        ) {
            throw new UpstreamException("未找到上游客户");
        }
        $n = $this->api("AddClient", [
            "email" => $email,
            "firstname" => isset($c["firstname"])
                ? $c["firstname"]
                : "Customer",
            "lastname" => isset($c["lastname"]) ? $c["lastname"] : "User",
            "password2" => isset($c["password"])
                ? $c["password"]
                : bin2hex(random_bytes(8)),
            "skipvalidation" => true,
            "noemail" => true,
        ]);

        return (int) $n["clientid"];
    }
    public function createService(array $p)
    {
        $cid = $this->client(isset($p["client"]) ? $p["client"] : []);
        $q = [
            "clientid" => $cid,
            "pid" => $p["pid"],
            "billingcycle" => $p["billingcycle"],
            "hostname" => isset($p["hostname"]) ? $p["hostname"] : "",
            "rootpw" => isset($p["password"]) ? $p["password"] : "",
            "paymentmethod" => isset($this->config["payment_method"])
                ? $this->config["payment_method"]
                : "mailin",
            "noinvoice" => true,
        ];
        if (!empty($p["options"])) {
            foreach ($p["options"] as $k => $v) {
                $q[$k] = $v;
            };
        }
        $d = $this->api("AddOrder", $q);
        $oid = isset($d["orderid"]) ? $d["orderid"] : "";
        $sid =
            isset($d["serviceids"]) && is_array($d["serviceids"])
                ? $d["serviceids"][0]
                : "";
        if ($oid) {
            $this->api("AcceptOrder", [
                "orderid" => $oid,
                "autosetup" => true,
                "sendemail" => false,
            ]);
        }

        return [
            "service_id" => (string) $sid,
            "order_id" => (string) $oid,
            "status" => "active",
            "raw" => $d,
        ];
    }
    public function getService($id)
    {
        $d = $this->api("GetClientsProducts", ["serviceid" => $id]);
        $x = isset($d["products"]["product"][0])
            ? $d["products"]["product"][0]
            : [];

        return [
            "status" => $this->status(isset($x["status"]) ? $x["status"] : ""),
            "ip" => isset($x["dedicatedip"]) ? $x["dedicatedip"] : "",
            "ips" => isset($x["assignedips"])
                ? preg_split("/[ ,]+/", $x["assignedips"])
                : [],
            "username" => isset($x["username"]) ? $x["username"] : "",
            "password" => isset($x["password"]) ? $x["password"] : "",
            "hostname" => isset($x["domain"]) ? $x["domain"] : "",
            "os" => isset($x["configoptions"]) ? $x["configoptions"] : "",
            "due_date" => isset($x["nextduedate"]) ? $x["nextduedate"] : "",
            "raw" => $d,
        ];
    }
    public function renewService($id, $cycle)
    {
        $months = ["monthly" => 1, "quarterly" => 3, "semiannually" => 6, "annually" => 12, "biennially" => 24, "triennially" => 36];
        $m = isset($months[$cycle]) ? $months[$cycle] : 1;
        $d = $this->api("GetClientsProducts", ["serviceid" => $id]);
        $x = isset($d["products"]["product"][0]) ? $d["products"]["product"][0] : [];
        if (
            isset($this->config["renew_mode"]) &&
            $this->config["renew_mode"] === "invoice"
        ) {
            // 生成该服务的续费账单，并使用上游账户余额(Credit)支付
            $uid = isset($x["clientid"]) ? $x["clientid"] : (isset($this->config["client_id"]) ? $this->config["client_id"] : "");
            $this->api("GenInvoices", ["serviceids" => $id, "noemails" => true]);
            $inv = $this->api("GetInvoices", ["userid" => $uid, "status" => "Unpaid", "limitnum" => 25, "orderby" => "id", "order" => "desc"]);
            $list = isset($inv["invoices"]["invoice"]) ? $inv["invoices"]["invoice"] : [];
            if (!$list) {
                throw new UpstreamException("上游未生成续费账单（可能尚未到账单生成期）");
            }
            $invoice = $list[0];
            $bal = isset($invoice["balance"]) ? $invoice["balance"] : (isset($invoice["total"]) ? $invoice["total"] : 0);
            $this->api("ApplyCredit", ["invoiceid" => $invoice["id"], "amount" => $bal, "noemail" => true]);

            return true;
        }
        // 默认：直接顺延上游到期日（需管理员 API 权限）
        $cur = isset($x["nextduedate"]) && $x["nextduedate"] !== "0000-00-00" ? strtotime($x["nextduedate"]) : false;
        $base = $cur && $cur > time() ? $cur : time();
        $names = ["monthly" => "Monthly", "quarterly" => "Quarterly", "semiannually" => "Semi-Annually", "annually" => "Annually", "biennially" => "Biennially", "triennially" => "Triennially"];
        $this->api("UpdateClientProduct", [
            "serviceid" => $id,
            "billingcycle" => isset($names[$cycle]) ? $names[$cycle] : "Monthly",
            "nextduedate" => date("Y-m-d", strtotime("+" . $m . " month", $base)),
        ]);

        return true;
    }

    /**
     * 升级/降级产品（WHMCS UpgradeProduct）
     */
    public function upgrade($id, $newPid, $cycle)
    {
        $this->api("UpgradeProduct", [
            "serviceid" => $id,
            "paymentmethod" => !empty($this->config["payment_method"]) ? $this->config["payment_method"] : "mailin",
            "type" => "product",
            "newproductid" => $newPid,
            "newproductbillingcycle" => $cycle,
        ]);

        return true;
    }
    protected function mod($a, $id, $p = [])
    {
        $p["serviceid"] = $id;
        $this->api($a, $p);

        return true;
    }
    public function suspend($id, $reason = "")
    {
        return $this->mod("ModuleSuspend", $id, ["suspendreason" => $reason]);
    }
    public function unsuspend($id)
    {
        return $this->mod("ModuleUnsuspend", $id);
    }
    public function terminate($id)
    {
        return $this->mod("ModuleTerminate", $id);
    }
    public function action($id, $action, array $params = [])
    {
        if ($action === "reset_password") {
            return $this->mod("ModuleChangePw", $id, [
                "servicepassword" => isset($params["password"])
                    ? $params["password"]
                    : "",
            ]);
        }
        $map = [];
        $s = isset($this->config["custom_commands"])
            ? $this->config["custom_commands"]
            : "";
        foreach (explode(",", $s) as $pair) {
            $v = explode("=", $pair, 2);
            if (count($v) == 2) {
                $map[trim($v[0])] = trim($v[1]);
            }
        }
        $fn = isset($map[$action]) ? $map[$action] : $action;

        return $this->mod("ModuleCustom", $id, [
            "func_name" => $fn,
            "params" => isset($params["params"]) ? $params["params"] : "",
        ]);
    }
}
