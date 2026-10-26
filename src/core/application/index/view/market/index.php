<?php
$statusText = [
    'pending' => ['待审核', 'warning'], 'active' => ['销售中', 'success'], 'rejected' => ['已驳回', 'danger'],
    'offline' => ['已下架', 'secondary'], 'paused' => ['已暂停', 'secondary'],
];
$wdText = ['pending' => '待处理', 'paid' => '已打款', 'rejected' => '已驳回'];
$allTypes = \app\common\service\DnsService::$allTypes;
$verifyHost = \app\common\service\MarketService::VERIFY_HOST;
?>
<h1 class="h3 mb-3">域名市场</h1>

<div class="card mb-4"><div class="card-body">
  <h2 class="h5">浏览并购买</h2>
  <div class="row g-3">
    <?php foreach ($listings as $d): ?>
      <div class="col-md-6"><div class="border rounded p-3 h-100">
        <b class="mono"><?= h($d['domain']) ?></b>
        <p class="small text-muted mb-2"><?= h($d['description']) ?></p>
        <p class="mb-2"><?= money($d['price']) ?> / <?= (int)$d['period_days'] > 0 ? h($d['period_days']) . ' 天' : '永久' ?></p>
        <a class="btn btn-sm btn-primary" href="<?= h(url_to('index/market/buy', ['id' => $d['id']])) ?>">查询并购买</a>
      </div></div>
    <?php endforeach; ?>
    <?php if (!$listings): ?><div class="empty">暂无在售域名</div><?php endif; ?>
  </div>
</div></div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h2 class="h4 mb-0">我的域名发布</h2>
  <a class="btn btn-outline-primary" href="<?= h(url_to('index/market/provider')) ?>">管理 DNS 凭据</a>
</div>
<p class="text-muted small">把您自己 DNS 账户里的域名发布到平台，其他用户购买子域名后，您获得售价扣除平台佣金（<?= h($commission) ?>%）后的收入。原有解析记录会被保护，买家无法占用。</p>

<div class="card mb-4"><div class="card-body">
  <h3 class="h6">发布新域名</h3>
  <?php if (!$providers): ?>
    <p class="mb-0">请先 <a href="<?= h(url_to('index/market/provider')) ?>">添加 DNS 凭据</a>（只需对应域名的解析权限），然后回到这里发布。</p>
  <?php else: ?>
  <form method="post" action="<?= h(url_to('index/market/submit')) ?>" class="row g-2" id="marketSubmit">
    <?= csrf_field() ?>
    <div class="col-md-4"><label class="form-label">DNS 凭据</label>
      <select class="form-select" name="provider_id" id="mkProvider" data-url="<?= h(url_to('index/market/remoteDomains')) ?>">
        <?php foreach ($providers as $p): ?><option value="<?= h($p['id']) ?>"><?= h($p['name']) ?>（<?= h($p['driver']) ?>）</option><?php endforeach; ?>
      </select></div>
    <div class="col-md-5"><label class="form-label">域名</label>
      <div class="input-group">
        <input class="form-control mono" name="domain" id="mkDomain" list="mkDomainList" placeholder="example.com" required>
        <button type="button" class="btn btn-outline-secondary" id="mkLoad">读取账户域名</button>
      </div>
      <datalist id="mkDomainList"></datalist>
      <input type="hidden" name="remote_id" id="mkRemote" value="">
      <div class="form-help" id="mkHint">可点击“读取账户域名”从 DNS 账户中选择</div></div>
    <div class="col-md-3"><label class="form-label">售价（每个子域名）</label>
      <input class="form-control" name="price" type="number" step="0.01" min="0" value="0"></div>
    <div class="col-md-3"><label class="form-label">有效期（天，0=永久）</label>
      <input class="form-control" name="period_days" type="number" min="0" value="365"></div>
    <div class="col-md-3"><label class="form-label">前缀长度</label>
      <div class="input-group"><input class="form-control" name="min_len" type="number" value="3" min="1" max="63"><span class="input-group-text">-</span><input class="form-control" name="max_len" type="number" value="32" min="1" max="63"></div></div>
    <div class="col-md-3"><label class="form-label">库存（0=不限）</label>
      <input class="form-control" name="stock" type="number" min="0" value="0"></div>
    <div class="col-md-3"><label class="form-label">每人限购（0=不限）</label>
      <input class="form-control" name="max_per_user" type="number" min="0" value="0"></div>
    <div class="col-md-12"><label class="form-label">允许的记录类型</label><div>
      <?php foreach ($allTypes as $t): ?>
        <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="types[]" value="<?= h($t) ?>" <?= in_array($t, ['A', 'AAAA', 'CNAME', 'TXT'], true) ? 'checked' : '' ?>> <?= h($t) ?></label>
      <?php endforeach; ?></div></div>
    <div class="col-md-6"><label class="form-label">保留前缀（逗号分隔）</label>
      <input class="form-control" name="reserved" placeholder="www,mail,admin"></div>
    <div class="col-md-3"><label class="form-label">默认 TTL</label>
      <input class="form-control" name="default_ttl" type="number" value="600"></div>
    <div class="col-md-3 d-flex align-items-end"><label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="allow_proxy" value="1"> 允许 CDN 代理</label></div>
    <div class="col-md-12"><label class="form-label">介绍</label>
      <input class="form-control" name="description" maxlength="250"></div>
    <div class="col-12"><button class="btn btn-primary">提交发布</button></div>
  </form>
  <?php endif; ?>
