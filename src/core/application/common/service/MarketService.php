<?php

namespace app\common\service;

use think\Db;
use app\common\dns\DnsFactory;
use app\common\lib\Notify;

/**
 * 域名市场：用户发布自有域名销售子域名
 *
 * 流程：用户添加自己的 DNS 凭据 → 选择域名提交上架 → 系统生成 TXT 验证记录（_idcdns-verify）
 *       → 用户在自己的 DNS 处添加该 TXT（或在支持自动写入时由系统读取校验）→ 验证所有权
 *       → 快照全部现有记录（受保护，名字自动保留，系统永不修改/删除）→ 待管理员审核（可设免审）→ 上架
 * 售出：售价 - 平台佣金 = 卖家收入，计入卖家余额；可申请提现（后台审核）
 * 凭据失效：计划任务巡检，自动暂停上架并通知卖家
 */
class MarketService
{
    const VERIFY_HOST = '_idcdns-verify';

    public static function enabled()
    {
        return setting('market_enable', '1') === '1';
    }

    /** 佣金比例 %：域名单独设置 > 卖家会员等级 > 全局 */
    public static function commissionRate(array $domain)
    {
        if ((float)$domain['commission_rate'] >= 0) {
            return min(100, (float)$domain['commission_rate']);
        }
        $seller = Db::name('user')->find($domain['user_id']);
        if ($seller) {
            $tier = TierService::current($seller);
            if ($tier && isset($tier['commission_rate']) && (float)$tier['commission_rate'] >= 0) {
                return min(100, (float)$tier['commission_rate']);
            }
        }

        return max(0, min(100, (float)setting('market_commission_rate', '10')));
    }

    /** 用户自有 DNS 凭据 */
    public static function userProviders($userId)
    {
        return Db::name('dns_provider')->where('user_id', $userId)->order('id desc')->select();
    }

    /** 保存用户 DNS 凭据（先校验可用） */
    public static function saveProvider(array $user, $driver, array $config, $name, $id = 0)
    {
        $all = DnsFactory::infoAll();
        if (!isset($all[$driver])) {
            throw new \Exception('不支持的 DNS 服务商');
        }
        $allowed = array_filter(explode(',', setting('market_drivers', '')));
        if ($allowed && !in_array($driver, $allowed, true)) {
            throw new \Exception('平台暂不支持使用该服务商发布域名');
        }
        $old = [];
        if ($id) {
            $row = Db::name('dns_provider')->where('id', $id)->where('user_id', $user['id'])->find();
            if (!$row) {
                throw new \Exception('凭据不存在');
            }
            $old = decrypt_json($row['config']);
        }
        $cfg = [];
        foreach ($all[$driver]['fields'] as $f) {
            $v = isset($config[$f['key']]) ? trim((string)$config[$f['key']]) : '';
            if ($v === '' && !empty($f['secret']) && isset($old[$f['key']])) {
                $v = $old[$f['key']]; // 留空表示不修改
            }
            if ($f['key'] === 'endpoint') {
                // 用户凭据禁止自定义接口地址（防 SSRF），只使用管理员在「系统设置 → 域名市场」配置的反向代理地址
                $v = trim((string)setting('market_endpoint_' . $driver, ''));
            }
            $cfg[$f['key']] = $v;
        }
        $drv = DnsFactory::make($driver, $cfg);
        try {
            $drv->check();
        } catch (\Exception $e) {
            throw new \Exception('凭据校验失败：' . $e->getMessage());
        }
        $data = ['name' => mb_substr($name ?: $all[$driver]['name'], 0, 100), 'driver' => $driver, 'config' => encrypt_json($cfg), 'status' => 1, 'user_id' => $user['id'], 'last_test_time' => time(), 'last_test_msg' => '校验通过'];
        if ($id) {
            Db::name('dns_provider')->where('id', $id)->update($data);
            // 凭据修复后自动恢复暂停的域名
            foreach (Db::name('dns_domain')->where('provider_id', $id)->where('listing_status', 'paused')->select() as $d) {
                Db::name('dns_domain')->where('id', $d['id'])->update(['listing_status' => 'active', 'pause_reason' => '', 'status' => 1]);
            }
        } else {
            $data['create_time'] = time();
            $id = Db::name('dns_provider')->insertGetId($data);
        }
        audit('market.provider.save', $data['name'], ['driver' => $driver], 'info');

        return $id;
    }

