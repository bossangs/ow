<?php

namespace app\common\service;

use think\Db;
use app\common\lib\Notify;

/**
 * 风控：规则匹配 + 频率限制 + 内网地址拦截
 * 规则类型 type: prefix(子域名前缀) value(解析记录值) email(注册邮箱) ip(访问IP)
 * 匹配方式 match_type: exact contains regex cidr ；动作 action: deny review
 */
class RiskService
{
    public static function ruleTypes()
    {
        return ['prefix' => '子域名前缀', 'value' => '解析记录值', 'email' => '注册邮箱', 'ip' => '访问 IP'];
    }

    public static function matchTypes()
    {
        return ['exact' => '完全匹配', 'contains' => '包含关键词', 'regex' => '正则表达式', 'cidr' => 'IP 段 (CIDR)'];
    }

    /**
     * @return array|null 命中的规则 ['action','rule']
     */
    public static function check($type, $subject)
    {
        $subject = strtolower(trim((string)$subject));
        if ($subject === '') {
            return null;
        }
        $rules = Db::name('risk_rule')->where('type', $type)->where('status', 1)->order('action asc,id asc')->select();
        foreach ($rules as $r) {
            if (self::match($r['match_type'], strtolower($r['pattern']), $subject)) {
                Db::name('risk_rule')->where('id', $r['id'])->setInc('hits');

                return ['action' => $r['action'], 'rule' => $r];
            }
        }

        return null;
    }

    public static function match($mt, $pattern, $subject)
    {
        switch ($mt) {
            case 'exact':
                return $subject === $pattern;
            case 'contains':
                return $pattern !== '' && strpos($subject, $pattern) !== false;
            case 'regex':
                $re = '#' . str_replace('#', '\\#', $pattern) . '#i';

                return @preg_match($re, $subject) === 1;
            case 'cidr':
                return self::ipInCidr($subject, $pattern);
        }

        return false;
    }

    public static function ipInCidr($ip, $cidr)
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        list($net, $bits) = explode('/', $cidr, 2);
        $ipb = @inet_pton($ip);
        $netb = @inet_pton($net);
        if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipb, 0, $bytes) !== substr($netb, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rem)) & 0xff);

        return (($ipb[$bytes] & $mask) === ($netb[$bytes] & $mask));
    }

    /** 是否内网/保留地址 */
    public static function isPrivateIp($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * 频率限制（基于审计日志计数）
     * @param string $action 审计动作
     * @param int $limit 次数上限（0 不限制）
     * @param int $window 秒
     */
    public static function tooFrequent($action, $limit, $window, $byIp = true, $userId = 0)
    {
        $limit = (int)$limit;
        if ($limit <= 0) {
            return false;
        }
        $q = Db::name('audit_log')->where('action', $action)->where('create_time', '>', time() - $window);
        if ($byIp) {
            $q->where('ip', client_ip());
        } else {
            $q->where('actor_id', $userId)->where('actor_type', 'user');
        }

        return $q->count() >= $limit;
    }

    /** 记录风控事件并按设置通知管理员 */
    public static function alert($title, $content, $level = 'warn')
    {
        audit('risk.alert', $title, $content, $level);
        if (setting('risk_notify_admin', '1') === '1') {
            Notify::admin('risk_alert', ['title' => $title, 'content' => is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content]);
        }
    }
}
