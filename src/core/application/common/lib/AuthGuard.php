<?php

namespace app\common\lib;

use think\Db;

/**
 * 失败计数与锁定（两步验证码、找回密码验证码等）。
 * 以“键”为单位计数：例如 2fa:admin:1、2fa:ip:1.2.3.4、reset:email:a@b.com。
 * 计数在窗口期内累计，达到上限后锁定一段时间；锁定结束后重新计数。
 */
class AuthGuard
{
    /** 剩余锁定秒数，未锁定返回 0 */
    public static function lockedFor($key)
    {
        $row = Db::name('auth_guard')->where('k', self::key($key))->find();
        if (!$row || (int)$row['lock_until'] <= time()) {
            return 0;
        }

        return (int)$row['lock_until'] - time();
    }

    /**
     * 记一次失败；达到 $max 次后锁定 $lock 秒。返回 true 表示本次触发了锁定。
     */
    public static function fail($key, $max = 5, $lock = 900, $window = 900)
    {
        $k = self::key($key);
        $now = time();
        $t = config('database.prefix') . 'auth_guard';
        // 原子累加：窗口过期或上次锁定已结束时从 1 重新计数
        Db::execute(
            'INSERT INTO `' . $t . '` (`k`,`fails`,`lock_until`,`update_time`) VALUES (?,1,0,?) '
            . 'ON DUPLICATE KEY UPDATE `fails` = IF(`update_time` < ? OR (`lock_until` > 0 AND `lock_until` <= ?), 1, `fails` + 1), '
            . '`lock_until` = IF(`lock_until` > 0 AND `lock_until` <= ?, 0, `lock_until`), `update_time` = ?',
            [$k, $now, $now - (int)$window, $now, $now, $now]
        );
        $row = Db::name('auth_guard')->where('k', $k)->find();
        if ($row && (int)$row['fails'] >= (int)$max && (int)$row['lock_until'] <= $now) {
            Db::name('auth_guard')->where('k', $k)->update(['lock_until' => $now + (int)$lock]);

            return true;
        }

        return false;
    }

    /** 当前失败次数（窗口内） */
    public static function fails($key, $window = 900)
    {
        $row = Db::name('auth_guard')->where('k', self::key($key))->find();
        if (!$row || (int)$row['update_time'] < time() - $window) {
            return 0;
        }

        return (int)$row['fails'];
    }

    public static function clear($key)
    {
        Db::name('auth_guard')->where('k', self::key($key))->delete();
    }

    /** 友好的剩余时间文字 */
    public static function waitText($seconds)
    {
        $m = (int)ceil($seconds / 60);

        return $m <= 1 ? '1 分钟' : $m . ' 分钟';
    }

    /** 过长或含特殊字符的键统一做哈希，避免超出字段长度 */
    private static function key($key)
    {
        $key = (string)$key;

        return strlen($key) <= 100 ? $key : substr($key, 0, 40) . ':' . sha1($key);
    }
}
