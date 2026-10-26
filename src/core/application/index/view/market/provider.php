<?php
$editId = isset($editId) ? (int)$editId : 0;
$editRow = null;
foreach ($providers as $p) {
    if ((int)$p['id'] === $editId) {
        $editRow = $p;
    }
}
$current = $editRow ? $editRow['driver'] : '';
if ($current === '' || !isset($drivers[$current])) {
    foreach ($drivers as $code => $d) {
        $current = $code;
        break;
    }
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h1 class="h3 mb-0">DNS 凭据</h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= h(url_to('index/market/index')) ?>">返回我的域名发布</a>
</div>
<p class="text-muted small">把您自己 DNS 账户的 API 凭据保存在这里，系统会先做健康检查，通过后才能选择账户里的域名发布到市场。凭据加密保存，密钥类字段不会再显示；编辑时留空表示不修改。</p>

<div class="card mb-4">
  <div class="card-body">
    <h2 class="h6 mb-3"><?= $editRow ? '编辑凭据：' . h($editRow['name']) : '添加凭据' ?></h2>
    <form method="post" id="provider-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label" for="driver">DNS 服务商</label>
          <select class="form-select" name="driver" id="driver" <?= $editRow ? 'disabled' : '' ?>>
            <?php foreach ($drivers as $code => $d): ?>
            <option value="<?= h($code) ?>" <?= $code === $current ? 'selected' : '' ?>><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($editRow): ?><input type="hidden" name="driver" value="<?= h($current) ?>"><?php endif; ?>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="pname">凭据名称</label>
          <input class="form-control" id="pname" name="name" maxlength="100" placeholder="例如：我的 Cloudflare" value="<?= $editRow ? h($editRow['name']) : '' ?>">
        </div>
      </div>
      <?php foreach ($drivers as $code => $d): ?>
      <fieldset class="driver-panel mt-3 <?= $code === $current ? '' : 'd-none' ?>" data-driver="<?= h($code) ?>" <?= $code === $current ? '' : 'disabled' ?>>
        <?php foreach ($d['fields'] as $f): ?>
          <?php if ($f['key'] === 'endpoint') {
              continue;
          } // 接口地址由管理员统一配置?>
          <div class="mb-2">
            <label class="form-label"><?= h($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
            <?php if (!empty($f['options']) && is_array($f['options'])): ?>
              <select class="form-select" name="config[<?= h($f['key']) ?>]">
                <?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= h($ov) ?>"><?= h($ol) ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="form-control" autocomplete="off" type="<?= !empty($f['secret']) ? 'password' : 'text' ?>" name="config[<?= h($f['key']) ?>]"
                     placeholder="<?= !empty($f['secret']) && $editRow ? '留空表示不修改' : '' ?>">
            <?php endif; ?>
            <?php if (!empty($f['help'])): ?><div class="form-help"><?= h($f['help']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </fieldset>
      <?php endforeach; ?>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary" type="submit">保存并检查</button>
        <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="<?= h(url_to('index/market/provider')) ?>">取消编辑</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="table-card table-responsive">
  <table class="table align-middle mb-0">
    <thead><tr><th>名称</th><th>服务商</th><th>状态</th><th>最近检查</th><th></th></tr></thead>
    <tbody>
    <?php if (!$providers): ?>
      <tr><td colspan="5" class="text-center text-muted py-4">还没有保存凭据</td></tr>
    <?php endif; ?>
    <?php foreach ($providers as $p): ?>
      <tr>
        <td><?= h($p['name']) ?></td>
        <td><?= h(isset($drivers[$p['driver']]) ? $drivers[$p['driver']]['name'] : $p['driver']) ?></td>
        <td><?= (int)$p['status'] === 1 ? '<span class="badge bg-success">正常</span>' : '<span class="badge bg-danger">异常</span>' ?></td>
        <td class="small text-muted"><?= !empty($p['last_test_time']) ? h(date('Y-m-d H:i', $p['last_test_time'])) : '-' ?> <?= h(isset($p['last_test_msg']) ? $p['last_test_msg'] : '') ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url_to('index/market/provider', ['edit' => $p['id']])) ?>">编辑</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
(function () {
  var sel = document.getElementById('driver');
  function sync() {
    document.querySelectorAll('.driver-panel').forEach(function (fs) {
      var on = fs.getAttribute('data-driver') === sel.value;
      fs.classList.toggle('d-none', !on);
      fs.disabled = !on; // 只提交当前服务商的字段，避免同名字段互相覆盖
    });
  }
  sel.addEventListener('change', sync);
})();
</script>
