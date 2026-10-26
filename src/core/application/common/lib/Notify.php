<?php

namespace app\common\lib;

use PHPMailer\PHPMailer\PHPMailer;
use think\Db;

/**
 * 通知服务（邮件 + Telegram + 站内）
 *
 * - 业务代码调用 user()/admin()/seller() 只写入 notify_queue 队列，不阻塞下单、支付回调；
 * - 计划任务调用 processQueue() 实际发送，失败按退避时间重试，超过次数标记失败；
 * - 每条通知有去重键（事件 + 业务键 + 接收人），同一事件同一业务只发一次；
 * - 每个事件可在后台单独开关邮件/Telegram，标题与正文模板可编辑；
 * - 验证码与“发送测试”走即时发送。
 */
class Notify
{
    /** 这些事件不进队列，立即发邮件（验证码、找回密码链接等有时效的内容） */
    private static $immediate = ['email_code', 'password_reset_code', 'password_reset_link', 'password_changed', 'twofa_changed'];

    /**
     * 事件定义：键 => [名称, 默认标题, 默认正文, 接收方 user|admin|seller]
     * 模板变量用 {变量名}，所有事件都可用 {site_name} {site_url} {username} {now}
     */
    public static function events()
    {
        return [
            // ---- 客户 ----
            'user_register' => ['注册成功', '欢迎注册 {site_name}', "您好 {username}，欢迎注册 {site_name}！\n登录地址：{site_url}", 'user'],
            'email_code' => ['邮箱验证码', '{site_name} 验证码', "您的验证码是：{code}，10 分钟内有效。如非本人操作请忽略。", 'user'],
            'password_reset_code' => ['找回密码验证码', '{site_name} 找回密码验证码', "您好 {username}，您正在找回 {site_name} 账号的登录密码。\n验证码：{code}\n{minutes} 分钟内有效，只能使用一次。\n如果不是您本人操作，请忽略本邮件，您的密码不会改变。", 'user'],
            'password_reset_link' => ['找回密码链接', '{site_name} 重置密码', "您好 {username}，您正在找回 {site_name} 账号的登录密码。\n请在 {minutes} 分钟内打开下面的链接设置新密码（只能使用一次）：\n{link}\n如果不是您本人操作，请忽略本邮件，您的密码不会改变。", 'user'],
            'password_changed' => ['密码已修改', '{site_name} 登录密码已修改', "您好 {username}，您的账号登录密码已于 {time} 修改。\n方式：{method}\nIP：{ip}\n其他设备上的登录已全部退出。\n如果不是您本人操作，请立即通过“忘记密码”重置并联系客服。", 'user'],
            'twofa_changed' => ['两步验证变更', '{site_name} 两步验证已{action}', "您好 {username}，您账号的两步验证（谷歌验证器）已于 {time} {action}。\n操作人：{operator}\nIP：{ip}\n如果不是您本人操作，请立即修改密码并联系客服。", 'user'],
            'order_created' => ['订单已创建（待支付）', '订单 {order_no} 待支付', "您好 {username}，您的订单已创建：\n订单号：{order_no}\n商品：{product}\n金额：{amount}\n请在 {pay_deadline} 前完成支付，超时订单将自动取消。\n支付地址：{pay_url}", 'user'],
            'order_paid' => ['付款成功', '订单 {order_no} 支付成功', "您好 {username}，订单 {order_no}（{product}）已收到付款 {amount}，正在为您处理。", 'user'],
            'service_active' => ['云主机开通成功', '云主机 {hostname} 已开通', "您好 {username}，您的云主机已开通。\n产品：{product}\nIP：{ip}\n端口：{ports}\n登录用户：{host_user}\n到期：{expire_at}\n登录密码请在用户中心查看。", 'user'],
            'dns_sub_active' => ['子域名开通成功', '子域名 {fqdn} 已开通', "您好 {username}，您的子域名 {fqdn} 已开通，到期时间 {expire_at}。\n现在可以在用户中心添加解析记录。", 'user'],
            'provision_failed' => ['开通失败（客户）', '订单 {order_no} 开通遇到问题', "您好 {username}，订单 {order_no}（{product}）自动开通暂未成功，工作人员正在处理，完成后会再次通知您。", 'user'],
            'host_renewed' => ['续费成功', '{product} 续费成功', "您好 {username}，{product}（{hostname}）已续费，新的到期时间：{expire_at}。", 'user'],
            'host_upgraded' => ['升级成功', '{product} 升级完成', "您好 {username}，您的服务 {hostname} 已升级为 {product}。", 'user'],
            'service_due' => ['到期提醒', '{product} 将于 {expire_at} 到期', "您好 {username}，您的 {product}（{hostname}）将于 {expire_at} 到期（剩余 {days} 天），请及时续费以免暂停。", 'user'],
            'dns_expire' => ['子域名到期提醒', '子域名 {fqdn} 将于 {expire_at} 到期', "您好 {username}，您的子域名 {fqdn} 将于 {expire_at} 到期（剩余 {days} 天），请及时续期。", 'user'],
            'service_suspend' => ['服务已暂停', '{product} 已暂停', "您好 {username}，您的 {product}（{hostname}）已暂停，原因：{reason}。续费后将自动恢复。", 'user'],
            'service_unsuspend' => ['服务已恢复', '{product} 已恢复', "您好 {username}，您的 {product}（{hostname}）已恢复正常。", 'user'],
            'service_terminate' => ['服务已终止', '{product} 已终止', "您好 {username}，您的 {product}（{hostname}）已终止，原因：{reason}。数据已删除且无法恢复。", 'user'],
            'order_cancelled' => ['订单已取消', '订单 {order_no} 已取消', "您好 {username}，订单 {order_no}（{product}）已取消。原因：{reason}", 'user'],
            'order_refund' => ['退款通知', '订单 {order_no} 已退款 {amount}', "您好 {username}，订单 {order_no} 已退款 {amount}，退款已退回{refund_to}。当前余额：{balance}", 'user'],
            'balance_adjust' => ['余额变动（后台调整）', '账户余额变动 {amount}', "您好 {username}，您的账户余额变动 {amount}，原因：{reason}。\n当前余额：{balance}", 'user'],
            'card_redeemed' => ['卡密充值成功', '卡密充值到账 {amount}', "您好 {username}，您使用卡密（尾号 {tail}）充值 {amount} 已到账，当前余额 {balance}。", 'user'],
            'recharge_paid' => ['充值成功', '充值到账 {amount}', "您好 {username}，您的账户已到账 {amount}，当前余额 {balance}。", 'user'],
            'dns_approved' => ['子域名审核通过', '子域名 {fqdn} 已通过审核', "您好 {username}，您申请的子域名 {fqdn} 已通过审核，现在可以添加解析记录。", 'user'],
            'dns_rejected' => ['子域名审核驳回', '子域名 {fqdn} 未通过审核', "您好 {username}，您申请的子域名 {fqdn} 未通过审核，原因：{reason}", 'user'],
            'ticket_reply' => ['工单有新回复', '工单 #{ticket_id} 有新回复', "您好 {username}，您的工单「{subject}」有新的回复：\n{content}", 'user'],
            'risk_alert' => ['安全/风控提醒（客户）', '[提醒] {title}', "{content}", 'user'],
            // ---- 卖家（域名市场）----
            'seller_sold' => ['子域名售出（卖家）', '您的域名 {domain} 售出了 {fqdn}', "您好 {username}，买家购买了 {fqdn}。\n成交金额：{amount}\n平台佣金：{commission}\n您的收入：{income}（已计入可提现余额）", 'seller'],
            'seller_withdraw_ok' => ['提现已通过（卖家）', '提现 {amount} 已处理', "您好 {username}，您的提现申请 #{withdraw_id}（{amount}，{method}）已通过并打款。备注：{note}", 'seller'],
            'seller_withdraw_no' => ['提现被驳回（卖家）', '提现 {amount} 未通过', "您好 {username}，您的提现申请 #{withdraw_id}（{amount}）未通过，原因：{note}。金额已退回可提现余额。", 'seller'],
            'seller_listing_pause' => ['上架已暂停（卖家）', '域名 {domain} 已暂停出售', "您好 {username}，您在市场上架的域名 {domain} 已自动暂停出售，原因：{reason}。\n请到用户中心更新 DNS 服务商凭据后重新验证。", 'seller'],
            // ---- 管理员 ----
            'admin_order_new' => ['新订单（管理员）', '[新订单] {order_no} {product}', "用户 {username} 创建了订单 {order_no}\n商品：{product}\n金额：{amount}", 'admin'],
            'admin_order_paid' => ['收到付款（管理员）', '[收款] {order_no} {amount}', "订单 {order_no}（{product}）已收到付款 {amount}，支付方式：{gateway}，用户：{username}", 'admin'],
            'service_failed' => ['开通失败（管理员）', '[告警] 开通失败 {service_id}', "服务 {service_id}（{product}）开通失败：{error}", 'admin'],
            'admin_cancel_request' => ['取消申请（管理员）', '[取消申请] {target}', "用户 {username} 申请取消 {target}\n方式：{mode}\n原因：{reason}", 'admin'],
            'admin_withdraw' => ['提现申请（管理员）', '[提现申请] {username} {amount}', "卖家 {username} 申请提现 {amount}\n方式：{method}\n账户：{account}", 'admin'],
            'stock_low' => ['库存不足（管理员）', '[库存不足] {item} 剩余 {stock}', "{item_type}「{item}」库存剩余 {stock}，已低于提醒阈值 {threshold}，请及时补货。", 'admin'],
            'stock_out' => ['已售罄（管理员）', '[已售罄] {item}', "{item_type}「{item}」已售罄，前台已显示“已售罄”并停止下单。", 'admin'],
            'stock_restock' => ['已补货（管理员）', '[已补货] {item} 剩余 {stock}', "{item_type}「{item}」库存已恢复到 {stock}，前台恢复销售。", 'admin'],
            'admin_card_redeemed' => ['卡密被兑换（管理员）', '[卡密] {username} 兑换 {amount}', "用户 {username} 兑换了卡密（尾号 {tail}），面值 {amount}。", 'admin'],
            'dns_review' => ['子域名待审核（管理员）', '[审核] {fqdn}', "用户 {username} 申请了 {fqdn}，请到后台审核。", 'admin'],
            'ticket_new' => ['新工单（管理员）', '[工单] #{ticket_id} {subject}', "用户 {username} 提交/回复了工单：{subject}\n{content}", 'admin'],
            'ticket_admin_reply' => ['工单客服回复', '工单 #{ticket_id} 有客服回复', "您的工单「{subject}」有新的客服回复：\n{content}", 'user'],
            'ticket_user_reply' => ['工单用户回复（管理员）', '[工单回复] #{ticket_id} {subject}', "工单「{subject}」收到用户新回复：\n{content}", 'admin'],
            'ticket_status' => ['工单状态更新', '工单 #{ticket_id} 状态更新', "工单「{subject}」状态已更新为：{status}", 'user'],
            'ticket_autoclose_warn' => ['工单即将自动关闭', '工单 #{ticket_id} 即将自动关闭', "工单「{subject}」长时间没有您的回复，将在近期自动关闭。", 'user'],
            'ticket_seller_new' => ['卖家工单提醒', '您收到新的卖家工单 #{ticket_id}', "关于「{subject}」的新工单需要您处理。", 'seller'],
            'ticket_seller_reply' => ['卖家工单有新回复', '卖家工单 #{ticket_id} 有新回复', "卖家工单「{subject}」有新的回复。", 'user'],
            'ticket_bad_rating' => ['工单差评（管理员）', '[差评] 工单 #{ticket_id} {subject}', "工单 #{ticket_id}「{subject}」收到 {score} 分评价。\n评价内容：{comment}", 'admin'],
            'ticket_overdue' => ['工单超时未处理（管理员）', '[催单] 工单 #{ticket_id} {subject}', "工单 #{ticket_id}「{subject}」已等待 {wait_hours} 小时 {wait_minutes} 分钟，部门：{department}，优先级：{priority}。\n处理地址：{admin_url}", 'admin'],
            'ticket_seller_overdue' => ['卖家工单超时', '[卖家催单] 工单 #{ticket_id} {subject}', "卖家工单 #{ticket_id}「{subject}」已等待 {wait_hours} 小时 {wait_minutes} 分钟，请及时处理。\n处理地址：{admin_url}", 'seller'],
            'risk_alert_admin' => ['风控告警（管理员）', '[风控] {title}', "{content}", 'admin'],
            'abuse_report' => ['滥用举报（管理员）', '[举报] {target}', "收到对 {target} 的举报：{reason}\n联系方式：{contact}", 'admin'],
        ];
    }

