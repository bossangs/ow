<?php

namespace app\common\service;

use app\common\lib\Notify;
use think\Db;

/**
 * 充值卡密
 *
 * - 卡密 16 位随机字符（去掉易混淆字符，约 80 bit 熵），格式 [前缀-]XXXX-XXXX-XXXX-XXXX；
 * - 数据库只保存 HMAC-SHA256(卡密, 站点密钥) 与末 4 位，泄露数据库也无法还原卡密；
 * - 明文只在生成后加密暂存，供管理员下载一次（或 24 小时内），下载后立即删除；
 * - 兑换：行锁 + 状态条件更新，同一张卡并发兑换只会成功一次；
 * - 防爆破：同一用户/IP 连续失败 N 次后要求图形验证码，达到 M 次锁定一段时间。
 */
class CardService
{
    const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 规范化用户输入：去空格/横线，转大写 */
    public static function normalize($code)
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)$code));
    }

    public static function hash($code)
    {
        return hash_hmac('sha256', self::normalize($code), 'card|' . idc_conf('app_key', 'idc'));
    }

    /**
     * 生成一批卡密
     * @param array $admin 当前管理员
     * @param array $p name note face_value qty prefix expire_date(Y-m-d) per_user_limit
     * @return int 批次 ID
     */
    public static function generate(array $admin, array $p)
    {
        $face = round((float)(isset($p['face_value']) ? $p['face_value'] : 0), 2);
        $qty = (int)(isset($p['qty']) ? $p['qty'] : 0);
        $prefix = strtoupper(trim((string)(isset($p['prefix']) ? $p['prefix'] : '')));
        $max = (int)setting('card_batch_max', '5000');
        if ($face <= 0) {
            throw new \InvalidArgumentException('面值必须大于 0');
        }
        if ($qty < 1 || $qty > $max) {
            throw new \InvalidArgumentException('数量须在 1 ~ ' . $max . ' 之间');
        }
        if ($prefix !== '' && !preg_match('/^[A-Z0-9]{1,8}$/', $prefix)) {
            throw new \InvalidArgumentException('前缀只能是 1~8 位字母或数字');
        }
        $expire = 0;
        if (!empty($p['expire_date'])) {
            $expire = strtotime($p['expire_date'] . ' 23:59:59');
            if (!$expire || $expire < time()) {
                throw new \InvalidArgumentException('过期日期无效');
            }
        }
        $codes = [];
        Db::startTrans();
        try {
            $bid = Db::name('card_batch')->insertGetId([
                'name' => mb_substr(trim((string)(isset($p['name']) ? $p['name'] : '')) ?: ('批次 ' . date('YmdHis')), 0, 100),
                'note' => mb_substr((string)(isset($p['note']) ? $p['note'] : ''), 0, 250),
                'face_value' => $face,
                'qty' => $qty,
                'prefix' => $prefix,
                'expire_time' => $expire,
                'per_user_limit' => max(0, (int)(isset($p['per_user_limit']) ? $p['per_user_limit'] : 0)),
                'export_blob' => '',
                'export_expire' => time() + 86400,
                'exported' => 0,
                'admin' => $admin['username'],
                'create_time' => time(),
            ]);
            $rows = [];
            while (count($codes) < $qty) {
                $raw = '';
                for ($i = 0; $i < 16; $i++) {
                    $raw .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
                }
                $code = ($prefix !== '' ? $prefix . '-' : '') . implode('-', str_split($raw, 4));
                $h = self::hash($code);
                if (isset($codes[$h])) {
                    continue;
                }
                $codes[$h] = $code;
                $rows[] = [
                    'batch_id' => $bid, 'code_hash' => $h, 'code_tail' => substr($raw, -4),
                    'face_value' => $face, 'status' => 'unused', 'expire_time' => $expire,
                    'used_user_id' => 0, 'used_time' => 0, 'used_ip' => '', 'create_time' => time(),
                ];
                if (count($rows) >= 500) {
                    Db::name('recharge_card')->insertAll($rows);
                    $rows = [];
                }
            }
            if ($rows) {
                Db::name('recharge_card')->insertAll($rows);
            }
            Db::name('card_batch')->where('id', $bid)->update(['export_blob' => encrypt_str(implode("\n", array_values($codes)))]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        audit('card.generate', '卡密批次#' . $bid, ['face_value' => $face, 'qty' => $qty, 'prefix' => $prefix, 'expire' => $expire], 'warn');

        return $bid;
    }

    /**
     * 导出明文（仅一次 / 24 小时内）
     * @param string $format csv|txt
     * @return array [filename, content]
     */
    public static function export(array $admin, $batchId, $format = 'csv')
    {
        $b = Db::name('card_batch')->find((int)$batchId);
        if (!$b) {
            throw new \InvalidArgumentException('批次不存在');
        }
        if ($b['export_blob'] === '' || $b['export_blob'] === null || $b['export_expire'] < time()) {
            throw new \RuntimeException('出于安全考虑，卡密明文只能在生成后 24 小时内下载一次，现已不可再导出。如遗失请禁用本批次并重新生成。');
        }
        $plain = decrypt_str($b['export_blob']);
        // 读取后立即删除明文
        Db::name('card_batch')->where('id', $b['id'])->update(['export_blob' => '', 'exported' => 1]);
        audit('card.export', '卡密批次#' . $b['id'], ['format' => $format, 'qty' => $b['qty']], 'warn');
        $codes = array_filter(explode("\n", $plain), 'strlen');
        $exp = $b['expire_time'] ? date('Y-m-d', $b['expire_time']) : '永久';
        if ($format === 'txt') {
            return ['cards_' . $b['id'] . '.txt', implode("\r\n", $codes) . "\r\n"];
        }
        $out = "\xEF\xBB\xBF" . "卡密,面值,过期时间,批次\r\n";
        foreach ($codes as $c) {
            $out .= $c . ',' . number_format((float)$b['face_value'], 2, '.', '') . ',' . $exp . ',' . str_replace(',', ' ', $b['name']) . "\r\n";
        }

        return ['cards_' . $b['id'] . '.csv', $out];
    }

    /** 是否需要图形验证码（达到失败次数阈值） */
    public static function needCaptcha($userId, $ip)
    {
        $n = (int)setting('card_captcha_after', '3');

        return self::fails('u:' . $userId) >= $n || self::fails('ip:' . $ip) >= $n;
    }

    protected static function fails($key)
    {
        $r = Db::name('redeem_fail')->where('fkey', $key)->find();
        if (!$r || $r['update_time'] < time() - 86400) {
            return 0;
        }

        return (int)$r['fails'];
    }

    protected static function lockedUntil($key)
    {
        $r = Db::name('redeem_fail')->where('fkey', $key)->find();

        return $r ? (int)$r['lock_until'] : 0;
    }

    protected static function addFail($key)
    {
        $lockAfter = (int)setting('card_lock_after', '10');
        $lockMin = (int)setting('card_lock_minutes', '30');
        $r = Db::name('redeem_fail')->where('fkey', $key)->find();
        $fails = $r && $r['update_time'] > time() - 86400 ? (int)$r['fails'] + 1 : 1;
        $lock = $fails >= $lockAfter ? time() + $lockMin * 60 : 0;
        if ($r) {
            Db::name('redeem_fail')->where('fkey', $key)->update(['fails' => $fails, 'lock_until' => $lock, 'update_time' => time()]);
        } else {
            Db::name('redeem_fail')->insert(['fkey' => $key, 'fails' => $fails, 'lock_until' => $lock, 'update_time' => time()]);
        }

        return $fails;
    }

    /**
     * 兑换
     * @param bool $captchaOk 调用方是否已校验图形验证码
     * @return float 到账金额
     */
    public static function redeem(array $user, $code, $ip, $captchaOk = false)
    {
        if (setting('card_enable', '1') !== '1') {
            throw new \RuntimeException('卡密充值未开启');
        }
        $ku = 'u:' . $user['id'];
        $ki = 'ip:' . $ip;
        $lock = max(self::lockedUntil($ku), self::lockedUntil($ki));
        if ($lock > time()) {
            throw new \RuntimeException('错误次数过多，请在 ' . date('H:i', $lock) . ' 后再试');
        }
        if (!$captchaOk && self::needCaptcha($user['id'], $ip)) {
            throw new \RuntimeException('请输入图形验证码');
        }
        $norm = self::normalize($code);
        if (strlen($norm) < 16 || strlen($norm) > 24) {
            self::addFail($ku);
            self::addFail($ki);
            throw new \InvalidArgumentException('卡密格式不正确');
        }
        $h = self::hash($code);
        Db::startTrans();
        try {
            $card = Db::name('recharge_card')->where('code_hash', $h)->lock(true)->find();
            if (!$card) {
                Db::rollback();
                self::addFail($ku);
                self::addFail($ki);
                audit('card.redeem_fail', '用户#' . $user['id'], ['ip' => $ip], 'warn', 'user', $user['id']);
                throw new \InvalidArgumentException('卡密不存在');
            }
            if ($card['status'] === 'used') {
                throw new \RuntimeException('该卡密已被使用');
            }
            if ($card['status'] === 'disabled') {
                throw new \RuntimeException('该卡密已停用');
            }
            if ($card['expire_time'] && $card['expire_time'] < time()) {
                throw new \RuntimeException('该卡密已过期');
            }
            $batch = Db::name('card_batch')->find($card['batch_id']);
            if ($batch && (int)$batch['per_user_limit'] > 0) {
                $used = Db::name('recharge_card')->where('batch_id', $batch['id'])->where('used_user_id', $user['id'])->count();
                if ($used >= (int)$batch['per_user_limit']) {
                    throw new \RuntimeException('本批次卡密每人最多兑换 ' . (int)$batch['per_user_limit'] . ' 张');
                }
            }
            $aff = Db::name('recharge_card')->where('id', $card['id'])->where('status', 'unused')->update([
                'status' => 'used', 'used_user_id' => $user['id'], 'used_time' => time(), 'used_ip' => mb_substr($ip, 0, 60),
            ]);
            if ($aff !== 1) {
                throw new \RuntimeException('该卡密已被使用');
            }
            $after = OrderService::addBalance($user['id'], (float)$card['face_value'], 'card', '卡密充值 ****' . $card['code_tail'], 'user', true);
            Db::commit();
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            try {
                Db::rollback();
            } catch (\Exception $e2) {
            }
            throw $e;
        }
        Db::name('redeem_fail')->where('fkey', 'in', [$ku])->delete();
        audit('card.redeem', '卡密#' . $card['id'], ['amount' => $card['face_value'], 'batch' => $card['batch_id'], 'ip' => $ip], 'info', 'user', $user['id']);
        if (setting('card_notify', '1') === '1') {
            Notify::user('card_redeemed', $user, [
                'amount' => money($card['face_value']), 'balance' => money($after), 'tail' => $card['code_tail'], '_key' => 'card' . $card['id'],
            ]);
        }
        if (setting('card_notify_admin', '0') === '1') {
            Notify::admin('admin_card_redeemed', [
                'username' => $user['username'], 'amount' => money($card['face_value']), 'tail' => $card['code_tail'], '_key' => 'card' . $card['id'],
            ]);
        }

        return (float)$card['face_value'];
    }

    /** 停用未使用卡密（单张或整批） */
    public static function disable($cardIds = [], $batchId = 0)
    {
        $q = Db::name('recharge_card')->where('status', 'unused');
        if ($batchId) {
            $q->where('batch_id', (int)$batchId);
        } else {
            $q->where('id', 'in', array_map('intval', (array)$cardIds));
        }
        $n = $q->update(['status' => 'disabled']);
        audit('card.disable', $batchId ? '批次#' . $batchId : '卡密', ['ids' => $cardIds, 'count' => $n], 'warn');

        return $n;
    }

    /** 删除未使用/已停用卡密（已使用的保留作为对账记录） */
    public static function delete($cardIds = [], $batchId = 0)
    {
        $q = Db::name('recharge_card')->where('status', 'in', ['unused', 'disabled']);
        if ($batchId) {
            $q->where('batch_id', (int)$batchId);
        } else {
            $q->where('id', 'in', array_map('intval', (array)$cardIds));
        }
        $n = $q->delete();
        audit('card.delete', $batchId ? '批次#' . $batchId : '卡密', ['ids' => $cardIds, 'count' => $n], 'warn');

        return $n;
    }

    /** 状态显示（含已过期） */
    public static function statusOf(array $card)
    {
        if ($card['status'] === 'unused' && $card['expire_time'] && $card['expire_time'] < time()) {
            return 'expired';
        }

        return $card['status'];
    }
}