    /** 列出凭据下可发布的域名 */
    public static function remoteDomains(array $user, $providerId)
    {
        $p = Db::name('dns_provider')->where('id', $providerId)->where('user_id', $user['id'])->find();
        if (!$p) {
            throw new \Exception('凭据不存在');
        }

        return DnsFactory::make($p['driver'], decrypt_json($p['config']))->getDomains();
    }

    /** 提交上架 */
    public static function submit(array $user, $providerId, $remoteId, $domainName, array $opt)
    {
        if (!self::enabled()) {
            throw new \Exception('平台暂未开放域名发布');
        }
        $p = Db::name('dns_provider')->where('id', $providerId)->where('user_id', $user['id'])->find();
        if (!$p) {
            throw new \Exception('凭据不存在');
        }
        $domainName = strtolower(trim($domainName, " .\t"));
        if (!preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domainName)) {
            throw new \Exception('域名格式不正确');
        }
        $remote = null;
        foreach (self::remoteDomains($user, $providerId) as $d) {
            if ($d['domain'] === $domainName && ($remoteId === '' || (string)$d['id'] === (string)$remoteId)) {
                $remote = $d;
                break;
            }
        }
        if (!$remote) {
            throw new \Exception('在您的 DNS 账户中没有找到该域名');
        }
        if (Db::name('dns_domain')->where('domain', $domainName)->count()) {
            throw new \Exception('该域名已在平台上（如需转移请联系客服）');
        }
        $hit = RiskService::check('prefix', explode('.', $domainName)[0]);
        if ($hit && $hit['action'] === 'deny') {
            throw new \Exception('该域名不允许发布（命中风控规则）');
        }
        $maxPerUser = (int)setting('market_max_domains_per_user', '10');
        if ($maxPerUser > 0 && Db::name('dns_domain')->where('user_id', $user['id'])->count() >= $maxPerUser) {
            throw new \Exception('每个用户最多发布 ' . $maxPerUser . ' 个域名');
        }
        $data = self::listingFields($opt);
        $data += [
            'provider_id' => $providerId, 'user_id' => $user['id'], 'domain' => $domainName, 'remote_id' => (string)$remote['id'],
            'listing_status' => 'pending', 'status' => 0, 'verify_token' => 'idcdns-' . bin2hex(random_bytes(12)), 'verified' => 0,
            'commission_rate' => -1, 'create_time' => time(), 'sort' => 100,
        ];
        $id = Db::name('dns_domain')->insertGetId($data);
        audit('market.domain.submit', $domainName, ['provider' => $p['driver']], 'info');

