<?php
$statusText = [
    'pending' => ['待审核', 'warning'], 'active' => ['销售中', 'success'], 'rejected' => ['已驳回', 'danger'],
    'offline' => ['已下架', 'secondary'], 'paused' => ['已暂停', 'secondary'],
];
$st = isset($statusText[$row['listing_status']]) ? $statusText[$row['listing_status']] : [$row['listing_status'], 'secondary'];
$rate = (float)$row['commission_rate'] >= 0 ? (float)$row['commission_rate'] : (float)setting('market_commission', '10');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h2 class="h4 mb-0">审核域名：<span class="mono"><?= h($row['domain']) ?></span> <small class="text-muted">#<?= h($row['id']) ?></small></h2>
  <span class="badge text-bg-<?= h($st[1]) ?>"><?= h($st[0]) ?></span>
</div>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card p-3 mb-3">
      <h3 class="h6 mb-3">发布信息</h3>
      <dl class="row mb-0 small">
        <dt class="col-4 col-md-3">卖家</dt>
        <dd class="col-8 col-md-9"><?= $seller ? h($seller['username']) . ' <span class="text-muted">(#' . h($seller['id']) . ' · ' . h($seller['email']) . ')</span>' : '—' ?></dd>
        <dt class="col-4 col-md-3">DNS 凭据</dt>
        <dd class="col-8 col-md-9"><?= $provider ? h($provider['name']) . ' <span class="text-muted">(' . h($provider['driver']) . ')</span>' : '—' ?></dd>
        <dt class="col-4 col-md-3">所有权验证</dt>
        <dd class="col-8 col-md-9"><?= (int)$row['verified'] ? '<span class="text-success">已通过 TXT 验证</span>' : '<span class="text-danger">未验证</span>（卖家需添加 TXT 记录后点击验证）' ?></dd>
        <dt class="col-4 col-md-3">售价 / 有效期</dt>
        <dd class="col-8 col-md-9"><?= money($row['price']) ?> / <?= (int)$row['period_days'] > 0 ? h($row['period_days']) . ' 天' : '永久' ?></dd>
        <dt class="col-4 col-md-3">平台佣金</dt>
        <dd class="col-8 col-md-9"><?= h($rate) ?>%（卖家每单实得 <?= money(round((float)$row['price'] * (100 - $rate) / 100, 2)) ?>）</dd>
        <dt class="col-4 col-md-3">前缀长度</dt>
        <dd class="col-8 col-md-9"><?= h($row['min_len']) ?> - <?= h($row['max_len']) ?></dd>
        <dt class="col-4 col-md-3">记录类型</dt>
        <dd class="col-8 col-md-9 mono"><?= h(str_replace(',', ', ', $row['types'])) ?></dd>
        <dt class="col-4 col-md-3">库存 / 已售</dt>
        <dd class="col-8 col-md-9"><?= (int)$row['stock'] > 0 ? h($row['stock']) : '不限' ?> / <?= h($row['sold']) ?></dd>
        <dt class="col-4 col-md-3">保留前缀</dt>
        <dd class="col-8 col-md-9 mono text-break"><?= h($row['reserved'] ?: '—') ?></dd>
        <?php if ($row['description'] !== ''): ?>
        <dt class="col-4 col-md-3">介绍</dt>
        <dd class="col-8 col-md-9"><?= h($row['description']) ?></dd>
        <?php endif; ?>
        <?php if ($row['pause_reason'] !== ''): ?>
        <dt class="col-4 col-md-3">备注/原因</dt>
        <dd class="col-8 col-md-9"><?= h($row['pause_reason']) ?></dd>
        <?php endif; ?>
      </dl>
    </div>
    <div class="card p-3 mb-3">
      <h3 class="h6">受保护的原有解析（<?= count($protected) ?> 条）</h3>
      <p class="small text-muted">验证通过时从卖家 DNS 账户快照，买家不能注册、修改或删除这些记录对应的主机名。</p>
      <div class="table-responsive">
        <table class="table table-sm small mb-0">
          <thead><tr><th>主机名</th><th>类型</th><th>值</th></tr></thead>
          <tbody>
          <?php if (!$protected): ?><tr><td colspan="3" class="text-muted">暂无快照（验证通过后生成）</td></tr><?php endif; ?>
          <?php foreach ($protected as $p): ?>
            <tr><td class="mono"><?= h($p['name']) ?></td><td><?= h($p['type']) ?></td><td class="mono text-break"><?= h($p['value']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <form method="post" class="card p-3 admin-form">
      <?= csrf_field() ?>
      <h3 class="h6 mb-3">审核操作</h3>
      <div class="mb-3">
        <label class="form-label" for="f_action">审核动作 <span class="text-danger">*</span></label>
        <select class="form-select" id="f_action" name="action" required>
          <?php foreach (['approve' => '通过并上架', 'reject' => '驳回', 'offline' => '下架', 'paused' => '暂停'] as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label" for="f_reason">原因（驳回/下架时会通知卖家）</label>
        <textarea class="form-control" id="f_reason" name="reason" rows="4"></textarea>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary">保存</button>
        <a class="btn btn-outline-secondary" href="<?= h(url_to('admin/market/index')) ?>">返回</a>
      </div>
    </form>
  </div>
</div>