</div></div>

<div class="table-card table-responsive mb-4"><table class="table align-middle">
  <thead><tr><th>域名</th><th>状态</th><th>所有权验证</th><th>售价</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($domains as $d): $st = isset($statusText[$d['listing_status']]) ? $statusText[$d['listing_status']] : [$d['listing_status'], 'secondary']; ?>
    <tr>
      <td class="mono"><?= h($d['domain']) ?></td>
      <td><span class="badge text-bg-<?= h($st[1]) ?>"><?= h($st[0]) ?></span>
        <?php if (!empty($d['pause_reason'])): ?><div class="small text-muted"><?= h($d['pause_reason']) ?></div><?php endif; ?></td>
      <td><?php if (!$d['verified']): ?>
        <div class="small mb-1">请在您的 DNS 中添加 TXT 记录：<br>主机 <code><?= h($verifyHost) ?></code> 值 <code class="text-break"><?= h($d['verify_token']) ?></code>
          <button type="button" class="btn btn-link btn-sm p-0" data-copy="<?= h($d['verify_token']) ?>">复制</button></div>
        <form method="post" action="<?= h(url_to('index/market/verify')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($d['id']) ?>"><button class="btn btn-sm btn-warning">添加后点此验证</button></form>
      <?php else: ?><span class="text-success">已验证</span><?php endif; ?></td>
      <td><?= money($d['price']) ?></td>
      <td class="text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#mkEdit<?= (int)$d['id'] ?>">销售参数</button>
        <?php if (in_array($d['listing_status'], ['active', 'offline'], true)): ?>
        <form method="post" action="<?= h(url_to('index/market/status')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($d['id']) ?>"><input type="hidden" name="status" value="<?= $d['listing_status'] === 'offline' ? 'active' : 'offline' ?>"><button class="btn btn-sm btn-outline-secondary"><?= $d['listing_status'] === 'offline' ? '上架' : '下架' ?></button></form>
        <?php endif; ?>
        <form method="post" action="<?= h(url_to('index/market/remove')) ?>" class="d-inline" data-confirm="删除后该域名下由平台分配的子域名将不能再续期，确定删除？"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($d['id']) ?>"><button class="btn btn-sm btn-outline-danger">删除</button></form>
      </td>
    </tr>
    <tr class="collapse" id="mkEdit<?= (int)$d['id'] ?>"><td colspan="5">
      <form method="post" action="<?= h(url_to('index/market/update')) ?>" class="row g-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($d['id']) ?>">
        <?php $dt = array_filter(explode(',', (string)$d['types'])); ?>
        <div class="col-md-2"><label class="form-label small">售价</label><input class="form-control form-control-sm" name="price" type="number" step="0.01" value="<?= h($d['price']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">有效期（天）</label><input class="form-control form-control-sm" name="period_days" type="number" value="<?= h($d['period_days']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">最短</label><input class="form-control form-control-sm" name="min_len" type="number" value="<?= h($d['min_len']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">最长</label><input class="form-control form-control-sm" name="max_len" type="number" value="<?= h($d['max_len']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">库存</label><input class="form-control form-control-sm" name="stock" type="number" value="<?= h($d['stock']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">每人限购</label><input class="form-control form-control-sm" name="max_per_user" type="number" value="<?= h($d['max_per_user']) ?>"></div>
        <div class="col-md-12"><?php foreach ($allTypes as $t): ?><label class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" name="types[]" value="<?= h($t) ?>" <?= in_array($t, $dt, true) ? 'checked' : '' ?>> <?= h($t) ?></label><?php endforeach; ?></div>
        <div class="col-md-5"><label class="form-label small">保留前缀（受保护记录会自动保留）</label><input class="form-control form-control-sm" name="reserved" value="<?= h($d['reserved']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">默认 TTL</label><input class="form-control form-control-sm" name="default_ttl" type="number" value="<?= h($d['default_ttl']) ?>"></div>
        <div class="col-md-2 d-flex align-items-end"><label class="form-check form-switch small mb-1"><input class="form-check-input" type="checkbox" name="allow_proxy" value="1" <?= $d['allow_proxy'] ? 'checked' : '' ?>> CDN 代理</label></div>
        <div class="col-md-12"><input class="form-control form-control-sm" name="description" value="<?= h($d['description']) ?>" placeholder="介绍"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">保存销售参数</button></div>
      </form>
    </td></tr>
  <?php endforeach; ?>
  <?php if (!$domains): ?><tr><td colspan="5" class="text-muted">还没有发布域名</td></tr><?php endif; ?>
  </tbody>
