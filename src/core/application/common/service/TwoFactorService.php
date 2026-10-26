<?php

namespace app\common\service;

use app\common\lib\AuthGuard;
use app\common\lib\Notify;
use app\common\lib\QrCode;
use app\common\lib\Totp;
use think\Db;

/**
 * 谷歌验证器两步验证（管理员 admin 与前台用户 user 共用）。
 *
 * - 密钥用 encrypt_str（AES-256 + HMAC，密钥在数据目录配置里）加密后存库；
 * - 每次成功都记下用掉的时间步，同一个验证码（同一时间窗）不能再用第二次；
 * - 恢复码 10 个，HMAC-SHA256（以 app_key 为密钥）哈希存储，每个只能用一次；
 * - 错误 5 次锁定 15 分钟（按账号），同一 IP 错误 20 次也锁定 15 分钟。
 */
class TwoFactorService
{
    const RECOVERY_COUNT = 10;
    const MAX_FAILS = 5;
    const MAX_IP_FAILS = 20;
    const LOCK_SECONDS = 900;
    const SETUP_TTL = 1800;
    const CODES_TTL = 1800;

    public static function table($type)
    {
        return $type === 'admin' ? 'admin' : 'user';
    }

    public static function enabled($row)
    {
        return is_array($row) && !empty($row['totp_enabled']) && !empty($row['totp_secret']);
    }

    /** 绑定时显示的发行方名称：后台账号加“后台”，避免和前台账号在验证器里混淆 */
    public static function issuer($type)
    {
        $name = trim((string)setting('twofa_issuer', ''));
        if ($name === '') {
            $name = setting('site_name', 'o2o云');
        }

        return $type === 'admin' ? $name . ' 后台' : $name;
    }

    /** 当前绑定流程的临时密钥（存在会话里，确认验证码前不入库） */
    public static function setupSecret($type, $id, $renew = false)
    {
        $key = 'twofa_setup_' . $type . '_' . (int)$id;
        $s = session($key);
        if ($renew || !is_array($s) || empty($s['secret']) || time() - (int)$s['time'] > self::SETUP_TTL) {
            $s = ['secret' => Totp::newSecret(), 'time' => time()];
            session($key, $s);
        }

        return $s['secret'];
    }

    public static function setupUri($type, array $row, $secret)
    {
        return Totp::uri(self::issuer($type), $row['username'], $secret);
    }

    /** 本地生成二维码 SVG（不经过任何外部服务） */
    public static function qrSvg($uri, $px = 220)
    {
        return QrCode::svg($uri, $px);
    }

    /**
     * 用第一次验证码确认并启用。返回明文恢复码（只在此刻可见）。
     */
    public static function enable($type, array $row, $code, $operator = '')
    {
        if (self::enabled($row)) {
            throw new \RuntimeException('已经开启了两步验证');
        }
        self::assertNotLocked($type, $row['id']);
        $secret = self::setupSecret($type, $row['id']);
        $step = Totp::matchStep($secret, $code);
        if ($step === false) {
            self::recordFail($type, $row['id']);
            throw new \RuntimeException('验证码不正确：请确认手机时间准确，并输入验证器里当前显示的 6 位数字');
        }
        Db::name(self::table($type))->where('id', $row['id'])->update([
            'totp_secret' => encrypt_str($secret),
            'totp_enabled' => 1,
            'totp_last_step' => $step,
            'totp_bind_time' => time(),
        ]);
        session('twofa_setup_' . $type . '_' . (int)$row['id'], null);
        AuthGuard::clear(self::guardKey($type, $row['id']));
        $codes = self::regenerateRecovery($type, $row['id']);
        self::log($type, $row, 'enable', $operator);
        self::notifyChange($type, $row, '开启', $operator);

        return $codes;
    }

    /**
     * 校验验证码（或恢复码）。成功返回 'totp' 或 'recovery'，失败抛出异常（含友好提示）。
     */
    public static function verify($type, array $row, $code, $allowRecovery = true)
    {
        if (!self::enabled($row)) {
            throw new \RuntimeException('该账号没有开启两步验证');
        }
        self::assertNotLocked($type, $row['id']);
        $code = strtolower(preg_replace('/[\s-]+/', '', (string)$code));
        if (preg_match('/^\d{6}$/', $code)) {
            $secret = decrypt_str($row['totp_secret']);
            $step = $secret === '' ? false : Totp::matchStep($secret, $code);
            if ($step !== false) {
                // 原子更新：只有比上次用过的时间步更新的验证码才算数（防重放）
                $n = Db::name(self::table($type))->where('id', $row['id'])->where('totp_last_step', '<', $step)->update(['totp_last_step' => $step]);
                if ($n) {
                    AuthGuard::clear(self::guardKey($type, $row['id']));

                    return 'totp';
                }
                self::recordFail($type, $row['id']);
                throw new \RuntimeException('这个验证码刚刚已经用过了，请等验证器刷新出下一个验证码再输入');
            }
        } elseif ($allowRecovery && preg_match('/^[a-z0-9]{10}$/', $code)) {
            $hash = self::recoveryHash($type, $row['id'], $code);
            $rec = Db::name('twofa_recovery')->where(['owner_type' => $type, 'owner_id' => (int)$row['id'], 'code_hash' => $hash, 'used_time' => 0])->find();
            if ($rec && Db::name('twofa_recovery')->where('id', $rec['id'])->where('used_time', 0)->update(['used_time' => time()])) {
                AuthGuard::clear(self::guardKey($type, $row['id']));
                self::log($type, $row, 'recovery_used', '', '剩余 ' . self::recoveryLeft($type, $row['id']) . ' 个');

                return 'recovery';
            }
        }
        $locked = self::recordFail($type, $row['id']);
        if ($locked) {
            throw new \RuntimeException('验证码错误次数过多，已锁定 ' . AuthGuard::waitText(self::LOCK_SECONDS) . '，请稍后再试');
        }
        $left = self::MAX_FAILS - AuthGuard::fails(self::guardKey($type, $row['id']));
        throw new \RuntimeException('验证码不正确' . ($left > 0 ? '（再错 ' . $left . ' 次将锁定 15 分钟）' : ''));
    }

