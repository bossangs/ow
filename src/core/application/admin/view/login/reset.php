<?= admin_auth_open('设置新密码', '为管理员 ' . $account['username'] . ' 设置新密码') ?>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <div class="mb-3"><label>新密码</label><input class="form-control" type="password" name="password" minlength="8" autocomplete="new-password" required autofocus><div class="form-text">8-64 位，必须同时包含字母和数字，不能与用户名或邮箱相同。</div></div>
  <div class="mb-3"><label>确认新密码</label><input class="form-control" type="password" name="password2" minlength="8" autocomplete="new-password" required></div>
  <?php if ($need2fa): ?>
  <div class="mb-3"><label>谷歌验证码 / 恢复码</label><input class="form-control" name="twofa_code" autocomplete="one-time-code" maxlength="11" required><div class="form-text">该账号开启了两步验证，重置密码也必须验证，防止邮箱被盗后被冒用。</div></div>
  <?php endif; ?>
  <button class="btn btn-primary w-100">保存新密码</button>
</form>
<div class="mt-3 small"><a href="<?= h(url_to('admin/login/index')) ?>">返回登录</a></div>
<?= admin_auth_close() ?>