    /** 兼容旧调用：管理员类事件列表 */
    public static function adminEvents()
    {
        $out = [];
        foreach (self::events() as $k => $e) {
            if ($e[3] === 'admin') {
                $out[] = $k;
            }
        }

        return $out;
    }

    /** 常用 SMTP 预设（后台“一键填入”使用）：名称 => [host, port, secure] */
    public static function smtpPresets()
    {
        return [
            'qq' => ['QQ 邮箱', 'smtp.qq.com', 465, 'ssl'],
            'exmail' => ['腾讯企业邮', 'smtp.exmail.qq.com', 465, 'ssl'],
            '163' => ['网易 163 邮箱', 'smtp.163.com', 465, 'ssl'],
            'aliyun' => ['阿里云企业邮', 'smtp.qiye.aliyun.com', 465, 'ssl'],
            'aliyundm' => ['阿里云邮件推送', 'smtpdm.aliyun.com', 465, 'ssl'],
            'outlook' => ['Outlook / Office 365', 'smtp.office365.com', 587, 'tls'],
            'gmail' => ['Gmail', 'smtp.gmail.com', 587, 'tls'],
        ];
    }

    protected static function render($tpl, array $vars)
    {
        $vars['site_name'] = setting('site_name', 'IDC');
        $vars['site_url'] = site_url();
        if (!isset($vars['now'])) {
            $vars['now'] = date('Y-m-d H:i');
        }
        foreach ($vars as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
            }
        }

