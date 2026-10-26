<?php
use app\common\nat\NatScript;

/** @var array|null $row @var array $opts @var bool $registered */
$sel = function ($name, array $options, $val) {
    $s = '<select class="form-select" id="f_' . h($name) . '" name="' . h($name) . '">';
    foreach ($options as $k => $label) {
        $s .= '<option value="' . h($k) . '"' . ((string) $k === (string) $val ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $s . '</select>';
};
$num = function ($name, $val, $min, $max) {
    return '<input type="number" inputmode="numeric" class="form-control" id="f_' . h($name) . '" name="' . h($name) . '" value="' . h($val) . '" min="' . (int) $min . '" max="' . (int) $max . '" required>';
};
$imgs = explode(',', (string) $opts['images']);
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h2 class="h4 mb-0"><?= $row ? '编辑节点' : '添加 NAT 节点' ?></h2>
  <?php if (!$row): ?>
  <form class="ms-auto" method="post" action="<?= h(url_to('admin/nat_node/quick')) ?>">
    <?= csrf_field() ?><input type="hidden" name="upstream_id" value="<?= (int) $upstreamId ?>">
    <button class="btn btn-success btn-sm nat-quick">快速添加（全部默认）</button>
  </form>
  <?php endif; ?>
</div>
<?php if (!$row): ?><div class="alert alert-light border small py-2">下面的参数已经按默认值自动填好（端口段、内网段会避开已有节点），一般直接点最下面「保存并生成安装命令」即可。</div><?php endif; ?>
<?php if ($row): $st = $row['state']; ?>
<div class="card p-3 mb-3 nat-readonly">
  <div class="d-flex align-items-center mb-2"><span class="fw-semibold">节点状态（系统自动维护）</span>
    <span class="badge text-bg-<?= h($st['badge']) ?> ms-auto"><?= h($st['label']) ?></span></div>
  <dl class="row small mb-0">
    <dt class="col-5 col-sm-3 fw-normal text-muted">公网 IP</dt><dd class="col-7 col-sm-9 mb-1"><?= h(trim($row['public_ip'] . ' ' . $row['public_ipv6']) ?: '-') ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">Agent 地址</dt><dd class="col-7 col-sm-9 mb-1" style="word-break:break-all"><?= h($row['agent_url'] ?: '注册后自动填写') ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">最后心跳</dt><dd class="col-7 col-sm-9 mb-1"><?= h(fmt_time($row['last_heartbeat'], 'Y-m-d H:i:s')) ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">注册令牌</dt><dd class="col-7 col-sm-9 mb-1"><?= $row['reg_token'] !== '' ? '有效期至 ' . h(fmt_time($row['reg_expire'])) : '已作废' ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">关联上游</dt><dd class="col-7 col-sm-9 mb-1"><?= $row['upstream_id'] ? h($row['upstream_name']) . ' (#' . (int) $row['upstream_id'] . ')' : '注册后自动创建' ?></dd>
  </dl>
  <?php if ($registered): ?><div class="small text-muted mt-2">节点已安装：修改下面的参数不会改动已安装的节点，需要重新生成令牌并在节点上重新运行安装命令才会生效。</div><?php endif; ?>
</div>
<?php endif; ?>
<form method="post" class="admin-form nat-form" action="<?= h(url_to('admin/nat_node/edit', $id ? ['id' => $id] : [])) ?>">
  <?= csrf_field() ?>
  <div class="card p-3 mb-3">
    <div class="fw-semibold mb-2">基本信息</div>
    <div class="row">
      <div class="col-md-6 mb-3"><label class="form-label" for="f_name">节点名称</label>
        <input class="form-control" id="f_name" name="name" maxlength="100" required value="<?= h($row ? $row['name'] : $defaultName) ?>">
        <div class="form-text">仅后台显示，例如「香港 NAT 1 号」</div></div>
      <div class="col-md-6 mb-3"><label class="form-label" for="f_panel_url">面板地址</label>
        <input class="form-control" id="f_panel_url" name="panel_url" required value="<?= h($opts['panel_url']) ?>" inputmode="url">
        <div class="form-text">节点通过这个地址下载脚本和注册，默认是本站网址</div></div>
      <?php if (!$registered): ?>
      <div class="col-md-6 mb-3"><label class="form-label" for="f_upstream_id">关联上游</label>
        <?= $sel('upstream_id', [0 => '注册成功后自动创建（推荐）'] + $upstreams, $upstreamId) ?>
        <div class="form-text">注册后 Agent 地址、令牌、公网 IP 会自动写入这个上游，产品选它即可销售</div></div>
      <?php endif; ?>
      <?php if (!$row): ?>
      <div class="col-md-6 mb-3"><label class="form-label" for="f_token_hours">注册令牌有效期</label>
        <?= $sel('token_hours', [1 => '1 小时', 6 => '6 小时', 24 => '24 小时', 72 => '3 天', 168 => '7 天'] + [$tokenHours => $tokenHours . ' 小时'], $tokenHours) ?>
        <div class="form-text">一次性令牌，过期或注册成功后作废；默认值可在系统设置里改</div></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card p-3 mb-3">
    <div class="fw-semibold mb-2">虚拟化与存储</div>
    <div class="row">
      <div class="col-md-6 mb-3"><label class="form-label" for="f_backend">后端</label><?= $sel('backend', NatScript::BACKENDS, $opts['backend']) ?></div>
      <div class="col-md-6 mb-3"><label class="form-label" for="f_storage_driver">存储类型</label><?= $sel('storage_driver', NatScript::STORAGES, $opts['storage_driver']) ?></div>
      <div class="col-6 col-md-6 mb-3"><label class="form-label" for="f_storage_size_gb">存储大小（GB）</label><?= $num('storage_size_gb', $opts['storage_size_gb'], 5, 100000) ?></div>
      <div class="col-6 col-md-6 mb-3"><label class="form-label" for="f_max_instances">最大实例数</label><?= $num('max_instances', $opts['max_instances'], 1, 100000) ?></div>
      <div class="col-md-6 mb-3" data-show-when="#f_storage_driver=btrfs|zfs|lvm"><label class="form-label" for="f_pool_path">存储源（可选）</label>
        <input class="form-control" id="f_pool_path" name="pool_path" value="<?= h($opts['pool_path']) ?>" placeholder="如 /dev/vdb，留空则用镜像文件">
        <div class="form-text">btrfs/zfs/lvm 可指定空闲磁盘或分区，会被格式化</div></div>
    </div>
  </div>
  <div class="card p-3 mb-3">
    <div class="fw-semibold mb-2">网络</div>
    <div class="row">
      <div class="col-6 mb-3"><label class="form-label" for="f_port_start">转发端口起</label><?= $num('port_start', $opts['port_start'], 1024, 65535) ?></div>
      <div class="col-6 mb-3"><label class="form-label" for="f_port_end">转发端口止</label><?= $num('port_end', $opts['port_end'], 1024, 65535) ?></div>
      <div class="col-6 col-md-6 mb-3"><label class="form-label" for="f_agent_port">Agent 端口</label><?= $num('agent_port', $opts['agent_port'], 1, 65535) ?></div>
      <div class="col-6 col-md-6 mb-3"><label class="form-label" for="f_bridge_v4">内网段</label>
        <input class="form-control" id="f_bridge_v4" name="bridge_v4" required value="<?= h($opts['bridge_v4']) ?>" placeholder="10.88.0.1/16"></div>
      <div class="col-md-6 mb-3"><label class="form-label" for="f_forward_mode">转发方式</label><?= $sel('forward_mode', NatScript::FORWARDS, $opts['forward_mode']) ?></div>
      <div class="col-md-6 mb-3"><label class="form-label" for="f_enable_ipv6">小鸡 IPv6</label><?= $sel('enable_ipv6', NatScript::IPV6, (string) $opts['enable_ipv6']) ?></div>
      <div class="col-md-6 mb-3 d-flex flex-column gap-2 justify-content-end">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="f_tls" name="tls" value="1"<?= $opts['tls'] ? ' checked' : '' ?>><label class="form-check-label" for="f_tls">Agent 启用 TLS（自签证书）</label></div>
      </div>
    </div>
  </div>
  <div class="card p-3 mb-3">
    <div class="fw-semibold mb-2">预下载系统镜像</div>
    <div class="row g-2">
      <?php foreach (NatScript::IMAGES as $k => $label): ?>
      <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="images[]" id="img_<?= h($k) ?>" value="<?= h($k) ?>"<?= in_array($k, $imgs, true) ? ' checked' : '' ?>><label class="form-check-label" for="img_<?= h($k) ?>"><?= h($label) ?></label></div></div>
      <?php endforeach; ?>
    </div>
    <div class="form-text">安装时从 images: 源下载，选得越多安装越久</div>
  </div>
  <div class="d-flex gap-2 mb-4">
    <button class="btn btn-primary"><?= $row ? '保存' : '保存并生成安装命令' ?></button>
    <a class="btn btn-outline-secondary" href="<?= h(url_to('admin/nat_node/index')) ?>">返回</a>
  </div>
</form>
