<?php

namespace app\common\service;

use think\Db;
use app\common\dns\DnsFactory;
use app\common\lib\Notify;

/**
 * 域名分发核心：子域名申请 / 续期 / 释放，解析记录增删改，配额与风控
 */
class DnsService
{
    public static $allTypes = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'CAA', 'SRV'];

    /** @return \app\common\dns\DnsDriver */
    public static function driverFor(array $domain)
    {
        $p = Db::name('dns_provider')->find($domain['provider_id']);
        if (!$p) {
            throw new \Exception('DNS 服务商不存在');
        }
        if (!$p['status']) {
            throw new \Exception('DNS 服务商已停用');
        }

        return DnsFactory::make($p['driver'], decrypt_json($p['config']));
    }

    public static function providerInfo(array $domain)
    {
        $p = Db::name('dns_provider')->find($domain['provider_id']);
        $all = DnsFactory::infoAll();

        return $p && isset($all[$p['driver']]) ? $all[$p['driver']] : ['lines' => false, 'types' => self::$allTypes];
    }

    /**
     * 用户有效配额：会员等级 + 有效套餐叠加
     * subs: 可拥有子域名总数；records: 每个子域名记录数上限；types: 允许类型；smart_line: 是否可用线路
     */
    public static function quota(array $user)
    {
        $tier = TierService::current($user);
        $subs = $tier ? (int)$tier['dns_max_subdomains'] : (int)setting('dns_default_sub_quota', '1');
        $records = $tier ? (int)$tier['dns_max_records'] : (int)setting('dns_default_record_quota', '5');
        $types = $tier ? array_filter(explode(',', strtoupper($tier['dns_types']))) : ['A', 'AAAA', 'CNAME', 'TXT'];
        $smart = $tier ? (bool)$tier['dns_smart_line'] : false;
        $proxy = $tier ? !empty($tier['dns_proxy']) : false;
        $pkgs = Db::name('user_package')->where('user_id', $user['id'])->where(function ($q) {
            $q->where('expire_time', 0)->whereOr('expire_time', '>', time());
        })->select();
        foreach ($pkgs as $p) {
            $subs += (int)$p['sub_quota'];
            $records = max($records, (int)$p['record_quota']);
            $types = array_merge($types, array_filter(explode(',', strtoupper($p['types']))));
            $smart = $smart || (bool)$p['smart_line'];
            $proxy = $proxy || !empty($p['proxy']);
        }
        $types = array_values(array_unique(array_map('trim', $types)));

        return ['subs' => $subs, 'records' => $records, 'types' => $types, 'smart_line' => $smart, 'proxy' => $proxy, 'review_exempt' => $tier ? (bool)$tier['dns_review_exempt'] : false, 'packages' => $pkgs, 'tier' => $tier];
    }

    /** 某域名对用户允许的类型 */
    public static function allowedTypes(array $user, array $domain)
    {
        $q = self::quota($user);
        $types = $q['types'];
        if (trim($domain['types']) !== '') {
            $types = array_values(array_intersect($types, array_map('trim', explode(',', strtoupper($domain['types'])))));
        }
        $info = self::providerInfo($domain);
        if (!empty($info['types'])) {
            $types = array_values(array_intersect($types, $info['types']));
        }

        return $types;
    }

    /** 用户在该域名上能否开启 CDN 代理（Cloudflare 橙云） */
    public static function proxyAllowed(array $user, array $domain)
    {
        $q = self::quota($user);
        $info = self::providerInfo($domain);

        return $q['proxy'] && !empty($domain['allow_proxy']) && !empty($info['proxy']);
    }

    public static function lineAllowed(array $user, array $domain)
    {
        $q = self::quota($user);
        $info = self::providerInfo($domain);

        return $q['smart_line'] && $domain['allow_line'] && !empty($info['lines']);
    }

    /** 当前用户可见可申请的域名 */
    public static function availableDomains(array $user)
    {
        $tier = TierService::current($user);
        $rows = Db::name('dns_domain')->where('status', 1)->where('listing_status', 'active')->order('sort asc,id asc')->select();
        $out = [];
        foreach ($rows as $d) {
            if (trim($d['tier_ids']) !== '') {
                $ids = array_map('intval', explode(',', $d['tier_ids']));
                if (!$tier || !in_array((int)$tier['id'], $ids, true)) {
                    continue;
                }
            }
            $out[] = $d;
        }

        return $out;
    }

    /** 校验前缀 */
    public static function validatePrefix(array $domain, $prefix)
    {
        $prefix = strtolower(trim($prefix));
        $min = max(1, (int)$domain['min_len']);
        $max = min(63, max($min, (int)$domain['max_len']));
        if (strlen($prefix) < $min || strlen($prefix) > $max) {
            throw new \Exception("前缀长度需在 {$min}-{$max} 个字符之间");
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $prefix)) {
            throw new \Exception('前缀只能包含小写字母、数字和连字符，且不能以连字符开头或结尾');
        }
        if (setting('dns_allow_pure_number', '1') !== '1' && ctype_digit($prefix)) {
            throw new \Exception('不允许纯数字前缀');
        }
        if (strpos($prefix, 'xn--') === 0) {
            throw new \Exception('不支持该前缀');
        }
        $reserved = array_filter(array_map('trim', preg_split('/[,\s]+/', strtolower(setting('dns_reserved_prefixes', 'www,mail,ns1,ns2,admin,api,root,ftp,smtp,pop,imap,blog,cdn,static') . ',' . $domain['reserved']))));
        if (in_array($prefix, $reserved, true) || $prefix === MarketService::VERIFY_HOST) {
            throw new \Exception('该前缀为保留前缀，不可注册');
        }
        if (!empty($domain['id']) && MarketService::nameProtected($domain['id'], $prefix)) {
            throw new \Exception('该前缀已被域名原有解析占用，不可注册');
        }

        return $prefix;
    }

    public static function checkAvailable(array $domain, $prefix, $live = false)
    {
        $prefix = self::validatePrefix($domain, $prefix);
        $fqdn = $prefix . '.' . $domain['domain'];
        if (Db::name('dns_subdomain')->where('fqdn', $fqdn)->count()) {
            throw new \Exception('该子域名已被注册');
        }
        $hit = RiskService::check('prefix', $prefix);
        if ($hit && $hit['action'] === 'deny') {
            throw new \Exception('该前缀不可注册（命中风控规则）');
        }
        if ((int)$domain['stock'] > 0 && Db::name('dns_subdomain')->where('domain_id', $domain['id'])->count() >= (int)$domain['stock']) {
            throw new \Exception('该域名的子域名已售罄');
        }
        // 现场核对服务商：已存在同名记录（非本系统创建）则拒绝，绝不覆盖原有解析
        if ($live && setting('dns_live_conflict_check', '1') === '1') {
            try {
                $exists = self::driverFor($domain)->getRecords($domain['domain'], $domain['remote_id'], $prefix);
            } catch (\Exception $e) {
                throw new \Exception('暂时无法连接该域名的 DNS 服务商，请稍后再试');
            }
            if ($exists) {
                throw new \Exception('该前缀在服务商处已有解析记录，不可注册');
            }
        }

        return ['prefix' => $prefix, 'fqdn' => $fqdn, 'review' => $hit && $hit['action'] === 'review'];
    }

    /**
     * 注册子域名
     * @param bool $paid 是否为已付费订单交付
     */
    public static function registerSub(array $user, $domainId, $prefix, $paid = false, $orderId = 0)
    {
        $domain = Db::name('dns_domain')->find($domainId);
        if (!$domain || !$domain['status'] || $domain['listing_status'] !== 'active') {
            throw new \Exception('该域名暂不开放');
        }
        $avail = self::checkAvailable($domain, $prefix, true);
        if (!$paid) {
            if ((float)$domain['price'] > 0) {
                throw new \Exception('该域名为付费域名，请下单购买');
            }
            $allowedIds = array_column(self::availableDomains($user), 'id');
            if (!in_array($domain['id'], $allowedIds)) {
                throw new \Exception('您的会员等级不能申请该域名');
            }
            $q = self::quota($user);
            $own = Db::name('dns_subdomain')->where('user_id', $user['id'])->where('status', 'in', ['active', 'review', 'suspended'])->count();
            if ($own >= $q['subs']) {
                throw new \Exception('子域名数量已达上限（' . $q['subs'] . '），可升级会员或购买套餐');
            }
            if ((int)$domain['max_per_user'] > 0) {
                $c = Db::name('dns_subdomain')->where('user_id', $user['id'])->where('domain_id', $domain['id'])->where('status', 'in', ['active', 'review', 'suspended'])->count();
                if ($c >= (int)$domain['max_per_user']) {
                    throw new \Exception('该域名每个用户最多注册 ' . $domain['max_per_user'] . ' 个');
                }
            }
            if (RiskService::tooFrequent('dns.sub.register', (int)setting('risk_sub_per_day', '10'), 86400, false, $user['id'])) {
                RiskService::alert('子域名注册过于频繁', ['user' => $user['username']]);
                throw new \Exception('今日注册次数过多，请明天再试');
            }
        }
        $q = self::quota($user);
        $review = ($domain['require_review'] || $avail['review']) && !$q['review_exempt'];
        $now = time();
        $expire = (int)$domain['period_days'] > 0 ? $now + (int)$domain['period_days'] * 86400 : 0;
        $id = Db::name('dns_subdomain')->insertGetId([
            'user_id' => $user['id'], 'domain_id' => $domain['id'], 'order_id' => (int)$orderId, 'prefix' => $avail['prefix'], 'fqdn' => $avail['fqdn'],
            'status' => $review ? 'review' : 'active', 'expire_time' => $expire, 'create_time' => $now, 'update_time' => $now,
        ]);
        Db::name('dns_domain')->where('id', $domain['id'])->setInc('sold');
        audit('dns.sub.register', $avail['fqdn'], ['review' => $review, 'paid' => $paid], 'info', 'user', $user['id'], $user['username']);
        if ($review) {
            Notify::admin('dns_review', ['username' => $user['username'], 'fqdn' => $avail['fqdn']]);
        }

        return Db::name('dns_subdomain')->find($id);
    }

    public static function renewSub($subId)
    {
        $s = Db::name('dns_subdomain')->find($subId);
        $d = Db::name('dns_domain')->find($s['domain_id']);
        if (!$s || !$d) {
            throw new \Exception('子域名不存在');
        }
        $days = max(1, (int)$d['period_days']);
        $base = max(time(), (int)$s['expire_time']);
        $up = ['expire_time' => $base + $days * 86400, 'remind_time' => 0, 'update_time' => time()];
        Db::name('dns_subdomain')->where('id', $subId)->update($up);
        // 宽限期内续期：自动恢复因到期暂停的解析
        if ($s['status'] === 'expired' && setting('lc_auto_unsuspend', '1') === '1') {
            self::resumeSub($subId);
        } elseif ($s['status'] === 'expired') {
            Db::name('dns_subdomain')->where('id', $subId)->update(['status' => 'active']);
        }
        audit('dns.sub.renew', $s['fqdn'], ['days' => $days], 'info', 'system', 0, 'deliver');

        return true;
    }

    /** 记录在服务商处的名称（相对 zone） */
    public static function zoneName(array $sub, $host)
    {
        $host = strtolower(trim($host));

        return ($host === '' || $host === '@') ? $sub['prefix'] : $host . '.' . $sub['prefix'];
    }

    /** 校验记录 */
    public static function validateRecord(array $user, array $sub, array $domain, $host, $type, $value, $line, $ttl, $mx, $excludeId = 0, $proxied = false)
    {
        $type = strtoupper(trim($type));
        $host = strtolower(trim($host));
        $value = trim($value);
        if ($host === '') {
            $host = '@';
        }
        if ($host !== '@' && !preg_match('/^(\*|[a-z0-9_]([a-z0-9_-]*[a-z0-9])?)(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9])?)*$/', $host)) {
            throw new \Exception('主机记录格式不正确');
        }
        if (strlen(self::zoneName($sub, $host) . '.' . $domain['domain']) > 253) {
            throw new \Exception('主机记录过长');
        }
        if ($host === '*' && setting('dns_allow_wildcard', '0') !== '1') {
            throw new \Exception('不允许泛解析');
        }
        if (!in_array($type, self::allowedTypes($user, $domain), true)) {
            throw new \Exception('当前套餐不支持 ' . $type . ' 记录');
        }
        switch ($type) {
            case 'A':
                if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new \Exception('A 记录值必须是 IPv4 地址');
                }
                break;
            case 'AAAA':
                if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new \Exception('AAAA 记录值必须是 IPv6 地址');
                }
                break;
            case 'CNAME':
            case 'MX':
            case 'NS':
                $value = rtrim(strtolower($value), '.');
                if (!preg_match('/^([a-z0-9_]([a-z0-9_-]*[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $value)) {
                    throw new \Exception($type . ' 记录值必须是有效域名');
                }
                if ($type === 'CNAME' && $value === strtolower(self::zoneName($sub, $host) . '.' . $domain['domain'])) {
                    throw new \Exception('CNAME 不能指向自身');
                }
                break;
            case 'TXT':
                if ($value === '' || strlen($value) > 255) {
                    throw new \Exception('TXT 记录值长度需在 1-255 之间');
                }
                break;
            case 'CAA':
                if (!preg_match('/^\d{1,3}\s+(issue|issuewild|iodef)\s+"?[^"]+"?$/i', $value)) {
                    throw new \Exception('CAA 记录格式：0 issue "letsencrypt.org"');
                }
                break;
            case 'SRV':
                if (!preg_match('/^\d+\s+\d+\s+\d+\s+\S+$/', $value)) {
                    throw new \Exception('SRV 记录格式：权重 优先级 端口 目标');
                }
                break;
        }
        if (in_array($type, ['A', 'AAAA'], true) && setting('risk_block_private_ip', '1') === '1' && RiskService::isPrivateIp($value)) {
            throw new \Exception('不允许解析到内网或保留地址');
        }
        $hit = RiskService::check('value', $value);
        if ($hit && $hit['action'] === 'deny') {
            RiskService::alert('解析记录命中风控', ['user' => $user['username'], 'fqdn' => $sub['fqdn'], 'value' => $value, 'rule' => $hit['rule']['pattern']]);
            throw new \Exception('该记录值不允许使用（命中风控规则）');
        }
        // 线路
        if ($line !== 'default') {
            if (!self::lineAllowed($user, $domain)) {
                $line = 'default';
            } elseif (!isset(\app\common\dns\DnsDriver::$lineLabels[$line])) {
                throw new \Exception('线路不正确');
            }
        }
        $minTtl = max(1, (int)setting('dns_min_ttl', '600'));
        $ttl = (int)$ttl ?: (int)$domain['default_ttl'];
        $ttl = max($minTtl, min(86400, $ttl));
        $mx = max(1, min(65535, (int)$mx ?: 10));
        // CNAME 冲突
        $same = Db::name('dns_record')->where('sub_id', $sub['id'])->where('host', $host)->where('id', '<>', $excludeId)->column('type');
        if ($type === 'CNAME' && count($same) > 0) {
            throw new \Exception('该主机记录已存在其他记录，不能再添加 CNAME');
        }
        if ($type !== 'CNAME' && in_array('CNAME', $same, true)) {
            throw new \Exception('该主机记录已存在 CNAME，不能再添加其他记录');
        }
        $dup = Db::name('dns_record')->where('sub_id', $sub['id'])->where('host', $host)->where('type', $type)->where('value', $value)->where('line', $line)->where('id', '<>', $excludeId)->count();
        if ($dup) {
            throw new \Exception('相同的记录已存在');
        }
        $proxied = $proxied && in_array($type, ['A', 'AAAA', 'CNAME'], true);
        if ($proxied && !self::proxyAllowed($user, $domain)) {
            throw new \Exception('当前套餐或该域名不支持开启 CDN 代理');
        }

        return [$host, $type, $value, $line, $ttl, $mx, $hit && $hit['action'] === 'review', $proxied ? 1 : 0];
    }

    protected static function ownedSub(array $user, $subId)
    {
        $sub = Db::name('dns_subdomain')->where('id', $subId)->where('user_id', $user['id'])->find();
        if (!$sub) {
            throw new \Exception('子域名不存在');
        }
        if ($sub['status'] !== 'active') {
            throw new \Exception('子域名当前状态（' . strip_tags(status_badge($sub['status'])) . '）不能管理解析');
        }
        if ($sub['expire_time'] && $sub['expire_time'] < time()) {
            throw new \Exception('子域名已过期，请先续期');
        }

        return $sub;
    }

    protected static function opLimit(array $user)
    {
        if (RiskService::tooFrequent('dns.record.op', (int)setting('risk_record_ops_per_min', '20'), 60, false, $user['id'])) {
            throw new \Exception('操作过于频繁，请稍后再试');
        }
    }

    /** 添加解析 */
    public static function addRecord(array $user, $subId, $host, $type, $value, $line = 'default', $ttl = 600, $mx = 10, $byAdmin = false, $proxied = false)
    {
        $sub = $byAdmin ? Db::name('dns_subdomain')->find($subId) : self::ownedSub($user, $subId);
        if (!$byAdmin) {
            self::opLimit($user);
        }
        $domain = Db::name('dns_domain')->find($sub['domain_id']);
        $owner = $byAdmin ? Db::name('user')->find($sub['user_id']) : $user;
        $q = self::quota($owner);
        $count = Db::name('dns_record')->where('sub_id', $sub['id'])->count();
        if (!$byAdmin && $count >= $q['records']) {
            throw new \Exception('解析记录数已达上限（' . $q['records'] . '）');
        }
        list($host, $type, $value, $line, $ttl, $mx, $review, $proxied) = self::validateRecord($owner, $sub, $domain, $host, $type, $value, $line, $ttl, $mx, 0, $proxied);
        $name = self::zoneName($sub, $host);
        $now = time();
        $rid = Db::name('dns_record')->insertGetId([
            'sub_id' => $sub['id'], 'user_id' => $sub['user_id'], 'domain_id' => $domain['id'], 'host' => $host,
            'name' => $name, 'type' => $type, 'value' => $value, 'line' => $line, 'ttl' => $ttl, 'mx' => $mx, 'proxied' => $proxied,
            'status' => $review ? 'review' : 'pending', 'create_time' => $now, 'update_time' => $now,
        ]);
        audit('dns.record.op', $name . '.' . $domain['domain'], ['op' => 'add', 'type' => $type, 'value' => $value, 'line' => $line]);
        if ($review) {
            Notify::admin('dns_review', ['username' => $owner['username'], 'fqdn' => $name . '.' . $domain['domain'] . ' ' . $type . ' ' . $value]);

            return Db::name('dns_record')->find($rid);
        }

        return self::pushRecord($rid);
    }

    /** 把本地记录推送到服务商 */
    public static function pushRecord($rid)
    {
        $r = Db::name('dns_record')->find($rid);
        $domain = Db::name('dns_domain')->find($r['domain_id']);
        try {
            self::guard($domain, '', $r['name']);
            $drv = self::driverFor($domain);
            $drv->proxied = !empty($r['proxied']);
            $remoteId = $drv->addRecord($domain['domain'], $domain['remote_id'], $r['name'], $r['type'], $r['value'], $r['line'], $r['ttl'], $r['mx']);
            Db::name('dns_record')->where('id', $rid)->update(['remote_id' => (string)$remoteId, 'status' => 'active', 'last_error' => '', 'update_time' => time()]);
        } catch (\Exception $e) {
            Db::name('dns_record')->where('id', $rid)->delete();
            audit('dns.record.fail', $r['name'] . '.' . $domain['domain'], ['error' => $e->getMessage()], 'warn');
            throw new \Exception('解析添加失败：' . $e->getMessage());
        }

        return Db::name('dns_record')->find($rid);
    }

    public static function updateRecord(array $user, $recordId, $host, $type, $value, $line = 'default', $ttl = 600, $mx = 10, $byAdmin = false, $proxied = false)
    {
        $r = Db::name('dns_record')->find($recordId);
        if (!$r || (!$byAdmin && $r['user_id'] != $user['id'])) {
            throw new \Exception('记录不存在');
        }
        $sub = $byAdmin ? Db::name('dns_subdomain')->find($r['sub_id']) : self::ownedSub($user, $r['sub_id']);
        if (!$byAdmin) {
            self::opLimit($user);
        }
        $domain = Db::name('dns_domain')->find($r['domain_id']);
        $owner = $byAdmin ? Db::name('user')->find($r['user_id']) : $user;
        list($host, $type, $value, $line, $ttl, $mx, $review, $proxied) = self::validateRecord($owner, $sub, $domain, $host, $type, $value, $line, $ttl, $mx, $r['id'], $proxied);
        if ($review && !$byAdmin) {
            throw new \Exception('该记录值需要人工审核，请删除后重新添加');
        }
        $name = self::zoneName($sub, $host);
        self::guard($domain, $r['remote_id'], $name);
        $drv = self::driverFor($domain);
        $drv->proxied = (bool)$proxied;
        try {
            if ($r['remote_id'] !== '') {
                $drv->updateRecord($domain['domain'], $domain['remote_id'], $r['remote_id'], $name, $type, $value, $line, $ttl, $mx);
                $newRemote = $r['remote_id'];
                // 无记录 ID 的服务商（ID 由内容生成）更新后 ID 会变化：重新查找
                $list = $drv->getRecords($domain['domain'], $domain['remote_id'], $name);
                foreach ($list as $x) {
                    if ($x['type'] === $type && $x['value'] === $value && $x['name'] === $name) {
                        $newRemote = $x['id'];
                        break;
                    }
                }
            } else {
                $newRemote = $drv->addRecord($domain['domain'], $domain['remote_id'], $name, $type, $value, $line, $ttl, $mx);
            }
        } catch (\Exception $e) {
            throw new \Exception('解析修改失败：' . $e->getMessage());
        }
        Db::name('dns_record')->where('id', $r['id'])->update(['host' => $host, 'name' => $name, 'type' => $type, 'value' => $value, 'line' => $line, 'ttl' => $ttl, 'mx' => $mx, 'proxied' => $proxied, 'remote_id' => (string)$newRemote, 'status' => 'active', 'update_time' => time()]);
        audit('dns.record.op', $name . '.' . $domain['domain'], ['op' => 'update', 'type' => $type, 'value' => $value, 'old' => $r['value']]);

        return true;
    }

    public static function deleteRecord(array $user, $recordId, $byAdmin = false, $silent = false)
    {
        $r = Db::name('dns_record')->find($recordId);
        if (!$r || (!$byAdmin && $r['user_id'] != $user['id'])) {
            throw new \Exception('记录不存在');
        }
        if (!$byAdmin) {
            $sub = Db::name('dns_subdomain')->where('id', $r['sub_id'])->where('user_id', $user['id'])->find();
            if (!$sub) {
                throw new \Exception('记录不存在');
            }
            self::opLimit($user);
        }
        $domain = Db::name('dns_domain')->find($r['domain_id']);
        if ($r['remote_id'] !== '' && MarketService::isProtected($domain['id'], $r['remote_id'])) {
            // 绝不删除受保护的原有记录，仅移除本地关联
            audit('dns.protect.block', $r['name'] . '.' . $domain['domain'], ['op' => 'delete', 'remote_id' => $r['remote_id']], 'danger');
            $r['remote_id'] = '';
        }
        if ($r['remote_id'] !== '') {
            try {
                self::driverFor($domain)->deleteRecord($domain['domain'], $domain['remote_id'], $r['remote_id']);
            } catch (\Exception $e) {
                if (!$silent) {
                    throw new \Exception('解析删除失败：' . $e->getMessage());
                }
            }
        }
        Db::name('dns_record')->where('id', $r['id'])->delete();
        audit('dns.record.op', $r['name'] . '.' . $domain['domain'], ['op' => 'delete', 'type' => $r['type'], 'value' => $r['value']]);

        return true;
    }

    /** 释放子域名（删除全部记录） */
    public static function releaseSub($subId, $reason = 'user')
    {
        $sub = Db::name('dns_subdomain')->find($subId);
        if (!$sub) {
            return false;
        }
        foreach (Db::name('dns_record')->where('sub_id', $subId)->select() as $r) {
            self::deleteRecord([], $r['id'], true, true);
        }
        Db::name('dns_subdomain')->where('id', $subId)->delete();
        audit('dns.sub.release', $sub['fqdn'], ['reason' => $reason], 'warn');

        return true;
    }

    /** 暂停：从服务商删除记录但保留本地数据，便于恢复 */
    public static function suspendSub($subId, $reason = '')
    {
        $sub = Db::name('dns_subdomain')->find($subId);
        $domain = Db::name('dns_domain')->find($sub['domain_id']);
        $drv = self::driverFor($domain);
        foreach (Db::name('dns_record')->where('sub_id', $subId)->where('status', 'active')->select() as $r) {
            try {
                if ($r['remote_id'] !== '' && !MarketService::isProtected($domain['id'], $r['remote_id'])) {
                    $drv->deleteRecord($domain['domain'], $domain['remote_id'], $r['remote_id']);
                }
            } catch (\Exception $e) {
            }
            Db::name('dns_record')->where('id', $r['id'])->update(['status' => 'paused', 'remote_id' => '']);
        }
        Db::name('dns_subdomain')->where('id', $subId)->update(['status' => 'suspended', 'remark' => mb_substr($reason, 0, 250), 'update_time' => time()]);
        audit('dns.sub.suspend', $sub['fqdn'], ['reason' => $reason], 'warn');
    }

    public static function resumeSub($subId)
    {
        $sub = Db::name('dns_subdomain')->find($subId);
        Db::name('dns_subdomain')->where('id', $subId)->update(['status' => 'active', 'update_time' => time()]);
        $errors = [];
        foreach (Db::name('dns_record')->where('sub_id', $subId)->where('status', 'in', ['paused', 'review'])->select() as $r) {
            try {
                self::pushRecord($r['id']);
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        audit('dns.sub.resume', $sub['fqdn'], ['errors' => $errors]);

        return $errors;
    }

    /**
     * 原有解析保护：受保护的记录 ID 不能被修改，名称不能与受保护记录冲突
     */
    public static function guard(array $domain, $remoteId, $name)
    {
        if ($remoteId !== '' && MarketService::isProtected($domain['id'], $remoteId)) {
            audit('dns.protect.block', $name . '.' . $domain['domain'], ['remote_id' => $remoteId], 'danger');
            throw new \Exception('该记录属于域名原有解析，受保护不可修改');
        }
        if (MarketService::nameProtected($domain['id'], $name)) {
            audit('dns.protect.block', $name . '.' . $domain['domain'], ['op' => 'name'], 'danger');
            throw new \Exception('该主机名与域名原有解析冲突，受保护不可使用');
        }
    }

    /** 开通/续费 DNS 套餐 */
    public static function grantPackage(array $user, $packageId, $userPackageId = 0, $orderId = 0)
    {
        $p = Db::name('dns_package')->find($packageId);
        if (!$p) {
            throw new \Exception('套餐不存在');
        }
        $days = (int)$p['days'];
        if ($userPackageId) {
            $up = Db::name('user_package')->where('id', $userPackageId)->where('user_id', $user['id'])->find();
            if ($up) {
                $base = max(time(), (int)$up['expire_time']);
                Db::name('user_package')->where('id', $up['id'])->update(['expire_time' => $days > 0 ? $base + $days * 86400 : 0]);

                return $up['id'];
            }
        }

        return Db::name('user_package')->insertGetId([
            'user_id' => $user['id'], 'package_id' => $p['id'], 'order_id' => (int)$orderId, 'name' => $p['name'], 'sub_quota' => $p['sub_quota'],
            'record_quota' => $p['record_quota'], 'types' => $p['types'], 'smart_line' => $p['smart_line'], 'proxy' => isset($p['proxy']) ? (int)$p['proxy'] : 0,
            'expire_time' => $days > 0 ? time() + $days * 86400 : 0, 'create_time' => time(),
        ]);
    }
}