        // 未提供的变量清空，避免把 {xxx} 原样发给客户
        return preg_replace('/\{[a-z_]+\}/', '', $tpl);
    }

    /** 读取模板（后台编辑过的优先） */
    public static function template($event)
    {
        $ev = self::events();
        $d = isset($ev[$event]) ? $ev[$event] : [$event, $event, '', 'user'];
        $title = setting('tpl_' . $event . '_title', '');
        $body = setting('tpl_' . $event . '_body', '');

        return [$title !== '' ? $title : $d[1], $body !== '' ? $body : $d[2]];
    }

    /** Telegram 模板：后台填写了 tpl_{event}_tg 用它，否则用“标题 + 正文” */
    public static function tgTemplate($event)
    {
        $tg = setting('tpl_' . $event . '_tg', '');
        if ($tg !== '') {
            return $tg;
        }
        list($t, $b) = self::template($event);

        return $t . "\n\n" . $b;
    }

    /** 渲染预览（后台模板页使用） */
    public static function preview($event, array $vars = [])
    {
        list($t, $b) = self::template($event);

        return [self::render($t, $vars), self::render($b, $vars)];
    }

    /** 事件渠道开关：邮件默认开，Telegram 默认开（需配置 bot） */
    public static function enabled($event, $channel)
    {
        if (setting('notify_' . $event . '_off', '0') === '1') {
            return false;
        }

        return setting('notify_' . ($channel === 'email' ? 'email' : 'tg') . '_' . $event, '1') === '1';
    }

    /**
     * 客户通知
     * @param string $event 事件
     * @param array $user 用户行
     * @param array $vars 模板变量；可带 _key（业务去重键，如订单号）
     */
    public static function user($event, array $user, array $vars = [])
    {
        if (empty($user)) {
            return;
        }
        $vars['username'] = isset($user['username']) ? $user['username'] : '';
        list($title, $body) = self::template($event);
        $title = self::render($title, $vars);
        $body = self::render($body, $vars);
        $key = isset($vars['_key']) ? (string)$vars['_key'] : '';
        if (in_array($event, self::$immediate, true)) {
            // 验证码、找回密码链接即时发送，不进队列
            if (!empty($user['email'])) {
                self::mail($user['email'], $title, $body);
            }

            return;
        }
        if (self::enabled($event, 'email') && !empty($user['email'])) {
            self::enqueue($event, 'email', $user['email'], $title, $body, $key);
        }
        if (self::enabled($event, 'tg') && !empty($user['telegram_chat_id']) && !empty($user['telegram_verified'])) {
            self::enqueue($event, 'telegram', $user['telegram_chat_id'], $title, self::render(self::tgTemplate($event), $vars), $key);
        }
        if (setting('notify_site_' . $event, '1') === '1' && !empty($user['id'])) {
            self::siteNotice((int)$user['id'], $title, $body, $event . ':' . $key);
        }
    }

    /** 卖家通知（与客户通知相同渠道） */
    public static function seller($event, array $user, array $vars = [])
    {
        self::user($event, $user, $vars);
    }

    /** 管理员通知 */
    /**
     * 按模板发给指定邮箱（例如某个管理员自己的邮箱），立即事件直接发送，其余进队列。
     * 返回是否已发送/入队。
     */
    public static function toEmail($event, $email, array $vars = [])
    {
        if (!filter_var((string)$email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (!isset($vars['username'])) {
            $vars['username'] = '';
        }
        list($title, $body) = self::template($event);
        $title = self::render($title, $vars);
        $body = self::render($body, $vars);
        if (in_array($event, self::$immediate, true)) {
            return self::mail($email, $title, $body) === true;
        }
        if (!self::enabled($event, 'email')) {
            return false;
        }
        self::enqueue($event, 'email', $email, $title, $body, isset($vars['_key']) ? (string)$vars['_key'] : '');

        return true;
    }

    public static function admin($event, array $vars = [])
    {
        if ($event === 'risk_alert') {
            $event = 'risk_alert_admin';
        }
        if (!isset($vars['username'])) {
            $vars['username'] = '';
        }
        list($title, $body) = self::template($event);
        $title = self::render($title, $vars);
        $body = self::render($body, $vars);
        $key = isset($vars['_key']) ? (string)$vars['_key'] : '';
        if (self::enabled($event, 'email')) {
            foreach (preg_split('/[,;\s]+/', setting('admin_notify_email', '')) as $to) {
                if ($to !== '') {
                    self::enqueue($event, 'email', $to, $title, $body, $key);
                }
            }
        }
        if (self::enabled($event, 'tg')) {
            foreach (preg_split('/[,;\s]+/', setting('tg_admin_chat_id', '')) as $cid) {
                if ($cid !== '') {
                    self::enqueue($event, 'telegram', $cid, $title, self::render(self::tgTemplate($event), $vars), $key);
                }
            }
        }
    }

    /**
     * 入队；去重键相同的通知只入队一次
     * @return int 队列 ID，重复时返回 0
     */
    public static function enqueue($event, $channel, $receiver, $title, $body, $key = '')
    {
        if ($key === '') {
            $key = md5($title . "\n" . $body);
        }
        $dedupe = sha1($event . '|' . $channel . '|' . $receiver . '|' . $key);
        try {
            if (Db::name('notify_queue')->where('dedupe_key', $dedupe)->count()) {
                return 0;
            }

            return (int)Db::name('notify_queue')->insertGetId([
                'event' => $event,
                'channel' => $channel,
                'receiver' => mb_substr((string)$receiver, 0, 190),
                'title' => mb_substr((string)$title, 0, 250),
                'body' => (string)$body,
                'dedupe_key' => $dedupe,
                'status' => 0,
                'attempts' => 0,
                'next_try' => time(),
                'last_error' => '',
                'create_time' => time(),
                'sent_time' => 0,
            ]);
        } catch (\Exception $e) {
            // 唯一索引冲突（并发重复）或表异常：不影响业务流程
            return 0;
        }
    }

    /** 站内通知（去重） */
    public static function siteNotice($userId, $title, $content, $key = '')
    {
        try {
            $q = Db::name('user_notice')->where('user_id', $userId)->where('title', mb_substr($title, 0, 190));
            if ($key !== '' && $q->where('create_time', '>', time() - 86400)->count()) {
                return;
            }
            Db::name('user_notice')->insert([
                'user_id' => $userId,
                'title' => mb_substr($title, 0, 190),
                'content' => $content,
                'is_read' => 0,
                'create_time' => time(),
            ]);
        } catch (\Exception $e) {
        }
    }

    /**
     * 处理队列（计划任务调用）
     * @return array [sent, failed, retry]
     */
    public static function processQueue($limit = 50)
    {
        $max = max(1, (int)setting('notify_max_attempts', '5'));
        $backoff = [60, 300, 900, 3600, 7200, 21600];
        $sent = 0;
        $failed = 0;
        $retry = 0;
        // 发送中超过 10 分钟视为进程中断，重新放回待发送
        Db::name('notify_queue')->where('status', 3)->where('next_try', '<', time() - 600)->update(['status' => 0]);
        $rows = Db::name('notify_queue')->where('status', 0)->where('next_try', '<=', time())
            ->order('id asc')->limit((int)$limit)->select();
        foreach ($rows as $row) {
            // 抢占，防止多个 cron 并发重复发送
            $got = Db::name('notify_queue')->where('id', $row['id'])->where('status', 0)
                ->update(['status' => 3, 'next_try' => time()]);
            if (!$got) {
                continue;
            }
            if ($row['channel'] === 'telegram') {
                self::tgThrottle($row['receiver']);
                self::$retryAfter = 0;
                $r = self::telegram($row['receiver'], $row['body']);
                if ($r !== true && self::$retryAfter > 0) {
                    // 429 限流：按 Telegram 要求的秒数延后，不计入失败次数
                    Db::name('notify_queue')->where('id', $row['id'])->update([
                        'status' => 0, 'next_try' => time() + self::$retryAfter, 'last_error' => mb_substr((string)$r, 0, 500),
                    ]);
                    $retry++;
                    continue;
                }
            } else {
                $r = self::mail($row['receiver'], $row['title'], $row['body']);
            }
            $attempts = (int)$row['attempts'] + 1;
            if ($r === true) {
                Db::name('notify_queue')->where('id', $row['id'])->update([
                    'status' => 1, 'attempts' => $attempts, 'sent_time' => time(), 'last_error' => '',
                ]);
                $sent++;
            } elseif ($attempts >= $max) {
                Db::name('notify_queue')->where('id', $row['id'])->update([
                    'status' => 2, 'attempts' => $attempts, 'last_error' => mb_substr((string)$r, 0, 500),
                ]);
                $failed++;
            } else {
                $wait = $backoff[min($attempts - 1, count($backoff) - 1)];
                Db::name('notify_queue')->where('id', $row['id'])->update([
                    'status' => 0, 'attempts' => $attempts, 'next_try' => time() + $wait,
                    'last_error' => mb_substr((string)$r, 0, 500),
                ]);
                $retry++;
            }
        }

        return [$sent, $failed, $retry];
    }

    /** @var int Telegram 429 返回的 retry_after 秒数 */
    protected static $retryAfter = 0;
    /** @var array 每个 chat 上次发送时间（微秒） */
    protected static $tgLast = [];
    /** @var float 全局上次发送时间 */
    protected static $tgGlobalLast = 0.0;

    /** Telegram 限速：同一 chat 每秒最多 1 条，全局每秒最多 tg_rate_per_sec 条（默认 20） */
    protected static function tgThrottle($chatId)
    {
        $perSec = max(1, (int)setting('tg_rate_per_sec', '20'));
        $now = microtime(true);
        $gap = 1.0 / $perSec;
        if (self::$tgGlobalLast > 0 && $now - self::$tgGlobalLast < $gap) {
            usleep((int)(($gap - ($now - self::$tgGlobalLast)) * 1000000));
        }
        $now = microtime(true);
        if (isset(self::$tgLast[$chatId]) && $now - self::$tgLast[$chatId] < 1.05) {
            usleep((int)((1.05 - ($now - self::$tgLast[$chatId])) * 1000000));
        }
        self::$tgLast[$chatId] = self::$tgGlobalLast = microtime(true);
    }

    /**
     * 用户绑定 Telegram：用户先在 Telegram 里给机器人发送 /start，然后在用户中心填写数字 chat ID，
     * 系统通过 sendMessage 发送 6 位验证码，用户回填后完成绑定。
     * 本系统只调用 sendMessage，绝不调用 setWebhook / deleteWebhook / getUpdates，
     * 因此可与已有 webhook 机器人共用同一个 Bot Token。
     * @return true|string
     */
    public static function tgBindStart(array $user, $chatId)
    {
        $chatId = trim((string)$chatId);
        if (!preg_match('/^-?\d{5,20}$/', $chatId)) {
            return 'chat ID 格式不正确，应为数字';
        }
        $last = Db::name('verify_code')->where('target', 'tg:' . $user['id'])->order('id desc')->find();
        if ($last && $last['create_time'] > time() - 60) {
            return '发送太频繁，请 60 秒后再试';
        }
        $code = (string)mt_rand(100000, 999999);
        $r = self::telegram($chatId, setting('site_name', 'IDC') . ' 绑定验证码：' . $code . "\n10 分钟内有效。如非本人操作请忽略。", true);
        if ($r !== true) {
            return '发送失败：' . $r . '（请确认已在 Telegram 中给机器人发送过 /start）';
        }
        Db::name('verify_code')->insert([
            'target' => 'tg:' . $user['id'],
            'scene' => 'tg_bind',
            'code' => password_hash($code . '|' . $chatId, PASSWORD_DEFAULT),
            'expire_time' => time() + 600,
            'create_time' => time(),
        ]);

        return true;
    }

    /** 校验绑定验证码，成功后写入 user.telegram_chat_id */
    public static function tgBindVerify(array $user, $chatId, $code)
    {
        $row = Db::name('verify_code')->where('target', 'tg:' . $user['id'])->where('scene', 'tg_bind')
            ->where('expire_time', '>', time())->order('id desc')->find();
        if (!$row || !password_verify(trim((string)$code) . '|' . trim((string)$chatId), $row['code'])) {
            return '验证码错误或已过期';
        }
        Db::name('verify_code')->where('id', $row['id'])->delete();
        Db::name('user')->where('id', $user['id'])->update(['telegram_chat_id' => trim((string)$chatId), 'telegram_verified' => 1]);
        audit('user.tg_bind', '用户#' . $user['id'], ['chat_id' => trim((string)$chatId)], 'info', 'user', $user['id']);

        return true;
    }

    /** 后台“重新发送”失败的队列项 */
    public static function retry($id)
    {
        return Db::name('notify_queue')->where('id', (int)$id)->where('status', 'in', [0, 2])
            ->update(['status' => 0, 'next_try' => time(), 'attempts' => 0]);
    }

    /** 发送邮件（即时），返回 true 或错误字符串 */
    public static function mail($to, $subject, $body, $isHtml = false)
    {
        // 纯文本通知默认套用品牌 HTML 模板（顶部 Logo 用绝对地址），同时保留纯文本备用正文
        $altBody = '';
        if (!$isHtml && setting('mail_html_enable', '1') === '1') {
            $altBody = (string)$body;
            $body = self::htmlWrap($subject, $altBody);
            $isHtml = true;
        }
        $transport = setting('mail_transport', 'smtp');
        if ($transport === 'phpmail') {
            return self::phpMail($to, $subject, $body, $isHtml);
        }
        if (setting('smtp_enable', '0') !== '1' || setting('smtp_host', '') === '') {
            self::log('email', $to, $subject, 0, 'SMTP 未启用');

            return 'SMTP 未启用或未配置';
        }
        try {
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->CharSet = 'UTF-8';
            $m->Host = setting('smtp_host');
            $m->Port = (int)setting('smtp_port', '465');
            $sec = setting('smtp_secure', 'ssl');
            if ($sec === 'ssl') {
                $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($sec === 'tls') {
                // STARTTLS（通常是 587 端口）
                $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $m->SMTPSecure = '';
                $m->SMTPAutoTLS = false;
            }
            $user = setting('smtp_user', '');
            if ($user !== '') {
                $m->SMTPAuth = true;
                $m->Username = $user;
                $m->Password = decrypt_str(setting('smtp_pass', ''));
            }
            $m->Timeout = (int)setting('smtp_timeout', '15');
            if (setting('smtp_verify_ssl', '1') !== '1') {
                $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            }
            $from = setting('smtp_from', '') ?: $user;
            $m->setFrom($from, setting('smtp_from_name', '') ?: setting('site_name', 'IDC'));
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->isHTML((bool)$isHtml);
            $m->Body = $body;
            if ($altBody !== '') {
                $m->AltBody = $altBody;
            }
            $m->send();
            self::log('email', $to, $subject, 1, '');

            return true;
        } catch (\Exception $e) {
            $err = mb_substr($e->getMessage(), 0, 400);
            self::log('email', $to, $subject, 0, $err);
            if (setting('mail_fallback_phpmail', '0') === '1') {
                return self::phpMail($to, $subject, $body, $isHtml);
            }

            return $err;
        }
    }

    /**
     * 品牌邮件 HTML：表格布局 + 行内样式，兼容主流邮箱客户端。
     * Logo 必须是绝对地址（邮件里没有相对路径）；未配置站点地址时只显示站点名文字。
     */
    public static function htmlWrap($subject, $text)
    {
        $site = setting('site_name', 'IDC');
        $base = site_url();
        $logo = brand_logo_url(false, true, true);
        $color = setting('theme_color', '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#0d6efd';
        }
        $e = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        $html = $e($text);
        // 链接可点击（只处理 http/https，转义后再匹配，避免注入）
        $html = preg_replace('#\bhttps?://[^\s<>"\']+#i', '<a href="$0" style="color:' . $color . '">$0</a>', $html);
        $html = nl2br($html);
        $head = $logo !== ''
            ? '<img src="' . $e($logo) . '" alt="' . $e($site) . '" height="40" style="height:40px;width:auto;border:0;display:block">'
            : '<span style="font-size:22px;font-weight:700;color:' . $color . '">' . $e($site) . '</span>';
        if ($base !== '') {
            $head = '<a href="' . $e($base) . '" style="text-decoration:none">' . $head . '</a>';
        }
        $foot = $e($site) . ($base !== '' ? ' · <a href="' . $e($base) . '" style="color:#8a94a6">' . $e(preg_replace('#^https?://#', '', $base)) . '</a>' : '');

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f6fb">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6fb"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;border:1px solid #e3e8f0">'
            . '<tr><td style="padding:22px 28px;border-bottom:3px solid ' . $color . '">' . $head . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font:15px/1.75 -apple-system,BlinkMacSystemFont,\'Segoe UI\',\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#1f2937">'
            . '<div style="font-size:17px;font-weight:600;margin-bottom:12px">' . $e($subject) . '</div>' . $html . '</td></tr>'
            . '<tr><td style="padding:14px 28px;background:#f8fafc;border-radius:0 0 12px 12px;font:12px/1.6 sans-serif;color:#8a94a6">此邮件由系统自动发送，请勿直接回复。<br>' . $foot . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** PHP mail() 发送（虚拟主机兜底） */
    protected static function phpMail($to, $subject, $body, $isHtml)
    {
        if (!function_exists('mail')) {
            self::log('email', $to, $subject, 0, 'mail() 不可用');

            return 'PHP mail() 函数不可用';
        }
        $from = setting('smtp_from', '');
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/" . ($isHtml ? 'html' : 'plain') . "; charset=UTF-8\r\n";
        if ($from !== '') {
            $name = '=?UTF-8?B?' . base64_encode(setting('smtp_from_name', '') ?: setting('site_name', 'IDC')) . '?=';
            $headers .= 'From: ' . $name . ' <' . $from . ">\r\n";
        }
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        self::log('email', $to, $subject, $ok ? 1 : 0, $ok ? '' : 'mail() 发送失败');

        return $ok ? true : 'PHP mail() 发送失败';
    }

    /** Telegram 发送（即时） */
    public static function telegram($chatId, $text, $forBind = false)
    {
        $token = decrypt_str(setting('tg_bot_token', ''));
        if ($forBind && setting('tg_bind_bot_token', '') !== '') {
            // 可选：绑定专用机器人
            $token = decrypt_str(setting('tg_bind_bot_token', ''));
        }
        if (setting('tg_enable', '0') !== '1' || $token === '') {
            self::log('telegram', $chatId, mb_substr($text, 0, 60), 0, 'Telegram 未启用');

            return 'Telegram 未启用或未配置';
        }
        $base = rtrim(setting('tg_api_base', 'https://api.telegram.org'), '/');
        $r = HttpClient::postJson($base . '/bot' . $token . '/sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4000),
            'disable_web_page_preview' => true,
        ], [], 15);
        $d = HttpClient::json($r);
        if ($d && !empty($d['ok'])) {
            self::log('telegram', $chatId, mb_substr($text, 0, 60), 1, '');

            return true;
        }
        if ($d && isset($d['parameters']['retry_after'])) {
            self::$retryAfter = (int)$d['parameters']['retry_after'];
        }
        $err = $d && isset($d['description']) ? $d['description'] : ($r['error'] ?: ('HTTP ' . $r['status']));
        $err = str_replace($token, '***', $err);
        self::log('telegram', $chatId, mb_substr($text, 0, 60), 0, $err);

        return $err;
    }

    protected static function log($channel, $to, $title, $ok, $err)
    {
        try {
            Db::name('notify_log')->insert([
                'channel' => $channel,
                'receiver' => mb_substr((string)$to, 0, 190),
                'title' => mb_substr((string)$title, 0, 250),
                'status' => $ok ? 1 : 0,
                'error' => mb_substr((string)$err, 0, 500),
                'create_time' => time(),
            ]);
        } catch (\Exception $e) {
        }
    }
}
