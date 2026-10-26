<div class="auth-wrap"><div class="card auth-card p-4">
<a class="auth-logo" href="<?= h(url_to('')) ?>"><img src="<?= h(brand_logo_url(false, true)) ?>" alt="<?= h(setting('site_name', 'o2o云')) ?>" height="48"></a>
<h2>设置新密码</h2>
<p class="small text-muted">为账号 <b><?= h($account['username']) ?></b> 设置新密码。这个链接只能使用一次。</p>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input class="form-control mb-1" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="新密码" required autofocus>
  <div class="form-text mb-2">8-64 位，必须同时包含字母和数字，不能与用户名或邮箱相同。</div>
  <input class="form-control mb-2" name="password2" type="password" minlength="8" autocomplete="new-password" placeholder="确认新密码" required>
  <?php if ($need2fa): ?>
  <input class="form-control mb-1" name="twofa_code" autocomplete="one-time-code" maxlength="11" placeholder="谷歌验证码 / 恢复码" required>
  <div class="form-text mb-2">该账号开启了两步验证，重置密码也必须验证。</div>
  <?php endif; ?>
  <button class="btn btn-primary w-100 mt-2">保存新密码</button>
</form>
</div></div>
