<?php
$navActive = isset($navActive) ? $navActive : '';
$title = isset($title) ? $title : '';
$sidebar = isset($sidebar) ? $sidebar : false;
$flash = session('flash') ?: [];
session('flash', null);
$ucMenu = [
    ['user', 'index', '概览'],
    ['host', 'index', '我的云主机'],
    ['dns', 'index', '我的域名'],
    ['order', 'index', '订单记录'],
    ['user', 'balance', '余额明细'],
    ['user', 'card', '卡密充值'],
    ['market', 'index', '域名市场'],
    ['ticket', 'index', '工单支持'],
    ['user', 'profile', '账户设置'],
    ['user', 'security', '账号安全'],
];
$ctrl = strtolower(\think\facade\Request::controller());
$act = strtolower(\think\facade\Request::action());
if (!$sidebar && $user && in_array($ctrl, ['user', 'host', 'dns', 'order', 'ticket', 'market'], true)) {
    $sidebar = true;
}
// 页面自带全宽 section/container 时不再包裹，否则统一放进 container，避免内容贴边
$selfContained = strpos(substr(ltrim($content), 0, 400), 'class="container') !== false;
$noticeUnread = $user ? (int)\think\Db::name('user_notice')->where('user_id', $user['id'])->where('is_read', 0)->count() : 0;
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title ? $title . ' - ' . $siteName : $siteName . ' - ' . setting('site_slogan', '云主机 · 域名分发')) ?></title>
<meta name="description" content="<?= h(setting('site_description', '')) ?>">
<meta name="keywords" content="<?= h(setting('site_keywords', '')) ?>">
<?= brand_head_tags() ?>
<link rel="stylesheet" href="<?= h(static_url('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= h(static_url('css/app.css')) ?>">
<?php if (setting('theme_color', '') !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', setting('theme_color'))): ?>
<style>:root{--brand:<?= setting('theme_color') ?>}</style>
<?php endif; ?>
</head>
<body>
<nav class="navbar navbar-expand-lg site-nav sticky-top">
  <div class="container">
    <a class="navbar-brand site-brand" href="<?= h(url_to('')) ?>"><img class="site-logo" src="<?= h(brand_logo_url()) ?>" alt="<?= h($siteName) ?>" height="32"></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-label="菜单"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link <?= $navActive === 'index' ? 'active' : '' ?>" href="<?= h(url_to('')) ?>">首页</a></li>
        <li class="nav-item"><a class="nav-link <?= $navActive === 'shop' && $act !== 'domains' && $act !== 'packages' ? 'active' : '' ?>" href="<?= h(url_to('index/shop/index')) ?>">云主机</a></li>
        <li class="nav-item"><a class="nav-link <?= $act === 'domains' ? 'active' : '' ?>" href="<?= h(url_to('index/shop/domains')) ?>">免费域名</a></li>
        <li class="nav-item"><a class="nav-link <?= $act === 'packages' ? 'active' : '' ?>" href="<?= h(url_to('index/shop/packages')) ?>">DNS 套餐</a></li>
        <li class="nav-item"><a class="nav-link <?= $act === 'announce' ? 'active' : '' ?>" href="<?= h(url_to('index/index/announce')) ?>">公告</a></li>
      </ul>
      <div class="d-flex gap-2 align-items-center">
        <?php if ($user): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= h(url_to('index/user/index')) ?>">用户中心<?php if ($noticeUnread): ?> <span class="badge text-bg-danger"><?= $noticeUnread ?></span><?php endif; ?></a>
          <span class="text-muted small d-none d-lg-inline"><?= h($user['username']) ?></span>
          <a class="btn btn-sm btn-light" href="<?= h(url_to('index/auth/logout', ['_token' => csrf_token()])) ?>">退出</a>
        <?php else: ?>
          <a class="btn btn-sm btn-light" href="<?= h(url_to('index/auth/login')) ?>">登录</a>
          <?php if (setting('reg_enable', '1') === '1'): ?><a class="btn btn-sm btn-primary" href="<?= h(url_to('index/auth/register')) ?>">免费注册</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<?php if (setting('site_notice', '') !== ''): ?>
<div class="bg-warning-subtle border-bottom small py-2"><div class="container"><?= h(setting('site_notice')) ?></div></div>
<?php endif; ?>
<main>
<?php if ($sidebar): ?>
<div class="container py-4">
  <div class="row g-4">
    <aside class="col-lg-3 col-xl-2">
      <div class="uc-side">
        <div class="d-lg-none mb-2">
          <select class="form-select" onchange="location.href=this.value">
            <?php foreach ($ucMenu as $m): ?>
              <option value="<?= h(url_to('index/' . $m[0] . '/' . $m[1])) ?>" <?= $ctrl === $m[0] && ($act === $m[1] || ($m[1] === 'index' && $ctrl !== 'user')) ? 'selected' : '' ?>><?= h($m[2]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="list-group d-none d-lg-block">
          <?php foreach ($ucMenu as $m):
              $on = $ctrl === $m[0] && ($m[0] !== 'user' || $act === $m[1] || ($m[1] === 'balance' && $act === 'recharge')); ?>
            <a class="list-group-item list-group-item-action <?= $on ? 'active' : '' ?>" href="<?= h(url_to('index/' . $m[0] . '/' . $m[1])) ?>"><?= h($m[2]) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted px-3 pt-3 d-none d-lg-block">余额 <b class="text-dark"><?= money($user['balance']) ?></b></div>
      </div>
    </aside>
    <section class="col-lg-9 col-xl-10">
      <?php foreach ($flash as $f): ?><div class="alert alert-<?= h($f[0]) ?> alert-dismissible fade show"><?= h($f[1]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>
      <?= $content ?>
    </section>
  </div>
</div>
<?php else: ?>
  <?php if ($flash): ?><div class="container pt-3"><?php foreach ($flash as $f): ?><div class="alert alert-<?= h($f[0]) ?> alert-dismissible fade show"><?= h($f[1]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?></div><?php endif; ?>
  <?php if ($selfContained): ?><?= $content ?><?php else: ?><div class="container py-4"><?= $content ?></div><?php endif; ?>
<?php endif; ?>
</main>
<footer class="footer mt-4">
  <div class="container d-flex flex-wrap justify-content-between gap-2">
    <div class="d-flex align-items-center gap-2"><img class="footer-logo" src="<?= h(brand_logo_url()) ?>" alt="<?= h($siteName) ?>" height="22"><span>© <?= date('Y') ?> <?= h($siteName) ?> <?= setting('site_icp', '') !== '' ? ' · ' . h(setting('site_icp')) : '' ?></span></div>
    <div class="d-flex gap-3"><a class="text-secondary" href="<?= h(url_to('index/index/help')) ?>">帮助中心</a><a class="text-secondary" href="<?= h(url_to('index/index/abuse')) ?>">滥用举报</a><a class="text-secondary" href="<?= h(url_to('index/index/terms')) ?>">服务条款</a></div>
  </div>
  <?php if (setting('site_footer_html', '') !== ''): ?><div class="container mt-2"><?= setting('site_footer_html') ?></div><?php endif; ?>
</footer>
<script src="<?= h(static_url('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= h(static_url('js/app.js')) ?>"></script>
</body>
</html>
