<div class="auth-wrap"><div class="card auth-card p-4">
<a class="auth-logo" href="<?= h(url_to('')) ?>"><img src="<?= h(brand_logo_url(false, true)) ?>" alt="<?= h(setting('site_name', 'o2o云')) ?>" height="48"></a>
<h2>找回密码</h2>
<p class="small text-muted">填写注册邮箱，我们会把<?= $method === 'link' ? '重置链接' : ($method === 'both' ? '验证码或重置链接' : '验证码') ?>发到这个邮箱。为保护隐私，无论邮箱是否注册过，提示都一样。</p>
<form method="post" class="mb-4" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="send">
  <input class="form-control mb-2" name="email" type="email" value="<?= h($email) ?>" placeholder="注册邮箱" required>
  <?php if ($method === 'both'): ?>
  <div class="mb-2 small">
    <label class="me-3"><input type="radio" name="via" value="code" checked> 邮箱验证码</label>
    <label><input type="radio" name="via" value="link"> 重置链接</label>
  </div>
  <?php endif; ?>
  <?php if (captcha_enabled()): ?>
  <div class="d-flex gap-2 mb-2"><input class="form-control" name="captcha" placeholder="图形验证码" required><img class="captcha-img" src="<?= h(url_to('index/auth/captcha', ['scene' => 'reset'])) ?>" alt="验证码"></div>
  <?php endif; ?>
  <button class="btn btn-outline-primary w-100">发送找回邮件</button>
</form>
<?php if ($method !== 'link'): ?>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="reset">
  <h6 class="mb-2">收到验证码后，在这里设置新密码</h6>
  <input class="form-control mb-2" name="email" type="email" value="<?= h($email) ?>" placeholder="注册邮箱" required>
  <input class="form-control mb-2" name="code" inputmode="numeric" maxlength="6" placeholder="邮件里的 6 位验证码（<?= (int) $minutes ?> 分钟内有效）" required>
  <input class="form-control mb-1" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="新密码" required>
  <div class="form-text mb-2">8-64 位，必须同时包含字母和数字，不能与用户名或邮箱相同。</div>
  <input class="form-control mb-2" name="password2" type="password" minlength="8" autocomplete="new-password" placeholder="确认新密码" required>
  <input class="form-control mb-1" name="twofa_code" autocomplete="one-time-code" maxlength="11" placeholder="谷歌验证码 / 恢复码（开启了两步验证才需要）">
  <div class="form-text mb-3">开启了两步验证的账号，必须同时验证，防止邮箱被盗后被冒用。</div>
  <button class="btn btn-primary w-100">重置密码</button>
</form>
<?php endif; ?>
<div class="small mt-3"><a href="<?= h(url_to('index/auth/login')) ?>">返回登录</a></div>
</div></div>