    /** 生成新的恢复码（旧的全部作废），明文只保留在会话里供本次查看/下载 */
    public static function regenerateRecovery($type, $id)
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $codes = [];
        Db::name('twofa_recovery')->where(['owner_type' => $type, 'owner_id' => (int)$id])->delete();
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= $alphabet[random_int(0, $max)];
            }
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
            Db::name('twofa_recovery')->insert(['owner_type' => $type, 'owner_id' => (int)$id, 'code_hash' => self::recoveryHash($type, $id, $c), 'used_time' => 0, 'create_time' => time()]);
        }
        session('twofa_codes_' . $type . '_' . (int)$id, ['codes' => $codes, 'time' => time()]);

        return $codes;
    }

    /** 取出待保存的明文恢复码；$consume=true 时取出后立即清除（“只能下载一次”） */
    public static function pendingCodes($type, $id, $consume = false)
    {
        $key = 'twofa_codes_' . $type . '_' . (int)$id;
        $s = session($key);
        if (!is_array($s) || empty($s['codes']) || time() - (int)$s['time'] > self::CODES_TTL) {
            session($key, null);

            return [];
        }
        if ($consume) {
            session($key, null);
        }

        return $s['codes'];
    }

    public static function discardCodes($type, $id)
    {
        session('twofa_codes_' . $type . '_' . (int)$id, null);
    }

    public static function recoveryLeft($type, $id)
    {
        return Db::name('twofa_recovery')->where(['owner_type' => $type, 'owner_id' => (int)$id, 'used_time' => 0])->count();
    }

    /** 关闭/重置：清除密钥与恢复码。$operator 为空表示本人操作 */
    public static function disable($type, array $row, $operator = '', $reason = '')
    {
        Db::name(self::table($type))->where('id', $row['id'])->update(['totp_secret' => '', 'totp_enabled' => 0, 'totp_last_step' => 0, 'totp_bind_time' => 0]);
        Db::name('twofa_recovery')->where(['owner_type' => $type, 'owner_id' => (int)$row['id']])->delete();
        AuthGuard::clear(self::guardKey($type, $row['id']));
        self::discardCodes($type, $row['id']);
        self::log($type, $row, $operator === '' ? 'disable' : 'reset', $operator, $reason);
        self::notifyChange($type, $row, $operator === '' ? '关闭' : '被重置', $operator);
    }

    public static function lockedFor($type, $id)
    {
        return max(AuthGuard::lockedFor(self::guardKey($type, $id)), AuthGuard::lockedFor('2fa:ip:' . client_ip()));
    }

    protected static function assertNotLocked($type, $id)
    {
        $s = self::lockedFor($type, $id);
        if ($s > 0) {
            throw new \RuntimeException('验证码错误次数过多，请 ' . AuthGuard::waitText($s) . '后再试');
        }
    }

    protected static function recordFail($type, $id)
    {
        AuthGuard::fail('2fa:ip:' . client_ip(), self::MAX_IP_FAILS, self::LOCK_SECONDS);
        $locked = AuthGuard::fail(self::guardKey($type, $id), self::MAX_FAILS, self::LOCK_SECONDS);
        audit('2fa.fail', $type . '#' . (int)$id, $locked ? '已锁定' : '', $locked ? 'warn' : 'info');

        return $locked;
    }

    protected static function guardKey($type, $id)
    {
        return '2fa:' . $type . ':' . (int)$id;
    }

    protected static function recoveryHash($type, $id, $code)
    {
        $code = strtolower(preg_replace('/[\s-]+/', '', (string)$code));

        return hash_hmac('sha256', $type . ':' . (int)$id . ':' . $code, 'twofa-recovery|' . idc_conf('app_key', 'idc'));
    }

    protected static function log($type, array $row, $what, $operator = '', $detail = '')
    {
        $target = ($type === 'admin' ? '管理员 ' : '用户 ') . $row['username'] . ' #' . (int)$row['id'];
        audit('2fa.' . $type . '.' . $what, $target, trim(($operator !== '' ? '操作人：' . $operator . ' ' : '') . $detail), in_array($what, ['disable', 'reset'], true) ? 'warn' : 'info');
    }

    protected static function notifyChange($type, array $row, $action, $operator)
    {
        $vars = ['action' => $action, 'time' => date('Y-m-d H:i'), 'operator' => $operator !== '' ? $operator : '本人', 'ip' => client_ip(), '_key' => 'twofa' . time()];
        try {
            if ($type === 'admin') {
                $vars['username'] = $row['username'];
                Notify::toEmail('twofa_changed', isset($row['email']) ? $row['email'] : '', $vars);
            } else {
                Notify::user('twofa_changed', $row, $vars);
            }
        } catch (\Exception $e) {
            // 通知失败不影响操作本身
        }
    }
}
