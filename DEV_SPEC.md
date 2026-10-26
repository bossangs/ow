# IDCDNS 开发规范（子任务必读）

项目：/workspace/idcdns/src（网站根，ZIP 根目录即这些文件）。ThinkPHP 5.1.42，**必须兼容 PHP 7.0**（不用 ?type、void、typed props、箭头函数、`??=`；`??` 可用）。每个 PHP 文件写完 `php7.0 -l`。
目录：`index.php`（入口）、`install.php`（安装器，待写）、`core/`（框架+应用，需禁止 web 访问）、`static/`（Bootstrap5 本地：static/vendor/bootstrap/bootstrap.min.css、bootstrap.bundle.min.js；static/css/app.css；static/js/app.js）、`data/`（运行数据：config.php、install.lock.php、runtime/，需禁止 web 访问；也可放网站根外，由 core/datapath.php 返回路径）。
数据库表结构：`core/install/schema.sql`（`{p}` 前缀占位、`{charset}`）。Db 用 `think\Db::name('表名不带前缀')`，结果为数组。

## 路由 / URL
- 多模块：index（前台）、admin（后台）、api（回调/计划任务/NAT 节点）。控制器类名首字母大写，文件 `core/application/{module}/controller/Xxx.php`，namespace `app\{module}\controller`。
- 不依赖伪静态：默认 `index.php?s=/模块/控制器/方法&a=b`。**所有链接必须用 `url_to('module/ctrl/action', ['k'=>v])`**，静态资源 `static_url('css/app.css')`。参数获取 `$this->request->param('id')` / `get()` / `post()`。路径参数如 `index/pay/notify/gw/epay` → `param('gw')`。

## 控制器基类（core/application/common/controller/）
- `Base`：`$this->fetch('tpl', $vars, $layout=null)` 渲染 `core/application/{module}/view/{tpl}.php`（原生 PHP 模板，变量 extract；输出一律 `h()` 转义），默认套 `layout.php`（传 `false` 不套）；`$this->assign()`；`$this->flash($msg,'success|danger|warning|info')`；`$this->redirectTo('module/ctrl/act', $params)`；`$this->back()`；`$this->ok($msg,$data)` / `$this->fail($msg)` 返回 JSON {code,msg,data}；`$this->abort($msg,$status)`；`$this->paginate($query,$size)` 返回 [$list,$total,$page,$size]，视图用 `pager_html($total,$page,$size,$query)`。
- **POST 自动校验 CSRF**：表单必须含 `<?= csrf_field() ?>`；AJAX 带 `_token`。不校验的方法写入 `protected $noCsrf = ['notify']`（小写方法名）。
- `FrontBase`：`$this->user`（数组或 null），`protected $needLogin = true` 强制登录；视图变量 `$user,$siteName,$navActive`。用户中心页面渲染时传 `'sidebar'=>true, 'title'=>'...'` 由 `index/view/layout.php` 显示侧栏（已写好，菜单：user/index 概览、host/index、dns/index、order/index、user/balance、ticket/index、user/profile）。登录态 session：`user_id,user_name,user_pwd_sig`（= substr(sha1(password_hash),0,16)）。
- `AdminBase`：`$this->admin`；session `admin_id,admin_name,admin_pwd_sig`。通用 CRUD：子类设 `$table,$title,$searchFields`，实现 `fields()` 返回 `key => ['标签','type'=>text|number|textarea|select|multiselect|switch|password|html,'options'=>[],'list'=>true,'help'=>'','required'=>true,'default'=>..]`；自带 `index()`（视图 `admin/view/common/crud_list.php`）、`edit()`（`common/crud_form.php`）、`delete()`（POST id）；钩子 `beforeSave($data,$id)`、`afterSave`、`beforeDelete`、`rowActions()`、`topActions()`。视图变量 `$pageTitle,$navActive,$pendingCounts`（sub_review,rec_review,ticket,failed,manual_pay）。

## 公共函数（core/application/common.php）
setting($name,$default) / setting_save(array) / base_path() / site_url() / url_to() / static_url() / money($v) / h($s) / random_str($len) / client_ip() / csrf_token() / csrf_field() / csrf_check() / encrypt_str / decrypt_str / encrypt_json(array) / decrypt_json($s) / audit($action,$target,$detail,$level) / billing_cycles() / cycle_name() / add_cycle() / fmt_time($ts) / status_badge($status) / order_no($prefix) / pager_html() / json_arr($s) / mask_secret($s)
敏感配置（上游、DNS、网关 config）一律 `encrypt_json` 存、`decrypt_json` 读；后台表单显示密钥字段时留空=不修改（显示 mask_secret 提示）。

