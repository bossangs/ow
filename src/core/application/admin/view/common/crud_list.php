<?php
$canAdd = isset($canAdd) ? $canAdd : true;
$canEdit = isset($canEdit) ? $canEdit : true;
$canDelete = isset($canDelete) ? $canDelete : true;
$topActions = isset($topActions) ? (array) $topActions : [];
$rowActions = isset($rowActions) ? (array) $rowActions : [];
$cycles = billing_cycles();

/** 把字段值转成可读文本；控制器可在行里预置 {字段}_text 或 {字段}_html */
$display = function ($k, $f, $r) use ($cycles) {
    if (isset($r[$k . '_text'])) {
        return (string) $r[$k . '_text'];
    }
    $type = isset($f['type']) ? $f['type'] : 'text';
    $v = isset($r[$k]) ? $r[$k] : '';
    if ($k === 'billing_cycle' && isset($cycles[(string) $v])) {
        return $cycles[(string) $v]['name'];
    }
    if ($type === 'password' || $type === 'driver-config') {
        return '******';
    }
    if ($type === 'switch' || $type === 'checkbox') {
        return $v ? '是' : '否';
    }
    if ($type === 'select' && isset($f['options'][(string) $v])) {
        return $f['options'][(string) $v];
    }
    if ($type === 'multiselect') {
        if ((string) $v === '') {
            return '不限';
        }
        $names = [];
        foreach (explode(',', (string) $v) as $one) {
            $names[] = isset($f['options'][$one]) ? $f['options'][$one] : $one;
        }

        return implode('、', $names);
    }
    if ($type === 'pricing') {
        $p = json_decode((string) $v, true);
        $parts = [];
        foreach ((array) $p as $c => $price) {
            if ((string) $price !== '-1' && isset($cycles[$c])) {
                $parts[] = $cycles[$c]['name'] . ' ' . $price;
            }
        }

        return $parts ? implode(' / ', $parts) : '未定价';
    }
    if ($type === 'lines') {
        $items = json_decode((string) $v, true);

        return is_array($items) ? implode('、', $items) : (string) $v;
    }
    if (($type === 'datetime' || substr($k, -5) === '_time') && is_numeric($v)) {
        return $v ? fmt_time($v) : '-';
    }
    $s = (string) $v;

    return mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s;
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h2 class="h4 mb-0"><?= h($pageTitle) ?></h2>
  <span class="text-muted small">共 <?= (int) $total ?> 条</span>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($canAdd): ?>
      <a class="btn btn-primary" href="<?= h(url_to('admin/' . $ctrl . '/edit')) ?>">新增</a>
    <?php endif; ?>
    <?php foreach ($topActions as $a): ?>
      <?php if (!empty($a['post'])): ?>
        <form class="d-inline" method="post" action="<?= h($a['url']) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-outline-primary"<?= !empty($a['confirm']) ? ' data-confirm="' . h($a['confirm']) . '"' : '' ?>><?= h($a['label']) ?></button>
        </form>
      <?php else: ?>
        <a class="btn btn-outline-primary" href="<?= h($a['url']) ?>"><?= h($a['label']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($search): ?>
  <form class="row g-2 mb-3" method="get" action="<?= h(url_to('admin/' . $ctrl . '/index')) ?>">
    <?php if (isset($_GET['s'])): ?><input type="hidden" name="s" value="<?= h($_GET['s']) ?>"><?php endif; ?>
    <div class="col-sm-6 col-md-4">
      <input class="form-control" name="kw" value="<?= h(isset($_GET['kw']) ? $_GET['kw'] : '') ?>" placeholder="搜索">
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary">搜索</button></div>
  </form>
<?php endif; ?>

<div class="table-responsive table-card">
  <table class="table table-hover align-middle">
    <thead>
      <tr>
        <th>ID</th>
        <?php foreach ($fields as $k => $f): ?>
          <?php if (isset($f['list']) && !$f['list']) {
              continue;
          } ?>
          <th class="text-nowrap"><?= h($f[0]) ?></th>
        <?php endforeach; ?>
        <th>操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($list)): ?>
        <tr><td colspan="99" class="text-center text-muted py-4">暂无记录</td></tr>
      <?php endif; ?>
      <?php foreach ($list as $r): ?>
        <tr>
          <td><?= h($r['id']) ?></td>
          <?php foreach ($fields as $k => $f): ?>
            <?php if (isset($f['list']) && !$f['list']) {
                continue;
            } ?>
            <td><?php if (isset($r[$k . '_html'])): ?><?= $r[$k . '_html'] ?><?php elseif (!empty($f['badge'])): ?><?= status_badge(isset($r[$k]) ? $r[$k] : '', $f['badge']) ?><?php else: ?><?= h($display($k, $f, $r)) ?><?php endif; ?></td>
          <?php endforeach; ?>
          <td class="text-nowrap">
            <?php if ($canEdit): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(url_to('admin/' . $ctrl . '/edit', ['id' => $r['id']])) ?>">编辑</a>
            <?php endif; ?>
            <?php foreach ($rowActions as $a): ?>
              <?php
              if (isset($a['when']) && is_callable($a['when']) && !call_user_func($a['when'], $r)) {
                  continue;
              }
                $actionUrl = is_callable($a['url']) ? call_user_func($a['url'], $r) : $a['url'];
                ?>
              <?php if (!empty($a['post'])): ?>
                <form class="d-inline" method="post" action="<?= h($actionUrl) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                  <?php foreach ((array) (isset($a['extra']) ? $a['extra'] : []) as $ek => $ev): ?>
                    <input type="hidden" name="<?= h($ek) ?>" value="<?= h($ev) ?>">
                  <?php endforeach; ?>
                  <button class="btn btn-sm btn-outline-<?= h(isset($a['style']) ? $a['style'] : 'secondary') ?>"<?= !empty($a['confirm']) ? ' data-confirm="' . h($a['confirm']) . '"' : '' ?>><?= h($a['label']) ?></button>
                </form>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($actionUrl) ?>"><?= h($a['label']) ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($canDelete): ?>
              <form class="d-inline" method="post" action="<?= h(url_to('admin/' . $ctrl . '/delete')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <button class="btn btn-sm btn-outline-danger" data-confirm="确定删除此记录？">删除</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= pager_html($total, $page, $size, isset($_GET) ? $_GET : []) ?>
