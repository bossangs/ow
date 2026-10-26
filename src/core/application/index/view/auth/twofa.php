<div class="auth-wrap"><div class="card auth-card p-4">
<a class="auth-logo" href="<?= h(url_to('')) ?>"><img src="<?= h(brand_logo_url(false, true)) ?>" alt="<?= h(setting('site_name', 'o2o云')) ?>" height="48"></a>
<h2>两步验证</h2>
<p class="small text-muted">账号 <b><?= h($account['username']) ?></b> 已开启两步验证。打开手机上的 Google Authenticator（或其他验证器 App），输入「<?= h(\app\common\service\TwoFactorService::issuer('user')) ?>」下当前显示的 6 位数字。</p>
<?php if ($locked > 0): ?><div class="alert alert-danger py-2">验证码错误次数过多，请 <?= h(\app\common\lib\AuthGuard::waitText($locked)) ?>后再试。</div><?php endif; ?>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input class="form-control form-control-lg text-center twofa-input mb-2" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" placeholder="6 位验证码" required autofocus>
  <div class="form-text mb-3">手机丢了？可以在这里输入一个恢复码（形如 abcde-12345），每个只能用一次。都没有了请联系客服。</div>
  <button class="btn btn-primary w-100">验证并登录</button>
</form>
<div class="small mt-3"><a href="<?= h(url_to('index/auth/logout')) ?>">换个账号登录</a></div>
</div></div>
