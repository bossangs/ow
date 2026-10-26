<?php

namespace app\admin\controller;

use app\common\lib\BrandUpload;

use app\common\controller\AdminBase;
use app\common\lib\Notify;
use app\common\service\LifecycleService;

class Settings extends AdminBase
{
    /** 修改密钥/接口配置、审批等敏感操作需要二次验证（已开启两步验证的管理员） */
    protected $sudoActions = ['index:post', 'regeneratecronkey'];
    protected $title = '系统设置';
    protected function groups()
    {
        return ['basic' => ['基本', ['site_name', 'site_url', 'site_logo', 'site_logo_light', 'site_favicon', 'site_slogan', 'site_description', 'site_keywords', 'site_notice', 'site_icp', 'site_footer_html', 'currency_symbol', 'theme_color', 'site_closed', 'site_closed_msg']], 'account' => ['注册与登录', ['reg_enable', 'reg_email_verify', 'captcha_enable', 'reg_gift_balance', 'default_tier_id']], 'dns' => ['DNS规则', ['dns_reserved_prefixes', 'dns_allow_pure_number', 'dns_allow_wildcard', 'dns_live_conflict_check', 'dns_min_ttl', 'dns_default_record_quota', 'dns_default_sub_quota']], 'risk' => ['风控', ['risk_sub_per_day', 'risk_record_ops_per_min', 'risk_block_private_ip', 'risk_notify_admin']], 'order' => ['订单与取消', ['cancel_request_enable', 'lc_unpaid_cancel_enable', 'lc_unpaid_cancel_hours', 'order_cancel_hours', 'cron_retry_provision']], 'lifecycle' => ['到期策略', ['lc_remind_enable', 'lc_remind_days', 'lc_remind_site', 'lc_suspend_enable', 'lc_suspend_days', 'lc_terminate_enable', 'lc_terminate_days', 'lc_dns_suspend_mode', 'lc_auto_unsuspend']], 'ticket' => ['工单与满意度', ['ticket_enable', 'ticket_rating_scale', 'ticket_rating_edit_days', 'ticket_overdue_hours', 'ticket_overdue_repeat_hours', 'ticket_overdue_priority_hours', 'ticket_overdue_department_hours', 'ticket_seller_escalate_hours', 'ticket_attachment_whitelist', 'ticket_attachment_max_size', 'ticket_attachment_max_files', 'ticket_auto_close_days', 'ticket_autoclose_warn_days', 'ticket_reopen_days', 'ticket_max_new_hour', 'ticket_max_open', 'ticket_reply_min_seconds']], 'smtp' => ['邮件 SMTP', ['mail_html_enable', 'smtp_enable', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name', 'smtp_verify_ssl', 'admin_notify_email']], 'telegram' => ['Telegram', ['tg_enable', 'tg_api_base', 'tg_bot_token', 'tg_admin_chat_id']], 'market' => ['域名市场与提现', ['market_enable', 'market_coupon_bearer', 'market_commission_rate', 'market_endpoint_cloudflare', 'market_auto_approve', 'market_max_domains_per_user', 'market_max_price', 'market_drivers', 'market_check_interval', 'withdraw_enable', 'withdraw_min', 'withdraw_fee_rate', 'withdraw_methods', 'withdraw_income_only', 'pay_balance_enable']], 'coupon' => ['优惠码与库存', ['coupon_enable', 'stock_alert_enable', 'stock_alert_default', 'stock_restock_on_ok', 'nat_ports_per_instance', 'nat_reg_token_hours', 'nat_node_timeout']], 'cron' => ['计划任务', ['cron_web_enable', 'cron_time_budget', 'cron_notify_batch', 'cron_visit_trigger', 'cron_visit_interval']], 'security' => ['登录安全', ['admin_2fa_required', 'admin_sudo_minutes', 'twofa_issuer', 'admin_reset_method', 'reset_method', 'reset_code_minutes', 'reset_link_minutes', 'balance_confirm_amount', 'admin_ip_whitelist', 'trust_proxy_header', 'url_mode']]];
    }
    protected function definitions()
    {
        return ['admin_2fa_required' => ['强制所有管理员开启两步验证', 'switch', 'default' => '0', 'help' => '开启后，没绑定谷歌验证器的管理员登录后只能先去绑定，绑定前不能使用后台其他功能。建议先自己绑定好再打开'],
            'admin_sudo_minutes' => ['敏感操作免验证时长（分钟）', 'number', 'default' => '5', 'help' => '已开启两步验证的管理员，修改支付/DNS/上游/NAT 密钥、审批提现、大额调余额、修改密码或管理员前要再输一次验证码；验证后这么多分钟内不再询问（1-60）'],
            'balance_confirm_amount' => ['大额余额调整确认金额', 'number', 'default' => '1000', 'help' => '单次（批量按总额）调整达到这个金额时，需要输入管理员登录密码，开启了两步验证的还要输谷歌验证码；填 0 表示不需要'],
            'twofa_issuer' => ['验证器里显示的名称', 'text', 'default' => '', 'help' => '扫码后在 Google Authenticator 里显示的名字，留空使用站点名称'],
            'admin_reset_method' => ['管理员找回密码方式', 'select', ['code' => '邮箱验证码', 'link' => '邮件重置链接', 'both' => '两种都可以'], 'default' => 'code'],
            'reset_method' => ['用户找回密码方式', 'select', ['code' => '邮箱验证码', 'link' => '邮件重置链接', 'both' => '两种都可以'], 'default' => 'code'],
            'reset_code_minutes' => ['找回密码验证码有效期（分钟）', 'number', 'default' => '10', 'help' => '1-60 分钟；连续输错 5 次验证码作废'],
            'reset_link_minutes' => ['重置链接有效期（分钟）', 'number', 'default' => '30', 'help' => '5-1440 分钟；链接只能使用一次'],
            'site_logo' => ['站点 Logo', 'image', 'help' => '前台页头、页脚、登录页和邮件顶部使用。建议透明底 PNG，高 64~128 像素；不上传则使用内置 o2o云 Logo。'],
            'site_logo_light' => ['深色背景 Logo（后台侧栏）', 'image', 'help' => '白色/浅色版，用在深色的后台侧栏；不上传则使用站点 Logo（未上传站点 Logo 时用内置反白版）。'],
            'site_favicon' => ['网站图标（favicon）', 'image', 'help' => '浏览器标签页图标，支持 ICO 或正方形 PNG（建议 32×32 以上）；PNG 同时用作手机主屏幕图标。'],
            'mail_html_enable' => ['邮件使用品牌 HTML 模板', 'switch', 'default' => '1', 'help' => '开启后邮件顶部显示站点 Logo（需填写站点地址），同时附带纯文本正文。'],
            'ticket_enable' => ['启用工单', 'switch', 'default' => '1'], 'ticket_rating_scale' => ['评分方式', 'select', ['stars' => '1-5 星', 'simple' => '好评/中评/差评'], 'default' => 'stars'], 'ticket_rating_edit_days' => ['评价可修改天数', 'number', 'default' => '3'], 'ticket_overdue_hours' => ['催单等待小时数', 'number', 'default' => '6'], 'ticket_overdue_repeat_hours' => ['催单重复间隔小时数（0=关闭）', 'number', 'default' => '0'], 'ticket_overdue_priority_hours' => ['优先级催单覆盖 JSON', 'text', 'default' => '{}'], 'ticket_overdue_department_hours' => ['部门催单覆盖 JSON', 'text', 'default' => '{}'], 'ticket_seller_escalate_hours' => ['卖家工单升级小时数', 'number', 'default' => '6'], 'ticket_attachment_whitelist' => ['附件扩展名白名单', 'text', 'default' => 'jpg,jpeg,png,gif,webp,avif,pdf,txt,log,zip,docx,xlsx,pptx'], 'ticket_attachment_max_size' => ['单个附件最大字节数', 'number', 'default' => '5242880'], 'ticket_attachment_max_files' => ['每次最多附件数', 'number', 'default' => '5'], 'ticket_auto_close_days' => ['自动关闭等待天数', 'number', 'default' => '7'], 'ticket_autoclose_warn_days' => ['自动关闭提前提醒天数', 'number', 'default' => '1'], 'ticket_reopen_days' => ['关闭后可重开天数', 'number', 'default' => '7'], 'ticket_max_new_hour' => ['每小时最多新建工单', 'number', 'default' => '5'], 'ticket_max_open' => ['最多未关闭工单数', 'number', 'default' => '10'], 'ticket_reply_min_seconds' => ['回复最短间隔秒数', 'number', 'default' => '10'], 'site_name' => ['站点名称', 'text'], 'site_url' => ['站点 URL', 'text'], 'site_slogan' => ['站点标语', 'text'], 'site_description' => ['站点描述', 'textarea'], 'site_keywords' => ['SEO 关键词', 'text'], 'site_notice' => ['站点公告', 'textarea'], 'site_icp' => ['ICP备案号', 'text'], 'site_footer_html' => ['页脚 HTML', 'textarea'], 'currency_symbol' => ['货币符号', 'text', 'default' => '¥'], 'theme_color' => ['主题色', 'text'], 'site_closed' => ['关闭站点', 'switch'], 'site_closed_msg' => ['关闭提示', 'textarea'], 'reg_enable' => ['允许注册', 'switch'], 'reg_email_verify' => ['注册需邮箱验证', 'switch'], 'captcha_enable' => ['启用验证码', 'switch'], 'reg_gift_balance' => ['注册赠送余额', 'number'], 'default_tier_id' => ['默认会员等级 ID', 'number'], 'dns_reserved_prefixes' => ['保留前缀', 'text'], 'dns_allow_pure_number' => ['允许纯数字前缀', 'switch'], 'dns_allow_wildcard' => ['允许泛解析', 'switch'], 'dns_live_conflict_check' => ['现场冲突检查', 'switch'], 'dns_min_ttl' => ['最小 TTL', 'number'], 'dns_default_record_quota' => ['默认记录配额', 'number'], 'dns_default_sub_quota' => ['默认子域名配额', 'number'], 'risk_sub_per_day' => ['每日申请子域名上限', 'number'], 'risk_record_ops_per_min' => ['每分钟解析操作上限', 'number'], 'risk_block_private_ip' => ['阻止内网 IP', 'switch'], 'risk_notify_admin' => ['风控通知管理员', 'switch'], 'cancel_request_enable' => ['允许用户申请取消', 'switch'], 'lc_unpaid_cancel_enable' => ['未付款订单自动取消', 'switch'], 'lc_unpaid_cancel_hours' => ['未付款超时小时数', 'number'], 'order_cancel_hours' => ['订单取消小时数', 'number'], 'cron_retry_provision' => ['自动重试开通', 'switch'], 'lc_remind_enable' => ['到期提醒', 'switch'], 'lc_remind_days' => ['提醒天数', 'text'], 'lc_remind_site' => ['发送站内通知', 'switch'], 'lc_suspend_enable' => ['自动暂停', 'switch'], 'lc_suspend_days' => ['逾期暂停天数', 'number'], 'lc_terminate_enable' => ['自动终止', 'switch'], 'lc_terminate_days' => ['逾期终止天数', 'number'], 'lc_dns_suspend_mode' => ['DNS暂停模式', 'select', ['pause' => '暂停保留记录', 'remove' => '删除系统记录']], 'lc_auto_unsuspend' => ['续费自动恢复', 'switch'], 'smtp_enable' => ['启用 SMTP', 'switch'], 'smtp_host' => ['SMTP 主机', 'text'], 'smtp_port' => ['SMTP 端口', 'number'], 'smtp_secure' => ['加密方式', 'select', ['ssl' => 'SSL', 'tls' => 'TLS', 'none' => '无']], 'smtp_user' => ['SMTP 用户名', 'text'], 'smtp_pass' => ['SMTP 密码', 'password'], 'smtp_from' => ['发件地址', 'email'], 'smtp_from_name' => ['发件人名称', 'text'], 'smtp_verify_ssl' => ['验证 SSL 证书', 'switch'], 'admin_notify_email' => ['管理员通知邮箱', 'text'], 'tg_enable' => ['启用 Telegram', 'switch'], 'tg_api_base' => ['API 地址', 'text'], 'tg_bot_token' => ['Bot Token', 'password'], 'tg_admin_chat_id' => ['管理员 Chat ID', 'text'], 'market_enable' => ['启用域名市场', 'switch'], 'market_coupon_bearer' => ['市场优惠承担方', 'select', ['platform' => '平台承担', 'seller' => '卖家承担']], 'market_endpoint_cloudflare' => ['卖家 Cloudflare 接口地址（可选）', 'text', 'help' => '留空使用官方 https://api.cloudflare.com/client/v4；服务器访问不了官方接口时填 API 反向代理地址。卖家自己不能修改接口地址（防止 SSRF）'], 'market_commission_rate' => ['默认佣金比例 %', 'number'], 'market_auto_approve' => ['自动审核', 'switch'], 'market_max_domains_per_user' => ['每用户最多域名', 'number'], 'market_max_price' => ['最高售价', 'number'], 'market_drivers' => ['允许的 DNS 驱动', 'text'], 'market_check_interval' => ['健康检查间隔', 'number'], 'withdraw_enable' => ['启用提现', 'switch'], 'withdraw_min' => ['最低提现金额', 'number'], 'withdraw_fee_rate' => ['提现手续费 %', 'number'], 'withdraw_methods' => ['提现方式', 'text'], 'withdraw_income_only' => ['仅可提现销售收入', 'switch'], 'pay_balance_enable' => ['启用余额支付', 'switch'], 'coupon_enable' => ['启用优惠码', 'switch'], 'stock_alert_enable' => ['启用库存提醒', 'switch'], 'stock_alert_default' => ['默认低库存阈值', 'number', 'default' => '5'], 'stock_restock_on_ok' => ['补货恢复提醒', 'switch'], 'nat_ports_per_instance' => ['每 NAT 实例端口数', 'number', 'default' => '20'], 'nat_reg_token_hours' => ['NAT 节点注册令牌有效期（小时）', 'number', 'default' => '24'], 'nat_node_timeout' => ['NAT 节点离线判定（秒，无心跳超过即离线）', 'number', 'default' => '180'], 'cron_web_enable' => ['启用 Web 计划任务', 'switch'], 'cron_time_budget' => ['Cron 时间预算', 'number', 'default' => '25'], 'cron_notify_batch' => ['通知批量数', 'number', 'default' => '30'], 'cron_visit_trigger' => ['访问触发兜底', 'switch'], 'cron_visit_interval' => ['访问触发间隔', 'number', 'default' => '300'], 'admin_ip_whitelist' => ['后台 IP 白名单', 'textarea'], 'trust_proxy_header' => ['可信代理头', 'text'], 'url_mode' => ['URL 模式', 'select', ['normal' => '兼容模式', 'rewrite' => '伪静态模式']]];
    }
    public function index()
    {
        $groups = $this->groups();
        $defs = $this->definitions();
        if (empty($this->admin['is_super'])) {
            // 登录安全（强制两步验证、后台 IP 白名单等）只有超级管理员能查看和修改
            unset($groups['security']);
        }
        if ($this->request->isPost()) {
            // 品牌图片：先处理上传 / 恢复默认，失败时不保存其他设置，避免用户以为已生效
            $brandSaved = [];
            foreach ($defs as $k => $d) {
                if ($d[1] !== 'image') {
                    continue;
                }
                $old = (string)setting($k, '');
                if ($this->request->post($k . '_reset') === '1') {
                    BrandUpload::remove($old);
                    setting_save([$k => '']);
                    $brandSaved[] = $d[0] . '已恢复默认';
                    continue;
                }
                if (empty($_FILES[$k]) || !is_array($_FILES[$k]) || is_array($_FILES[$k]['name'])) {
                    continue;
                }
                list($path, $err) = BrandUpload::save($_FILES[$k], $k);
                if ($err !== '') {
                    $this->flash($err, 'danger');

                    return $this->back();
                }
                if ($path !== '') {
                    BrandUpload::remove($old);
                    setting_save([$k => $path]);
                    $brandSaved[] = $d[0] . '已更新';
                }
            }
            if ($brandSaved) {
                audit('admin.settings.brand', implode('；', $brandSaved));
            }
            foreach ($defs as $k => $d) {
                if ($d[1] === 'image') {
                    continue;
                }
                $v = $this->request->post($k, null);
                if ($v === null) {
                    continue;
                }if ($d[1] === 'switch') {
                    $v = $this->request->post($k) ? '1' : '0';
                }if ($d[1] === 'password') {
                    if ($v === '') {
                        continue;
                    }$v = encrypt_str($v);
                }if ($d[1] === 'number' && trim((string)$v) === '') {
                    // 数字留空 = 恢复默认值
                    \think\Db::name('setting')->where('name', $k)->delete();
                    setting(false);
                    continue;
                }if ($d[1] === 'number' && $k === 'cron_time_budget') {
                    $v = max(5, min(120, (int)$v));
                }setting_save([$k => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : trim((string)$v)]);
            }foreach (Notify::events() as $event => $ev) {
                foreach (['notify_email_' . $event, 'notify_tg_' . $event, 'tpl_' . $event . '_title', 'tpl_' . $event . '_body'] as $k) {
                    $v = $this->request->post($k, null);
                    if ($v !== null) {
                        setting_save([$k => $v]);
                    }
                }
            }audit('admin.settings.save', '系统设置');
            $this->flash('设置已保存');

            return $this->back();
        }$values = [];
        foreach ($defs as $k => $d) {
            $values[$k] = setting($k, isset($d['default']) ? $d['default'] : '');
        }$values['smtp_pass'] = $values['tg_bot_token'] = '';

        return $this->fetch('settings', ['groups' => $groups, 'defs' => $defs, 'values' => $values, 'events' => Notify::events(), 'lifecycle' => LifecycleService::defaults()]);
    }
    public function regenerateCronKey()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }setting_save(['cron_key' => bin2hex(random_bytes(20))]);
        audit('admin.settings.cron_key', '重新生成计划任务密钥', '', 'warn');
        $this->flash('计划任务密钥已重新生成');

        return $this->back();
    }
    public function testMail()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }$r = Notify::mail(trim($this->request->post('to', '')), '测试邮件', '后台 SMTP 配置测试成功。');
        $this->flash($r === true ? '测试邮件已发送' : (string)$r, $r === true ? 'success' : 'danger');

        return $this->back();
    }
    public function testTelegram()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        $chatId = trim((string) $this->request->post('chat_id', ''));
        if ($chatId === '') {
            // 测试按钮不带 chat_id 时，发给「管理员 Chat ID」里的第一个
            $ids = preg_split('/[,;\s]+/', trim((string) setting('tg_admin_chat_id', '')));
            $chatId = isset($ids[0]) ? $ids[0] : '';
        }
        if ($chatId === '') {
            $this->flash('请先填写并保存「管理员 Chat ID」', 'danger');

            return $this->back();
        }
        $r = Notify::telegram($chatId, '后台 Telegram 配置测试成功。');
        $this->flash($r === true ? '测试消息已发送' : (string)$r, $r === true ? 'success' : 'danger');

        return $this->back();
    }
}
