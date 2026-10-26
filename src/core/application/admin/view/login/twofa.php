<?= admin_auth_open('两步验证', '请输入谷歌验证器中的验证码') ?>
<p class="small text-muted mb-3">账号 <b><?= h($account['username']) ?></b> 已开启两步验证。打开手机上的 Google Authenticator（或其他验证器 App），输入「<?= h(\app\common\service\TwoFactorService::issuer('admin')) ?>」下当前显示的 6 位数字。</p>
<?php if ($locked > 0): ?>
<div class="alert alert-danger">验证码错误次数过多，请 <?= h(\app\common\lib\AuthGuard::waitText($locked)) ?>后再试。</div>
<?php endif; ?>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <div class="mb-3">
    <label for="twofa-code">验证码</label>
    <input id="twofa-code" class="form-control form-control-lg text-center twofa-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" placeholder="6 位数字" required autofocus>
    <div class="form-text">手机丢了？可以在这里输入一个恢复码（形如 abcde-12345），每个恢复码只能用一次。</div>
  </div>
  <button class="btn btn-primary w-100">验证并登录</button>
</form>
<div class="d-flex justify-content-between mt-3 small">
  <a href="<?= h(url_to('admin/login/logout')) ?>">换个账号登录</a>
  <span class="text-muted">请在 5 分钟内完成验证</span>
</div>
<?= admin_auth_close() ?>
