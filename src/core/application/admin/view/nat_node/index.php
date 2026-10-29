<?php
/** @var array $list */
$cmdUrl = function ($id, $partial) {
    return url_to('admin/nat_node/command', $partial ? ['id' => $id, 'partial' => 1] : ['id' => $id]);
};
$ago = function ($ts) {
    if (!$ts) {
        return '从未';
    }
    $d = time() - (int) $ts;
    if ($d < 60) {
        return max(0, $d) . ' 秒前';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' 分钟前';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' 小时前';
    }
    return fmt_time($ts);
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h2 class="h4 mb-0">NAT 节点</h2>
  <span class="text-muted small">共 <?= count($list) ?> 个</span>
  <div class="ms-auto d-flex gap-2">
    <form method="post" action="<?= h(url_to('admin/nat_node/quick')) ?>"><?= csrf_field() ?><button class="btn btn-success nat-quick">快速添加</button></form>
    <a class="btn btn-outline-primary" href="<?= h(url_to('admin/whmcsbridge/index')) ?>">WHMCS 桥接</a>
    <a class="btn btn-primary" href="<?= h(url_to('admin/nat_node/edit')) ?>">添加节点</a>
  </div>
</div>
<p class="text-muted small mb-3">添加节点后复制一键安装命令到节点服务器（Debian / Ubuntu）上运行，节点注册成功后会自动接入「上游接口」，在产品里选这个上游即可开始销售。</p>
<form class="row g-2 mb-3" method="get" action="<?= h(url_to('admin/nat_node/index')) ?>">
  <?php if (isset($_GET['s'])): ?><input type="hidden" name="s" value="<?= h($_GET['s']) ?>"><?php endif; ?>
  <div class="col-8 col-md-4"><input class="form-control" name="kw" value="<?= h($kw) ?>" placeholder="名称或 IP"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">搜索</button></div>
</form>
<?php if (!$list): ?>
  <div class="card p-4 text-center text-muted">还没有节点。点「快速添加」用默认参数直接生成一键安装命令，或点「添加节点」调整参数。</div>
<?php endif; ?>
<div class="row g-3">
  <?php foreach ($list as $n): $st = $n['state']; $pct = $n['cap_max'] ? min(100, round($n['cap_used'] * 100 / $n['cap_max'])) : 0; ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 nat-node-card" data-node-id="<?= (int) $n['id'] ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-2">
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= h($n['name']) ?> <span class="text-muted small">#<?= (int) $n['id'] ?></span></div>
            <div class="small text-muted"><?= $n['public_ip'] !== '' || $n['public_ipv6'] !== '' ? h(trim($n['public_ip'] . ' ' . $n['public_ipv6'])) : '未注册，暂无 IP' ?></div>
          </div>
          <span class="badge text-bg-<?= h($st['badge']) ?> nat-state" data-state="<?= h($st['code']) ?>"><?= h($st['label']) ?></span>
        </div>
        <dl class="row small mb-2">
          <dt class="col-4 fw-normal text-muted">最后心跳</dt><dd class="col-8 mb-1"><?= h($ago($n['last_heartbeat'])) ?></dd>
          <dt class="col-4 fw-normal text-muted">容量</dt>
          <dd class="col-8 mb-1"><?= (int) $n['cap_used'] ?> / <?= (int) $n['cap_max'] ?> 台
            <div class="progress mt-1" style="height:6px"><div class="progress-bar<?= $pct >= 90 ? ' bg-danger' : '' ?>" style="width:<?= (int) $pct ?>%"></div></div>
          </dd>
          <dt class="col-4 fw-normal text-muted">端口范围</dt><dd class="col-8 mb-1"><?= h($n['port_range']) ?></dd>
          <dt class="col-4 fw-normal text-muted">关联上游</dt>
          <dd class="col-8 mb-1"><?php if ($n['upstream_id']): ?><a href="<?= h(url_to('admin/upstream/edit', ['id' => $n['upstream_id']])) ?>"><?= h($n['upstream_name'] ?: '#' . $n['upstream_id']) ?></a><?php else: ?><span class="text-muted">注册后自动创建</span><?php endif; ?></dd>
          <?php if ($st['code'] === 'pending'): ?>
          <dt class="col-4 fw-normal text-muted">令牌过期</dt><dd class="col-8 mb-1"><?= h(fmt_time($n['reg_expire'])) ?></dd>
          <?php endif; ?>
        </dl>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-primary" href="<?= h($cmdUrl($n['id'], false)) ?>" data-nat-cmd="<?= h($cmdUrl($n['id'], true)) ?>">生成安装命令</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(url_to('admin/nat_node/edit', ['id' => $n['id']])) ?>">编辑</a>
          <form class="d-inline ms-auto" method="post" action="<?= h(url_to('admin/nat_node/delete')) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" data-confirm="确定删除节点「<?= h($n['name']) ?>」？节点上已安装的程序不会被卸载。">删除</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="natCmdModal" tabindex="-1" aria-labelledby="natCmdTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="natCmdTitle">一键安装命令</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body" id="natCmdBody"><div class="text-center text-muted py-4">加载中…</div></div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('natCmdModal');
  if (!el || !window.bootstrap) { return; }
  var modal = new bootstrap.Modal(el), body = document.getElementById('natCmdBody');
  function open(url) {
    body.innerHTML = '<div class="text-center text-muted py-4">加载中…</div>';
    modal.show();
    fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
      .then(function (html) { body.innerHTML = html; })
      .catch(function () { body.innerHTML = '<div class="alert alert-danger mb-0">加载失败，请刷新页面后重试</div>'; });
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-nat-cmd]') : null;
    if (a) { e.preventDefault(); open(a.getAttribute('data-nat-cmd')); }
  });
  <?php if ($show): ?>
  var auto = document.querySelector('.nat-node-card[data-node-id="<?= (int) $show ?>"] [data-nat-cmd]');
  if (auto) { open(auto.getAttribute('data-nat-cmd')); }
  <?php endif; ?>
});
</script>
