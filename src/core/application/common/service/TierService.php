<?php

namespace app\common\service;

use think\Db;

/**
 * 会员分级：等级决定折扣、子域名数量、记录数、可用记录类型、智能线路、免审核
 */
class TierService
{
    public static function defaultTier()
    {
        static $t = null;
        if ($t === null) {
            $t = Db::name('user_tier')->where('is_default', 1)->where('status', 1)->find();
            if (!$t) {
                $t = Db::name('user_tier')->where('status', 1)->order('level asc')->find();
            }
            if (!$t) {
                $t = false;
            }
        }

        return $t ?: null;
    }

    /** 用户当前有效等级 */
    public static function current(array $user)
    {
        if (!empty($user['tier_id'])) {
            if (empty($user['tier_expire']) || $user['tier_expire'] > time()) {
                $t = Db::name('user_tier')->where('id', $user['tier_id'])->where('status', 1)->find();
                if ($t) {
                    return $t;
                }
            }
        }

        return self::defaultTier();
    }

    /** 开通/续期等级 */
    public static function grant(array $user, $tierId)
    {
        $t = Db::name('user_tier')->find($tierId);
        if (!$t) {
            throw new \Exception('会员等级不存在');
        }
        $days = (int)$t['period_days'];
        if ($days <= 0) {
            $expire = 0; // 永久
        } else {
            $base = ($user['tier_id'] == $tierId && $user['tier_expire'] > time()) ? $user['tier_expire'] : time();
            $expire = $base + $days * 86400;
        }
        Db::name('user')->where('id', $user['id'])->update(['tier_id' => $tierId, 'tier_expire' => $expire, 'update_time' => time()]);
        audit('tier.grant', '用户#' . $user['id'], ['tier' => $t['name'], 'expire' => $expire ? date('Y-m-d', $expire) : '永久'], 'info', 'system', 0, 'deliver');

        return true;
    }
}
