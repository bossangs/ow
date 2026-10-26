<?php

namespace app\common\lib;

/**
 * 全部系统设置的默认值（唯一来源）。
 * 数据库里没有保存过的设置项一律取这里的值，后台设置页也按这里显示初始值，
 * 这样首次保存设置页不会把未勾选的开关、空数字写成与系统行为不一致的值。
 */
class SettingDefaults
{
    public static function all()
    {
        return [
            'admin_notify_email' => '',
            'audit_keep_days' => '180',
            'balance_batch_max' => '200',
            'balance_confirm_amount' => '1000',
            'cancel_request_enable' => '1',
            'captcha_enable' => '1',
            'card_batch_max' => '5000',
            'card_captcha_after' => '3',
            'card_enable' => '1',
            'card_lock_after' => '10',
            'card_lock_minutes' => '30',
            'card_notify' => '1',
            'card_notify_admin' => '0',
            'coupon_enable' => '1',
            'cron_key' => '',
            'cron_last_result' => '',
            'cron_last_run' => '0',
            'cron_next_task' => '',
            'cron_notify_batch' => '30',
            'cron_retry_provision' => '1',
            'cron_sync_interval' => '3600',
            'cron_time_budget' => '25',
            'cron_visit_interval' => '300',
            'cron_visit_trigger' => '0',
            'cron_web_enable' => '1',
            'currency_symbol' => '¥',
            'dns_allow_pure_number' => '1',
            'dns_allow_wildcard' => '0',
            'dns_default_record_quota' => '5',
            'dns_default_sub_quota' => '1',
            'dns_live_conflict_check' => '1',
            'dns_min_ttl' => '600',
            'dns_reserved_prefixes' => 'www,mail,ns1,ns2,admin,api,root,ftp,smtp,pop,imap,blog,cdn,static',
            'help_content' => '如需帮助，请提交工单联系我们。',
            'home_stat_subtitle' => '透明价格，快速开通',
            'home_stat_text' => '自动化交付 · 全天候服务',
            'home_subtitle' => '弹性计算、稳定解析，按需使用。',
            'home_title' => '可靠的云主机与 DNS 分发平台',
            'import_markup' => '20',
            'lc_auto_unsuspend' => '1',
            'lc_dns_suspend_mode' => 'pause',
            'lc_remind_days' => '7,3,1',
            'lc_remind_enable' => '1',
            'lc_remind_site' => '1',
            'lc_suspend_days' => '1',
            'lc_suspend_enable' => '1',
            'lc_terminate_days' => '7',
            'lc_terminate_enable' => '1',
            'lc_unpaid_cancel_enable' => '1',
            'lc_unpaid_cancel_hours' => '24',
            'mail_fallback_phpmail' => '0',
            'mail_transport' => 'smtp',
            'market_auto_approve' => '0',
            'market_check_interval' => '3600',
            'market_commission_rate' => '10',
            'market_coupon_bearer' => 'platform',
            'market_drivers' => '',
            'market_enable' => '1',
            'market_max_domains_per_user' => '10',
            'market_max_price' => '9999',
            'nat_node_timeout' => '180',
            'nat_ports_per_instance' => '20',
            'notify_max_attempts' => '5',
            'pay_balance_enable' => '1',
            'reg_bonus' => '0',
            'reg_email_verify' => '0',
            'reg_enable' => '1',
            'risk_block_private_ip' => '1',
            'risk_notify_admin' => '1',
            'risk_record_ops_per_min' => '20',
            'risk_sub_per_day' => '10',
            'site_closed' => '0',
            'site_closed_msg' => '站点维护中，请稍后访问',
            'site_description' => '',
            'site_footer_html' => '',
            'site_icp' => '',
            'site_keywords' => '',
            'site_name' => 'IDC 云',
            'site_notice' => '',
            'site_slogan' => '云主机 · 域名分发',
            'site_url' => '',
            'smtp_enable' => '0',
            'smtp_from' => '',
            'smtp_from_name' => '',
            'smtp_host' => '',
            'smtp_pass' => '',
            'smtp_port' => '465',
            'smtp_secure' => 'ssl',
            'smtp_timeout' => '15',
            'smtp_user' => '',
            'smtp_verify_ssl' => '1',
            'stock_alert_default' => '5',
            'stock_alert_enable' => '1',
            'stock_restock_on_ok' => '0',
            'terms_content' => '请遵守平台服务条款。',
            'tg_admin_chat_id' => '',
            'tg_api_base' => 'https://api.telegram.org',
            'tg_bind_bot_token' => '',
            'tg_bot_token' => '',
            'tg_enable' => '0',
            'tg_rate_per_sec' => '20',
            'theme_color' => '',
            'ticket_attachment_max_files' => '5',
            'ticket_attachment_max_size' => '5242880',
            'ticket_attachment_whitelist' => 'jpg,jpeg,png,gif,webp,avif,pdf,txt,log,zip,docx,xlsx,pptx',
            'ticket_auto_close_days' => '7',
            'ticket_autoclose_warn_days' => '1',
            'ticket_enable' => '1',
            'ticket_max_new_hour' => '5',
            'ticket_max_open' => '10',
            'ticket_overdue_department_hours' => '{}',
            'ticket_overdue_hours' => '6',
            'ticket_overdue_priority_hours' => '{}',
            'ticket_overdue_repeat_hours' => '0',
            'ticket_rating_edit_days' => '3',
            'ticket_rating_scale' => 'stars',
            'ticket_reopen_days' => '7',
            'ticket_reply_min_seconds' => '10',
            'ticket_seller_escalate_hours' => '6',
            'trust_proxy_header' => '',
            'url_mode' => 'compat',
            'verify_code_body' => '您的验证码是 {code}，10 分钟内有效。',
            'verify_code_title' => '邮箱验证码',
            'withdraw_enable' => '1',
            'withdraw_fee_rate' => '0',
            'withdraw_income_only' => '1',
            'withdraw_methods' => '支付宝,USDT-TRC20,银行卡',
            'withdraw_min' => '10',
        ];
    }

    public static function has($name)
    {
        $a = self::all();

        return array_key_exists($name, $a);
    }

    public static function get($name, $fallback = '')
    {
        $a = self::all();

        return array_key_exists($name, $a) ? $a[$name] : $fallback;
    }
}
