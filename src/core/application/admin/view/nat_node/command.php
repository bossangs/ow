<?php
/** @var array $n @var array $info @var bool $partial */
$st = $n['state'];
?>
<?php if (!$partial): ?>
<div class="d-flex align-items-center gap-2 mb-3">
  <h2 class="h4 mb-0">一键安装命令</h2>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= h(url_to('admin/nat_node/index')) ?>">返回节点列表</a>
</div>
<div class="card p-3">
<?php endif; ?>
<div class="nat-cmd" data-node-id="<?= (int) $n['id'] ?>">
  <div class="d-flex align-items-center gap-2 mb-3">
    <div class="fw-semibold"><?= h($n['name']) ?> <span class="text-muted small">#<?= (int) $n['id'] ?></span></div>
    <span class="badge text-bg-<?= h($st['badge']) ?> ms-auto"><?= h($st['label']) ?></span>
  </div>
  <?php if ($info['valid']): ?>
    <p class="small mb-2">用 <b>root</b> 登录节点服务器，复制下面任意一条命令运行。令牌只能用一次，<b><?= h(fmt_time($info['expire'])) ?></b> 前有效。</p>
    <?php foreach ($info['commands'] as $i => $cmd): ?>
      <div class="mb-3">
        <div class="d-flex align-items-center mb-1">
          <span class="small fw-semibold"><?= $i === 0 ? '方式一：直接运行' : '方式二：先下载并校验 SHA-256，再运行（推荐）' ?></span>
          <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-copy-text="<?= h($cmd) ?>">复制</button>
        </div>
        <pre class="nat-cmd-line bg-dark text-light rounded p-2 mb-0 small" style="white-space:pre-wrap;word-break:break-all"><?= h($cmd) ?></pre>
      </div>
    <?php endforeach; ?>
    <dl class="row small mb-3">
      <dt class="col-sm-3 fw-normal text-muted">脚本地址</dt>
      <dd class="col-sm-9"><code class="nat-script-url" style="word-break:break-all"><?= h($info['script_url']) ?></code>
        <button type="button" class="btn btn-sm btn-link p-0 ms-1 align-baseline" data-copy-text="<?= h($info['script_url']) ?>">复制</button></dd>
      <dt class="col-sm-3 fw-normal text-muted">SHA-256</dt>
      <dd class="col-sm-9"><code class="nat-script-sha" style="word-break:break-all"><?= h($info['sha256']) ?></code></dd>
      <dt class="col-sm-3 fw-normal text-muted">令牌过期</dt>
      <dd class="col-sm-9"><?= h(fmt_time($info['expire'])) ?>（一次性，节点注册成功后自动作废）</dd>
    </dl>
  <?php elseif ($info['registered']): ?>
    <div class="alert alert-success small">节点已注册，注册令牌已自动作废。如需在新服务器上重装，请点下面的「重新生成令牌」获取新命令。</div>
  <?php elseif ($info['error'] !== ''): ?>
    <div class="alert alert-danger small">节点参数有误：<?= h($info['error']) ?>。请先 <a href="<?= h(url_to('admin/nat_node/edit', ['id' => $n['id']])) ?>">编辑节点</a>。</div>
  <?php else: ?>
    <div class="alert alert-warning small">注册令牌已过期，旧命令无法再使用。请点下面的「重新生成令牌」。</div>
  <?php endif; ?>
  <div class="small text-muted border-top pt-2 mb-3">
    <div class="fw-semibold text-body mb-1">注意事项</div>
    <ul class="ps-3 mb-0">
      <li>仅支持 <b>Debian 11/12/13、Ubuntu 20.04/22.04/24.04</b>（64 位），建议用全新系统。</li>
      <li>在服务商的<b>安全组 / 防火墙</b>放行 TCP <b><?= (int) $info['agent_port'] ?></b>（面板连接 Agent 用）和 TCP/UDP <b><?= h($info['port_range']) ?></b>（小鸡端口转发用）。</li>
      <li>节点需要能访问本面板地址；安装完成后刷新节点列表，状态会变成「在线」，并自动创建/更新关联的上游。</li>
    </ul>
  </div>
  <form method="post" action="<?= h(url_to('admin/nat_node/regen')) ?>" class="d-flex flex-wrap align-items-center gap-2">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
    <button class="btn btn-outline-warning btn-sm" data-confirm="重新生成后，旧令牌和旧安装命令会立即失效，确定继续？">重新生成令牌</button>
    <span class="small text-muted">已开启两步验证时需要输入谷歌验证码</span>
  </form>
</div>
<?php if (!$partial): ?>
</div>
<?php endif; ?>
