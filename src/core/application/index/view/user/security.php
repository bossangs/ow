<h2><?= h($title) ?></h2>
<?php if ($codes): ?>
<div class="card border-warning p-4 mb-3">
  <h5>请立即保存恢复码</h5>
  <p class="small text-muted mb-2">手机丢失、换机或验证器被删时，只能用恢复码登录。每个恢复码只能用一次；这些恢复码<strong>只显示这一次</strong>。</p>
  <div class="recovery-codes mb-3"><?php foreach ($codes as $c): ?><code><?= h($c) ?></code><?php endforeach; ?></div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary btn-sm" href="<?= h(url_to('index/user/twofaCodes')) ?>">下载 txt（下载后不再显示）</a>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-text="<?= h(implode("\n", $codes)) ?>">复制全部</button>
    <form method="post" action="<?= h(url_to('index/user/twofaSaved')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-success btn-sm">我已保存好</button></form>
  </div>
</div>
<?php endif; ?>
<?php if ($enabled): ?>
<div class="card p-4 mb-3">
  <p class="mb-1"><span class="badge text-bg-success">两步验证已开启</span> 绑定时间：<?= h(date('Y-m-d H:i', $bindTime)) ?></p>
  <p class="small text-muted mb-0">登录和找回密码时需要输入验证器里「<?= h($issuer) ?>」的 6 位验证码。剩余可用恢复码：<b><?= (int) $left ?></b> 个<?= $left <= 3 ? '，建议重新生成' : '' ?>。</p>
</div>
<div class="row g-3">
  <div class="col-md-6">
    <form method="post" action="<?= h(url_to('index/user/twofaRegenerate')) ?>" class="card p-4 h-100" autocomplete="off">
      <h6>重新生成恢复码</h6>
      <p class="small text-muted">生成一组新的 10 个恢复码，旧的全部作废。</p>
      <?= csrf_field() ?>
      <input class="form-control mb-2 twofa-input" name="code" inputmode="numeric" maxlength="6" placeholder="当前 6 位验证码" required>
      <div><button class="btn btn-outline-primary">重新生成</button></div>
    </form>
  </div>
  <div class="col-md-6">
    <form method="post" action="<?= h(url_to('index/user/twofaDisable')) ?>" class="card p-4 h-100" autocomplete="off">
      <h6>关闭两步验证</h6>
      <p class="small text-muted">关闭后登录只需要密码，安全性会降低。</p>
      <?= csrf_field() ?>
      <input class="form-control mb-2" type="password" name="password" placeholder="登录密码" autocomplete="current-password" required>
      <input class="form-control mb-2" name="code" maxlength="11" placeholder="当前验证码或恢复码" required>
      <div><button class="btn btn-outline-danger" data-confirm="确定关闭两步验证吗？">关闭</button></div>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card p-4">
  <p class="mb-3">开启两步验证后，即使密码泄露，别人没有您的手机也登录不了。</p>
  <div class="row g-4 align-items-start">
    <div class="col-md-5 text-center">
      <div class="qr-box"><?= $qr ?></div>
      <div class="small text-muted mt-2">二维码由本站服务器本地生成，不经过第三方。</div>
    </div>
    <div class="col-md-7">
      <ol class="twofa-steps">
        <li>在手机上安装 <b>Google Authenticator</b>（谷歌身份验证器），也可以用 Microsoft Authenticator 等支持 TOTP 的 App。</li>
        <li>在 App 里点「+」→「扫描二维码」，扫描左边的二维码。<br><span class="small text-muted">扫不了？选「输入设置密钥」，账号填 <code><?= h($account['username']) ?></code>，密钥填：</span>
          <div class="input-group input-group-sm my-1"><input class="form-control font-monospace" value="<?= h($secretGroups) ?>" readonly><button type="button" class="btn btn-outline-secondary" data-copy-text="<?= h($secret) ?>">复制</button></div>
          <span class="small text-muted">类型选「基于时间」。</span></li>
        <li>输入 App 里「<?= h($issuer) ?>」下显示的 6 位数字，并输入登录密码确认。</li>
      </ol>
      <?php if ($locked > 0): ?><div class="alert alert-danger py-2">验证码错误次数过多，请 <?= h(\app\common\lib\AuthGuard::waitText($locked)) ?>后再试。</div><?php endif; ?>
      <form method="post" action="<?= h(url_to('index/user/twofaEnable')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="row g-2">
          <div class="col-sm-6"><input class="form-control twofa-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6 位验证码" required></div>
          <div class="col-sm-6"><input class="form-control" type="password" name="password" placeholder="登录密码" autocomplete="current-password" required></div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <button class="btn btn-primary">确认开启</button>
          <a class="btn btn-outline-secondary" href="<?= h(url_to('index/user/security', ['renew' => 1])) ?>">换一个二维码</a>
        </div>
        <p class="small text-muted mt-2 mb-0">验证码对不上？多半是手机时间不准：请在手机设置里打开「自动设置时间」。</p>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
