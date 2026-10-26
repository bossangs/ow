<?php

namespace app\common\service;

use app\common\lib\Notify;
use think\Db;

/**
 * 后台余额调整
 *
 * - 每次调整写 balance_log（变动前/后余额、操作人、IP、时间、备注），用户在“余额明细”里可见；
 * - 写审计日志；
 * - 默认不允许扣成负数（后台勾选“允许负数”才行）；
 * - 单次金额绝对值 ≥ 设置 balance_confirm_amount（默认 1000）时必须再次输入管理员密码；
 * - 支持批量（多个用户 ID / 用户名 / 邮箱，逗号或换行分隔）。
 */
class BalanceService
{
    /**
     * 调整单个用户余额
     * @param array $admin 当前管理员行
     * @param int $userId
     * @param float $amount 正数增加，负数扣除
     * @param string $reason 必填原因
     * @param array $opt notify(bool) allow_negative(bool) admin_password(string)
     * @return float 调整后余额
     */
    public static function adjust(array $admin, $userId, $amount, $reason, array $opt = [])
    {
        $amount = round((float)$amount, 2);
        $reason = trim((string)$reason);
        if ($amount == 0) {
            throw new \InvalidArgumentException('调整金额不能为 0');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('请填写调整原因');
        }
        self::checkBigAmount($admin, abs($amount), isset($opt['admin_password']) ? $opt['admin_password'] : '', isset($opt['admin_2fa_code']) ? $opt['admin_2fa_code'] : '');
        $user = Db::name('user')->find((int)$userId);
        if (!$user) {
            throw new \InvalidArgumentException('用户 #' . (int)$userId . ' 不存在');
        }
        $allowNeg = !empty($opt['allow_negative']);
        $operator = 'admin:' . $admin['username'];
        $after = OrderService::addBalance($user['id'], $amount, $amount > 0 ? 'admin_add' : 'admin_deduct', $reason, $operator, $allowNeg);
        audit('user.balance_adjust', '用户#' . $user['id'], [
            'username' => $user['username'],
            'amount' => $amount,
            'before' => round($after - $amount, 2),
            'after' => $after,
            'reason' => $reason,
            'allow_negative' => $allowNeg ? 1 : 0,
        ], 'warn');
        if (!empty($opt['notify'])) {
            Notify::user('balance_adjust', $user, [
                'amount' => money($amount),
                'balance' => money($after),
                'reason' => $reason,
                '_key' => 'bal' . $user['id'] . '-' . microtime(true),
            ]);
        }

        return $after;
    }

    /**
     * 批量调整
     * @param string $targets 用户 ID / 用户名 / 邮箱，逗号、空格或换行分隔
     * @return array ['ok' => [[id, username, after]], 'fail' => [[target, error]]]
     */
    public static function batch(array $admin, $targets, $amount, $reason, array $opt = [])
    {
        $amount = round((float)$amount, 2);
        $list = array_values(array_unique(array_filter(preg_split('/[\s,;，；]+/u', (string)$targets), 'strlen')));
        if (!$list) {
            throw new \InvalidArgumentException('请填写要调整的用户');
        }
        $max = (int)setting('balance_batch_max', '200');
        if (count($list) > $max) {
            throw new \InvalidArgumentException('一次最多调整 ' . $max . ' 个用户');
        }
        // 批量按总额判断是否需要密码确认
        self::checkBigAmount($admin, abs($amount) * count($list), isset($opt['admin_password']) ? $opt['admin_password'] : '', isset($opt['admin_2fa_code']) ? $opt['admin_2fa_code'] : '');
        $opt['admin_password'] = '__checked__';
        $ok = [];
        $fail = [];
        foreach ($list as $t) {
            $q = Db::name('user');
            if (ctype_digit($t)) {
                $q->where('id', (int)$t);
            } elseif (strpos($t, '@') !== false) {
                $q->where('email', $t);
            } else {
                $q->where('username', $t);
            }
            $u = $q->find();
            if (!$u) {
                $fail[] = [$t, '用户不存在'];
                continue;
            }
            try {
                $after = self::adjust($admin, $u['id'], $amount, $reason, $opt);
                $ok[] = [$u['id'], $u['username'], $after];
            } catch (\Exception $e) {
                $fail[] = [$t, $e->getMessage()];
            }
        }
        audit('user.balance_batch', '批量调整余额', ['amount' => $amount, 'reason' => $reason, 'ok' => count($ok), 'fail' => count($fail)], 'warn');

        return ['ok' => $ok, 'fail' => $fail];
    }

    /** 大额需再次输入管理员密码 */
    protected static function checkBigAmount(array $admin, $abs, $password, $twofaCode = '')
    {
        $limit = (float)setting('balance_confirm_amount', '1000');
        if ($limit <= 0 || $abs < $limit || $password === '__checked__') {
            return;
        }
        $row = Db::name('admin')->find($admin['id']);
        if (!$row || $password === '' || !password_verify((string)$password, $row['password'])) {
            throw new \InvalidArgumentException('调整金额达到 ' . money($limit) . '，请输入正确的管理员登录密码确认');
        }
        // 开启了两步验证的管理员：几分钟内没做过二次验证时，还需要输入谷歌验证码
        if (\app\common\service\TwoFactorService::enabled($row) && (int)session('admin_sudo_until') < time()) {
            if (trim((string)$twofaCode) === '') {
                throw new \InvalidArgumentException('调整金额达到 ' . money($limit) . '，请同时输入谷歌验证码确认');
            }
            \app\common\service\TwoFactorService::verify('admin', $row, $twofaCode, false);
            session('admin_sudo_until', time() + max(1, (int)setting('admin_sudo_minutes', '5')) * 60);
        }
    }
}
