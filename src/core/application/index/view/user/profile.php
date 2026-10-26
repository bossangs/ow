<h2><?= h($title) ?></h2>
<div class="card p-4">
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label class="form-label">邮箱</label>
    <input class="form-control mb-1" type="email" name="email" value="<?= h($user['email']) ?>" required>
    <div class="form-text mb-3">用于登录、找回密码和接收通知。修改后会给原邮箱发一封提醒。</div>
    <label class="form-label">Telegram Chat ID</label>
    <input class="form-control mb-3" name="telegram_chat_id" value="<?= h($user['telegram_chat_id']) ?>">
    <label class="form-label">新密码（留空不修改）</label>
    <input class="form-control mb-1" type="password" name="password" minlength="8" maxlength="64" autocomplete="new-password">
    <div class="form-text mb-2">8-64 位，必须同时包含字母和数字，不能与用户名或邮箱相同。修改后其他设备会退出登录。</div>
    <input class="form-control mb-3" type="password" name="password2" minlength="8" maxlength="64" autocomplete="new-password" placeholder="再输一次新密码">
    <label class="form-label">当前密码 <span class="text-muted small">（修改邮箱或密码时必填）</span></label>
    <input class="form-control mb-3" type="password" name="current_password" autocomplete="current-password">
    <button class="btn btn-primary">保存</button>
    <a class="btn btn-outline-secondary ms-2" href="<?= h(url_to('index/user/security')) ?>">两步验证设置</a>
  </form>
</div>
