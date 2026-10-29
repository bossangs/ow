<?php
$flashes = session('flash') ?: [];
session('flash', null);
// 菜单：[key, 名称, 路由]；分组展示，key 与控制器 $navActive 对应
$menuGroups = [
    '概览' => [
        ['dashboard', '仪表盘', 'dashboard/index'],
    ],
    '订单与财务' => [
        ['order', '订单管理', 'order/index'],
        ['cancelrequest', '取消申请', 'cancel_request/index'],
        ['payment', '支付流水', 'payment/index'],
        ['gateway', '支付网关', 'gateway/index'],
        ['withdraw', '提现审核', 'withdraw/index'],
        ['balance', '余额调整/明细', 'balance/index'],
    ],
    '营销' => [
        ['coupon', '优惠码', 'coupon/index'],
        ['card', '卡密', 'card/index'],
    ],
    '云主机' => [
        ['service', '云主机服务', 'service/index'],
        ['product', '云主机产品', 'product/index'],
        ['productgroup', '产品分组', 'product_group/index'],
        ['upstream', '上游接口', 'upstream/index'],
        ['natnode', 'NAT 节点', 'nat_node/index'],
        ['whmcsbridge', 'WHMCS 桥接', 'whmcsbridge/index'],
    ],
    'DNS 分发' => [
        ['subdomain', '子域名', 'subdomain/index'],
        ['record', '解析记录', 'record/index'],
        ['domain', '根域名', 'domain/index'],
        ['dnsprovider', 'DNS 服务商', 'dnsprovider/index'],
        ['package', 'DNS 套餐', 'package/index'],
        ['userpackage', '用户套餐', 'user_package/index'],
        ['protectedrecord', '受保护记录', 'protected_record/index'],
    ],
    '域名市场' => [
        ['market', '用户域名审核', 'market/index'],
        ['marketsale', '销售与佣金', 'market_sale/index'],
    ],
    '用户' => [
        ['user', '用户管理', 'user/index'],
        ['tier', '会员等级', 'tier/index'],
        ['ticket', '工单', 'ticket/index'],
        ['satisfaction', '满意度报表', 'satisfaction/index'],
        ['announcement', '公告', 'announcement/index'],
    ],
    '系统' => [
        ['settings', '系统设置', 'settings/index'],
        ['risk', '风控规则', 'risk/index'],
        ['audit', '审计日志', 'audit/index'],
        ['operations', '计划任务', 'operations/index'],
        ['profile', '我的两步验证', 'profile/twofa'],
    ],
];
if (!empty($admin['is_super'])) {
    $menuGroups['系统'][] = ['admin', '管理员', 'admin/index'];
}
$active = isset($navActive) ? $navActive : '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(isset($pageTitle) ? $pageTitle : '后台') ?> - <?= h($siteName) ?></title>
<?= brand_head_tags() ?>
<link rel="stylesheet" href="<?= h(static_url('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= h(static_url('css/admin.css')) ?>">
</head>
<body>
<div class="admin-shell">
  <aside class="admin-side" id="adminSide">
    <a class="admin-brand" href="<?= h(url_to('admin/dashboard/index')) ?>"><img src="<?= h(brand_logo_url(true)) ?>" alt="<?= h($siteName) ?>" height="30"><small>管理后台</small></a>
    <nav>
      <?php foreach ($menuGroups as $group => $items): ?>
        <div class="admin-nav-group"><?= h($group) ?></div>
        <?php foreach ($items as $m): ?>
          <a class="<?= $active === $m[0] ? 'active' : '' ?>" href="<?= h(url_to('admin/' . $m[2])) ?>">
            <?= h($m[1]) ?>
            <?php if (!empty($pendingCounts[$m[0]])): ?><b class="badge bg-danger"><?= h($pendingCounts[$m[0]]) ?></b><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="admin-mask" id="adminMask"></div>
  <main class="admin-main">
    <header class="admin-top">
      <button class="btn btn-sm btn-outline-secondary" id="sideToggle" type="button" aria-label="菜单">☰</button>
      <img class="admin-top-logo" src="<?= h(brand_logo_url()) ?>" alt="" height="24">
      <span class="admin-title"><?= h(isset($pageTitle) ? $pageTitle : '后台') ?></span>
      <span class="ms-auto admin-top-links">
        <a href="<?= h(url_to('index/index/index')) ?>" target="_blank">前台</a>
        <a href="<?= h(url_to('admin/profile/twofa')) ?>">两步验证<?= empty($admin['totp_enabled']) ? '<span class="badge bg-warning text-dark ms-1">未开启</span>' : '' ?></a>
        <a href="<?= h(url_to('admin/login/password')) ?>">修改密码</a>
        <a href="<?= h(url_to('admin/login/logout')) ?>">退出</a>
      </span>
    </header>
    <section class="admin-content">
      <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= h($f[0]) ?>"><?= nl2br(h($f[1])) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </section>
  </main>
</div>
<script src="<?= h(static_url('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= h(static_url('js/admin.js')) ?>"></script>
</body>
</html>