        return Db::name('dns_domain')->find($id);
    }

    /** 卖家可编辑的销售参数 */
    public static function listingFields(array $opt)
    {
        $types = array_values(array_intersect(array_map('strtoupper', (array)(isset($opt['types']) ? $opt['types'] : [])), DnsService::$allTypes));
        $price = max(0, round((float)(isset($opt['price']) ? $opt['price'] : 0), 2));
        $maxPrice = (float)setting('market_max_price', '9999');
        if ($maxPrice > 0 && $price > $maxPrice) {
            throw new \Exception('售价不能超过 ' . $maxPrice);
        }
        $reserved = isset($opt['reserved']) ? (string)$opt['reserved'] : '';
        $reserved = implode(',', array_filter(array_map(function ($x) {
            return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($x)));
        }, preg_split('/[,\s]+/', $reserved))));

        return [
            'price' => $price,
            'period_days' => max(0, min(3650, (int)(isset($opt['period_days']) ? $opt['period_days'] : 365))),
            'types' => implode(',', $types ?: ['A', 'AAAA', 'CNAME', 'TXT']),
            'min_len' => max(1, min(63, (int)(isset($opt['min_len']) ? $opt['min_len'] : 3))),
            'max_len' => max(1, min(63, (int)(isset($opt['max_len']) ? $opt['max_len'] : 32))),
            'reserved' => mb_substr($reserved, 0, 5000),
            'stock' => max(0, (int)(isset($opt['stock']) ? $opt['stock'] : 0)),
            'max_per_user' => max(0, (int)(isset($opt['max_per_user']) ? $opt['max_per_user'] : 0)),
            'default_ttl' => max(60, min(86400, (int)(isset($opt['default_ttl']) ? $opt['default_ttl'] : 600))),
            'allow_proxy' => !empty($opt['allow_proxy']) ? 1 : 0,
            'description' => mb_substr(trim((string)(isset($opt['description']) ? $opt['description'] : '')), 0, 250),
        ];
    }

    /** 卖家修改销售参数 */
    public static function update(array $user, $domainId, array $opt)
    {
        $d = Db::name('dns_domain')->where('id', $domainId)->where('user_id', $user['id'])->find();
        if (!$d) {
            throw new \Exception('域名不存在');
        }
        $data = self::listingFields($opt);
        // 保护记录名称永远保留
        $data['reserved'] = self::mergeReserved($data['reserved'], self::protectedPrefixes($d['id']));
        Db::name('dns_domain')->where('id', $d['id'])->update($data);
        audit('market.domain.update', $d['domain'], $data, 'info');

        return true;
    }

    /** 校验所有权：读取 _idcdns-verify TXT */
    public static function verify(array $user, $domainId)
    {
        $d = Db::name('dns_domain')->where('id', $domainId)->where('user_id', $user['id'])->find();
        if (!$d) {
            throw new \Exception('域名不存在');
        }
        if ($d['verified']) {
            return $d;
        }
        $drv = DnsService::driverFor($d);
        $found = false;
        foreach ($drv->getRecords($d['domain'], $d['remote_id'], self::VERIFY_HOST) as $r) {
            if ($r['type'] === 'TXT' && $r['name'] === self::VERIFY_HOST && trim($r['value'], '"') === $d['verify_token']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \Exception('未找到验证记录：请在您的 DNS 中添加 TXT 记录 ' . self::VERIFY_HOST . '.' . $d['domain'] . ' ，值为 ' . $d['verify_token']);
        }
        $count = self::snapshot($d);
        $auto = setting('market_auto_approve', '0') === '1';
        Db::name('dns_domain')->where('id', $d['id'])->update([
            'verified' => 1,
            'listing_status' => $auto ? 'active' : 'pending',
            'status' => $auto ? 1 : 0,
            'last_check_time' => time(),
        ]);
        audit('market.domain.verified', $d['domain'], ['protected' => $count, 'auto_approve' => $auto], 'info');
        if (!$auto) {
            Notify::admin('dns_review', ['username' => $user['username'], 'fqdn' => '[发布域名] ' . $d['domain']]);
        }

        return Db::name('dns_domain')->find($d['id']);
    }

    /**
     * 快照现有记录为受保护记录，并把其名称加入保留前缀
     * 平台域名导入时也调用（保护站长原有解析）
     */
    public static function snapshot(array $d)
    {
        $drv = DnsService::driverFor($d);
        $records = $drv->getRecords($d['domain'], $d['remote_id']);
        $now = time();
        $existing = Db::name('dns_protected')->where('domain_id', $d['id'])->column('remote_id');
        $n = 0;
        foreach ($records as $r) {
            if (in_array((string)$r['id'], $existing, true)) {
                continue;
            }
            // 系统自己创建的记录不纳入保护
            if (Db::name('dns_record')->where('domain_id', $d['id'])->where('remote_id', (string)$r['id'])->count()) {
                continue;
            }
            Db::name('dns_protected')->insert([
                'domain_id' => $d['id'], 'remote_id' => (string)$r['id'], 'name' => strtolower($r['name']),
                'type' => $r['type'], 'value' => mb_substr((string)$r['value'], 0, 490), 'create_time' => $now,
            ]);
            $n++;
        }
        $reserved = self::mergeReserved($d['reserved'], self::protectedPrefixes($d['id']));
        Db::name('dns_domain')->where('id', $d['id'])->update(['reserved' => $reserved]);

        return $n;
    }

    /** 受保护记录对应的子域名前缀（相对名最右侧标签），@ 除外 */
    public static function protectedPrefixes($domainId)
    {
        $out = [];
        foreach (Db::name('dns_protected')->where('domain_id', $domainId)->column('name') as $name) {
            $name = strtolower(trim($name));
            if ($name === '' || $name === '@') {
                continue;
            }
            $parts = explode('.', $name);
            $out[] = end($parts);
        }

        return array_values(array_unique($out));
    }

    protected static function mergeReserved($reserved, array $add)
    {
        $list = array_filter(array_map('trim', preg_split('/[,\s]+/', strtolower((string)$reserved))));
        $list = array_values(array_unique(array_merge($list, $add, [self::VERIFY_HOST])));

        return implode(',', $list);
    }

    /** 记录是否受保护（系统禁止修改/删除） */
    public static function isProtected($domainId, $remoteId)
    {
        if ((string)$remoteId === '') {
            return false;
        }

        return Db::name('dns_protected')->where('domain_id', $domainId)->where('remote_id', (string)$remoteId)->count() > 0;
    }

    /** 名称是否与受保护记录冲突（相同或位于其下级） */
    public static function nameProtected($domainId, $name)
    {
        $name = strtolower(trim($name));
        foreach (Db::name('dns_protected')->where('domain_id', $domainId)->column('name') as $p) {
            $p = strtolower($p);
            if ($p === '@' || $p === '') {
                continue;
            }
            if ($name === $p || substr($name, -strlen('.' . $p)) === '.' . $p || substr($p, -strlen('.' . $name)) === '.' . $name) {
                return true;
            }
        }

        return false;
    }

    /** 管理员审核 */
    public static function review($domainId, $approve, $reason = '')
    {
        $d = Db::name('dns_domain')->find($domainId);
        if (!$d || !$d['user_id']) {
            throw new \Exception('域名不存在');
        }
        if ($approve && !$d['verified']) {
            throw new \Exception('该域名尚未通过所有权验证');
        }
        Db::name('dns_domain')->where('id', $d['id'])->update([
            'listing_status' => $approve ? 'active' : 'rejected', 'status' => $approve ? 1 : 0, 'pause_reason' => $approve ? '' : mb_substr($reason, 0, 250),
        ]);
        $seller = Db::name('user')->find($d['user_id']);
        if ($seller) {
            Notify::user($approve ? 'dns_approved' : 'dns_rejected', $seller, ['fqdn' => '[发布域名] ' . $d['domain'], 'reason' => $reason]);
        }
        audit('market.domain.review', $d['domain'], ['approve' => $approve, 'reason' => $reason], $approve ? 'info' : 'warn');
    }

    /** 下架/暂停/恢复 */
    public static function setStatus($domainId, $status, $reason = '')
    {
        $d = Db::name('dns_domain')->find($domainId);
        if (!$d) {
            throw new \Exception('域名不存在');
        }
        Db::name('dns_domain')->where('id', $d['id'])->update([
            'listing_status' => $status, 'status' => $status === 'active' ? 1 : 0, 'pause_reason' => mb_substr($reason, 0, 250),
        ]);
        audit('market.domain.' . $status, $d['domain'], ['reason' => $reason], $status === 'active' ? 'info' : 'warn');
    }

    /** 卖家删除域名（需先无有效子域名） */
    public static function remove(array $user, $domainId)
    {
        $d = Db::name('dns_domain')->where('id', $domainId)->where('user_id', $user['id'])->find();
        if (!$d) {
            throw new \Exception('域名不存在');
        }
        if (Db::name('dns_subdomain')->where('domain_id', $d['id'])->where('status', 'in', ['active', 'review', 'suspended'])->count()) {
            throw new \Exception('该域名下仍有用户正在使用的子域名，只能下架，不能删除');
        }
        Db::name('dns_protected')->where('domain_id', $d['id'])->delete();
        Db::name('dns_domain')->where('id', $d['id'])->delete();
        audit('market.domain.remove', $d['domain'], '', 'warn');
    }

    /**
     * 售出结算（付费子域名新购/续期交付成功后调用）
     */
    public static function settle(array $order, $domainId, $subId)
    {
        $d = Db::name('dns_domain')->find($domainId);
        $gross = max(0, round((float)$order['amount'] - (float)$order['discount'], 2)); // 会员折扣后、优惠码前
        $paid = (float)$order['pay_amount'];
        // 优惠码承担方：platform（默认，卖家按折前价结算，优惠由平台承担）/ seller（按买家实付结算）
        $bearer = setting('market_coupon_bearer', 'platform') === 'seller' ? 'seller' : 'platform';
        $base = (float)$order['coupon_discount'] > 0 && $bearer === 'platform' ? $gross : $paid;
        if (!$d || !$d['user_id'] || $base <= 0) {
            return null;
        }
        if (Db::name('market_sale')->where('order_id', $order['id'])->count()) {
            return null; // 幂等
        }
        if ((int)$d['user_id'] === (int)$order['user_id']) {
            return null; // 自买自卖不结算
        }
        $amount = $base;
        $rate = self::commissionRate($d);
        $commission = round($amount * $rate / 100, 2);
        $income = round($amount - $commission, 2);
        Db::startTrans();
        try {
            Db::name('market_sale')->insert([
                'domain_id' => $d['id'], 'seller_id' => $d['user_id'], 'buyer_id' => $order['user_id'], 'sub_id' => (int)$subId,
                'order_id' => $order['id'], 'amount' => $amount, 'commission' => $commission, 'income' => $income,
                'gross_amount' => $gross, 'paid_amount' => $paid, 'base_amount' => $base, 'bearer' => $bearer, 'create_time' => time(),
            ]);
            if ($income > 0) {
                OrderService::addBalance($d['user_id'], $income, 'sale_income', '域名销售收入 ' . $d['domain'] . '（订单 ' . $order['order_no'] . '，佣金 ' . $rate . '%）');
            }
            Db::name('dns_domain')->where('id', $d['id'])->inc('income', $income)->update();
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        audit('market.sale', $d['domain'], ['order' => $order['order_no'], 'gross' => $gross, 'paid' => $paid, 'base' => $amount, 'bearer' => $bearer, 'coupon' => $order['coupon_code'], 'commission' => $commission, 'income' => $income], 'info', 'system', 0, 'deliver');

        return $income;
    }

    /** 申请提现 */
    public static function withdraw(array $user, $amount, $method, $account)
    {
        if (setting('withdraw_enable', '1') !== '1') {
            throw new \Exception('暂未开放提现');
        }
        $amount = round((float)$amount, 2);
        $min = (float)setting('withdraw_min', '10');
        if ($amount < $min) {
            throw new \Exception('最低提现金额为 ' . $min);
        }
        $methods = array_filter(array_map('trim', explode(',', setting('withdraw_methods', '支付宝,USDT-TRC20,银行卡'))));
        if (!in_array($method, $methods, true)) {
            throw new \Exception('请选择提现方式');
        }
        $account = trim($account);
        if ($account === '' || mb_strlen($account) > 200) {
            throw new \Exception('请填写收款账号');
        }
        if (Db::name('withdraw')->where('user_id', $user['id'])->where('status', 'pending')->count()) {
            throw new \Exception('您有待处理的提现申请');
        }
        // 只允许提现销售收入部分（可在后台关闭此限制）
        if (setting('withdraw_income_only', '1') === '1') {
            $earned = (float)Db::name('market_sale')->where('seller_id', $user['id'])->sum('income');
            $taken = (float)Db::name('withdraw')->where('user_id', $user['id'])->where('status', 'in', ['pending', 'approved', 'paid'])->sum('amount');
            if ($amount > round($earned - $taken, 2) + 0.001) {
                throw new \Exception('可提现的销售收入不足（可提现 ' . money(max(0, $earned - $taken)) . '）');
            }
        }
        $fee = round($amount * max(0, (float)setting('withdraw_fee_rate', '0')) / 100, 2);
        Db::startTrans();
        try {
            $ok = Db::name('user')->where('id', $user['id'])->where('balance', '>=', $amount)->setDec('balance', $amount);
            if (!$ok) {
                throw new \Exception('余额不足');
            }
            $bal = Db::name('user')->where('id', $user['id'])->value('balance');
            Db::name('balance_log')->insert(['user_id' => $user['id'], 'amount' => -$amount, 'balance' => $bal, 'type' => 'withdraw', 'remark' => '申请提现（冻结）', 'create_time' => time()]);
            $id = Db::name('withdraw')->insertGetId(['user_id' => $user['id'], 'amount' => $amount, 'fee' => $fee, 'method' => $method, 'account' => $account, 'status' => 'pending', 'create_time' => time()]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        audit('market.withdraw.apply', '提现#' . $id, ['amount' => $amount, 'method' => $method], 'info');
        Notify::admin('risk_alert', ['title' => '新提现申请', 'content' => '用户 ' . $user['username'] . ' 申请提现 ' . money($amount) . '（' . $method . '）']);

        return $id;
    }

    /** 管理员处理提现：approve=已打款，reject=驳回退回余额 */
    public static function handleWithdraw($id, $approve, $note = '')
    {
        $w = Db::name('withdraw')->find($id);
        if (!$w || $w['status'] !== 'pending') {
            throw new \Exception('提现申请不存在或已处理');
        }
        Db::startTrans();
        try {
            $aff = Db::name('withdraw')->where('id', $id)->where('status', 'pending')->update(['status' => $approve ? 'paid' : 'rejected', 'admin_note' => mb_substr($note, 0, 250), 'handle_time' => time()]);
            if (!$aff) {
                throw new \Exception('提现申请已处理');
            }
            if (!$approve) {
                OrderService::addBalance($w['user_id'], (float)$w['amount'], 'withdraw_back', '提现驳回退回' . ($note !== '' ? '：' . $note : ''));
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        audit('market.withdraw.' . ($approve ? 'paid' : 'reject'), '提现#' . $id, ['amount' => $w['amount'], 'note' => $note], 'warn');
    }

    /**
     * 巡检用户域名凭据（计划任务调用）：失效则暂停上架并通知卖家
     */
    public static function healthCheck($limit = 20)
    {
        $rows = Db::name('dns_domain')->where('user_id', '>', 0)->where('listing_status', 'in', ['active', 'paused'])
            ->where('last_check_time', '<', time() - (int)setting('market_check_interval', '3600'))
            ->order('last_check_time asc')->limit($limit)->select();
        $res = ['checked' => 0, 'paused' => 0, 'resumed' => 0];
        foreach ($rows as $d) {
            $res['checked']++;
            $ok = true;
            $err = '';
            try {
                $drv = DnsService::driverFor($d);
                $drv->check();
                $found = false;
                foreach ($drv->getDomains($d['domain']) as $z) {
                    if ($z['domain'] === $d['domain']) {
                        $found = true;
                    }
                }
                if (!$found) {
                    throw new \Exception('DNS 账户中已找不到该域名');
                }
            } catch (\Exception $e) {
                $ok = false;
                $err = $e->getMessage();
            }
            $up = ['last_check_time' => time()];
            if (!$ok && $d['listing_status'] === 'active') {
                $up += ['listing_status' => 'paused', 'status' => 0, 'pause_reason' => mb_substr('凭据失效：' . $err, 0, 250)];
                $res['paused']++;
                $seller = Db::name('user')->find($d['user_id']);
                if ($seller) {
                    Notify::user('risk_alert', $seller, ['title' => '您发布的域名已暂停销售', 'content' => '域名 ' . $d['domain'] . ' 的 DNS 凭据无法使用（' . $err . '），已暂停销售。请到用户中心「我的发布」更新凭据后自动恢复。']);
                }
                audit('market.domain.paused', $d['domain'], ['error' => $err], 'warn', 'system', 0, 'cron');
            } elseif ($ok && $d['listing_status'] === 'paused' && strpos($d['pause_reason'], '凭据失效') === 0) {
                $up += ['listing_status' => 'active', 'status' => 1, 'pause_reason' => ''];
                $res['resumed']++;
            }
            Db::name('dns_domain')->where('id', $d['id'])->update($up);
        }

        return $res;
    }
}
