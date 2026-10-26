<?php
/** 上游接口 → NAT 小鸡节点：不手填，节点注册后自动填写。@var array|null $node @var int $upId @var array $cfg */
?>
<div class="nat-auto mb-2">
<?php if ($node): $st = \app\common\service\NatNodeService::state($node); list($used, $max) = \app\common\service\NatNodeService::capacity($node); ?>
  <div class="d-flex align-items-center mb-2">
    <span class="small">关联节点：<a href="<?= h(url_to('admin/nat_node/edit', ['id' => $node['id']])) ?>"><?= h($node['name']) ?> #<?= (int) $node['id'] ?></a></span>
    <span class="badge text-bg-<?= h($st['badge']) ?> ms-auto nat-state" data-state="<?= h($st['code']) ?>"><?= h($st['label']) ?></span>
  </div>
  <dl class="row small mb-2">
    <dt class="col-5 col-sm-3 fw-normal text-muted">Agent 地址</dt><dd class="col-7 col-sm-9 mb-1 nat-agent-url" style="word-break:break-all"><?= h(!empty($cfg['agent_url']) ? $cfg['agent_url'] : '节点注册后自动填写') ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">Agent Token</dt><dd class="col-7 col-sm-9 mb-1"><?= !empty($cfg['agent_token']) ? '已自动保存（加密）' : '节点注册后自动填写' ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">公网 IP</dt><dd class="col-7 col-sm-9 mb-1"><?= h(!empty($cfg['public_ip']) ? $cfg['public_ip'] : '-') ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">最后心跳</dt><dd class="col-7 col-sm-9 mb-1"><?= h(fmt_time($node['last_heartbeat'], 'Y-m-d H:i:s')) ?></dd>
    <dt class="col-5 col-sm-3 fw-normal text-muted">容量</dt><dd class="col-7 col-sm-9 mb-1"><?= (int) $used ?> / <?= (int) $max ?> 台</dd>
  </dl>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-primary" href="<?= h(url_to('admin/nat_node/index', ['show' => $node['id']])) ?>">生成安装命令</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(url_to('admin/nat_node/edit', ['id' => $node['id']])) ?>">节点参数</a>
  </div>
<?php elseif ($upId): ?>
  <div class="alert alert-warning small mb-2">这个上游还没有关联节点。添加节点后会生成一键安装命令，节点注册成功后 Agent 地址、令牌、公网 IP 会自动填到这里。</div>
  <div class="d-flex flex-wrap gap-2">
    <input type="hidden" name="upstream_id" value="<?= (int) $upId ?>">
    <button class="btn btn-sm btn-success nat-quick" formaction="<?= h(url_to('admin/nat_node/quick')) ?>" formmethod="post" formnovalidate>一键添加节点（默认参数）</button>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(url_to('admin/nat_node/edit', ['upstream_id' => $upId])) ?>">自定义参数添加</a>
  </div>
<?php else: ?>
  <div class="alert alert-info small mb-0">NAT 节点<b>不需要手填</b> Agent 地址和令牌：点「保存」后会自动用默认参数添加节点（端口段、内网段自动避开已有节点），并直接显示一键安装命令；节点装好并注册后，Agent 地址、令牌、公网 IP 会自动填到这个上游，产品选它即可销售。</div>
<?php endif; ?>
</div>