</table></div>

<div class="row g-4">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h2 class="h5">销售记录</h2>
    <div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>时间</th><th>子域名</th><th>实付</th><th>佣金</th><th>收入</th></tr></thead><tbody>
      <?php foreach (array_slice($sales, 0, 20) as $s): ?>
        <tr><td class="small"><?= date('m-d H:i', (int)$s['create_time']) ?></td><td class="mono small"><?= h(isset($s['fqdn']) ? $s['fqdn'] : '') ?></td><td><?= money($s['amount']) ?></td><td><?= money($s['commission']) ?></td><td><?= money($s['income']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$sales): ?><tr><td colspan="5" class="text-muted">暂无销售</td></tr><?php endif; ?>
      </tbody></table></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h2 class="h5">提现</h2>
    <p class="text-muted small">账户余额：<b><?= money($user['balance']) ?></b>；最低 <?= h(setting('withdraw_min', '10')) ?>，手续费 <?= h(setting('withdraw_fee_rate', '0')) ?>%。</p>
    <form method="post" action="<?= h(url_to('index/market/withdraw')) ?>" class="row g-2 mb-3"><?= csrf_field() ?>
      <div class="col-4"><input class="form-control" name="amount" type="number" step="0.01" placeholder="金额" required></div>
      <div class="col-8"><select class="form-select" name="method"><?php foreach ($withdrawMethods as $m): ?><option><?= h($m) ?></option><?php endforeach; ?></select></div>
      <div class="col-8"><input class="form-control" name="account" placeholder="收款账号" required></div>
      <div class="col-4"><button class="btn btn-primary w-100">申请提现</button></div>
    </form>
    <div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>时间</th><th>金额</th><th>方式</th><th>状态</th></tr></thead><tbody>
      <?php foreach (array_slice($withdraws, 0, 20) as $w): ?>
        <tr><td class="small"><?= date('m-d H:i', (int)$w['create_time']) ?></td><td><?= money($w['amount']) ?></td><td class="small"><?= h($w['method']) ?></td>
          <td><?= h(isset($wdText[$w['status']]) ? $wdText[$w['status']] : $w['status']) ?><?php if (!empty($w['admin_note'])): ?><div class="small text-muted"><?= h($w['admin_note']) ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$withdraws): ?><tr><td colspan="4" class="text-muted">暂无提现</td></tr><?php endif; ?>
      </tbody></table></div>
  </div></div></div>
</div>
<script>
(function () {
  var btn = document.getElementById('mkLoad');
  if (!btn) return;
  var map = {};
  btn.addEventListener('click', function () {
    var sel = document.getElementById('mkProvider'), hint = document.getElementById('mkHint');
    hint.textContent = '正在读取…';
    fetch(sel.getAttribute('data-url') + '&provider_id=' + encodeURIComponent(sel.value), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.code !== 0) { hint.textContent = d.msg || '读取失败'; return; }
        var list = document.getElementById('mkDomainList'); list.innerHTML = ''; map = {};
        (d.data || []).forEach(function (x) { map[x.domain] = x.id; var o = document.createElement('option'); o.value = x.domain; list.appendChild(o); });
        hint.textContent = '已读取 ' + (d.data || []).length + ' 个域名，请在输入框中选择';
      })
      .catch(function () { hint.textContent = '读取失败，也可以直接输入域名'; });
  });
  document.getElementById('mkDomain').addEventListener('input', function () {
    document.getElementById('mkRemote').value = map[this.value] || '';
  });
})();
</script>
