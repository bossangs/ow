<?= admin_auth_open('找回管理员密码', '找回管理员密码') ?>
<p class="small text-muted">填写管理员账号绑定的邮箱，我们会把<?= $method === 'link' ? '重置链接' : ($method === 'both' ? '验证码或重置链接' : '验证码') ?>发到这个邮箱。为了不泄露哪些邮箱是管理员，无论邮箱是否存在，提示都是一样的。</p>
<form method="post" class="mb-4" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="send">
  <div class="mb-3"><label>管理员邮箱</label><input class="form-control" type="email" name="email" value="<?= h($email) ?>" required></div>
  <?php if ($method === 'both'): ?>
  <div class="mb-3"><label class="d-block">找回方式</label>
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="via" id="via-code" value="code" checked><label class="form-check-label" for="via-code">邮箱验证码</label></div>
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="via" id="via-link" value="link"><label class="form-check-label" for="via-link">重置链接</label></div>
  </div>
  <?php endif; ?>
  <?php if (captcha_enabled()): ?>
  <div class="mb-3"><label>图形验证码</label><div class="input-group"><input class="form-control" name="captcha" required><img class="captcha-img" src="<?= h(url_to('admin/login/captcha')) ?>" data-captcha alt="验证码"></div></div>
  <?php endif; ?>
  <button class="btn btn-outline-primary w-100">发送找回邮件</button>
</form>
<?php if ($method !== 'link'): ?>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="reset">
  <h6 class="mb-3">收到验证码后，在这里设置新密码</h6>
  <div class="mb-3"><label>管理员邮箱</label><input class="form-control" type="email" name="email" value="<?= h($email) ?>" required></div>
  <div class="mb-3"><label>邮箱验证码</label><input class="form-control" name="code" inputmode="numeric" maxlength="6" placeholder="邮件里的 6 位数字（<?= (int) $minutes ?> 分钟内有效）" required></div>
  <div class="mb-3"><label>新密码</label><input class="form-control" type="password" name="password" minlength="8" autocomplete="new-password" required><div class="form-text">8-64 位，必须同时包含字母和数字，不能与用户名或邮箱相同。</div></div>
  <div class="mb-3"><label>确认新密码</label><input class="form-control" type="password" name="password2" minlength="8" autocomplete="new-password" required></div>
  <div class="mb-3"><label>谷歌验证码 / 恢复码 <span class="text-muted small">（开启了两步验证才需要填）</span></label><input class="form-control" name="twofa_code" autocomplete="one-time-code" maxlength="11"></div>
  <button class="btn btn-primary w-100">重置密码</button>
</form>
<?php endif; ?>
<div class="mt-3 small"><a href="<?= h(url_to('admin/login/index')) ?>">返回登录</a></div>
<?= admin_auth_close() ?>