## 服务层（core/application/common/service/，均为静态方法，出错抛 \Exception 中文消息）
- OrderService：types(); create($user,$type,$title,$data,$amount); createPayment($user,'order'|'recharge',$orderId,$amount,$gateway,$channel); payWithBalance($order); confirmPayment($tradeNo,$amount,$outTradeNo,$raw,$by); startGateway($pay,$subject,$isMobile) 返回网关动作数组（查看 payment/PayGateway.php::create 的返回约定：type=redirect|form|qrcode|html|manual 等）; deliver($order); addBalance(). 订单类型与 data 见 OrderService.php 头注释（host_new/host_renew/host_upgrade/dns_package/dns_sub_new/dns_sub_renew/tier/recharge）。
- ProvisionService：pricing($product); priceOf($product,$cycle); renew; upgrade; upgradePrice; action($sid,$action,$params,$byAdmin); sync($sid)。
- DnsService：quota($user); availableDomains($user); checkAvailable($domain,$prefix,$live); registerSub($user,$domainId,$prefix,$paid); addRecord($user,$subId,$host,$type,$value,$line,$ttl,$mx,$byAdmin,$proxied); updateRecord(...同); deleteRecord($user,$rid,$byAdmin); releaseSub; suspendSub; resumeSub; allowedTypes; lineAllowed; proxyAllowed; providerInfo; driverFor; grantPackage. 线路标签 `\app\common\dns\DnsDriver::$lineLabels`。
- MarketService（用户发布自有域名销售）：saveProvider($user,$driver,$config,$name,$id); remoteDomains($user,$pid); submit($user,$pid,$remoteId,$domain,$opt); update($user,$domainId,$opt); verify($user,$domainId)（TXT `_idcdns-verify` 值=verify_token）; review($id,$approve,$reason); setStatus($id,'active|offline|paused',$reason); remove($user,$id); withdraw($user,$amount,$method,$account); handleWithdraw($id,$approve,$note); healthCheck(); commissionRate($domain)。受保护记录表 dns_protected（上架时快照原有记录，系统永不修改/删除）。
- TierService::current($user)/grant; RiskService::check/tooFrequent/alert/isPrivateIp; CronService::run().
- 工厂：PayFactory::infoAll()/make($code)/enabled(); UpstreamFactory::infoAll()/make($code,$cfg)（驱动 whmcs、zjmf、zjmf_finance、natnode）; DnsFactory::infoAll()/make($code,$cfg)（14 家）。info() 中 `fields` 为 [key,label,type,required,secret,help,options]，后台据此动态生成配置表单。
- lib：Captcha::create($scene) 返回 SVG 字符串（输出 Content-Type image/svg+xml）、Captcha::check($input,$scene)；Notify::events()/user($event,$user,$vars)/admin($event,$vars)/mail()/telegram()；模板设置键 `tpl_{event}_title/body`，开关 `notify_email_{event}`、`notify_tg_{event}`。
- NAT：common/nat/NatScript::defaults()/render($vars)/oneLiner($url)；upstream/NatNode（info2() 原始节点信息）。表 nat_node。面板 API：GET `api/nat/script?node=&token=` 返回渲染后的 bash；POST `api/nat/register`（JSON）；POST `api/nat/heartbeat`。

## UI 风格
Bootstrap 5 + app.css 已有类：.hero .section .section-title .section-sub .card-hover .price .spec .feature-ico .domain-search .uc-side .kpi(.v .l) .table-card .copy-box .auth-wrap .auth-card .captcha-img .admin-side .admin-main .admin-top .admin-content .empty .mono .form-help。必须响应式（手机可用：表格外包 `.table-responsive`，按钮组可换行）。中文界面，文案专业简洁。复制按钮：`<button data-copy="#id">复制</button>`；危险操作：`data-confirm="确定？"`（可用于 a/button/form）；验证码图片 `<img class="captcha-img" src="url_to('index/auth/captcha',['scene'=>'login'])">` 点击刷新；发送邮箱验证码按钮 `data-sendcode="url" data-scene="register"`。
所有业务配置走后台“系统设置”（setting 表，键名自定义但要在设置页可编辑），不得硬编码。

## 第二轮新增（08:20）
- OrderAdminService（后台订单管理）：related($order) 返回 services/subs/records/sales/packages/payments；update($id,$in,$admin)（title/amount/discount/pay_amount/status/admin_remark/data[]/service_due/service_price/service_cycle/service_status/service_product_id/sub_expire/sub_status）；provision($id,$chargeBalance,$admin)（未付款→标记已付 gateway=admin 或扣余额后交付；已付→重新交付，续费类已交付成功则拒绝）；lifecycle($id,'suspend|unsuspend|terminate',$reason,$admin)；delete($id,$opt,$admin)；cancel($id,$opt,$admin)（opt：terminate、remove_dns、reverse_commission、refund_mode=full|partial|none、refund_amount、reason；幂等）；userCancel($user,$id)（仅 unpaid）；requestCancel($user,'service|sub',$targetId,'immediate|end',$reason)；withdrawRequest($user,$id)；handleRequest($id,$approve,$note,['refund_amount'=>x],$admin)；表 cancel_request（status pending/approved/rejected/done/withdrawn）。订单状态 OrderAdminService::$statuses。
- LifecycleService（到期策略）：defaults()、policy($json)、overrideFields()（key => [标签, bool|days|int|mode]）；全局设置键 lc_remind_enable、lc_remind_days(默认"7,3,1")、lc_remind_site、lc_suspend_enable、lc_suspend_days(1)、lc_terminate_enable、lc_terminate_days(7)、lc_dns_suspend_mode(pause|remove)、lc_unpaid_cancel_enable、lc_unpaid_cancel_hours(24)、lc_auto_unsuspend；可覆盖 JSON 列 product.lifecycle、dns_domain.lifecycle、dns_package.lifecycle（值为空=继承）。站内通知表 user_notice(user_id,title,content,is_read)，LifecycleService::siteNotice()。
- 设置键 cancel_request_enable（默认1）。
- 新表/字段：dns_subdomain.order_id、user_package.order_id、user_package.remind_time、order.admin_remark、order.update_time、payment.status 2=已关闭。
- 联调站点：`bash tests/e2e/setup_site.sh php7.0 <端口>` 复制 src 到 /tmp/idcsite_<端口> 并自动安装（库 idcdns_e2e_<端口>，管理员 admin / Admin@12345 仅本地测试）。改了 src 后需重新运行该脚本（或把改动文件同步到 /tmp/idcsite_<端口>）。端口 8090 被占用，请用 82xx/83xx/84xx 各自独立端口。
- 后台登录有验证码：测试时可用 PHP CLI 引导脚本读取 session 文件，或用 curl 先取 captcha 再从 session 存储（data/runtime 下）读取答案——不要在产品里加测试后门。
