<?php
/**
 * 通用编辑表单
 * 支持类型：text number email password textarea switch checkbox select multiselect
 *          json-kv lines pricing datetime lifecycle html driver-config
 */
$row = isset($row) && is_array($row) ? $row : [];
$ctrl = isset($ctrl) ? $ctrl : '';
$id = isset($id) ? $id : 0;
$formAction = isset($formAction) ? $formAction : '';
$backUrl = isset($backUrl) ? $backUrl : url_to('admin/' . $ctrl . '/index');
?>
<div class="d-flex align-items-center mb-3">
  <h2 class="h4 mb-0"><?= h($pageTitle) ?><?php if (!empty($id)): ?> <small class="text-muted">#<?= h($id) ?></small><?php endif; ?></h2>
</div>
<form method="post" class="card p-3 admin-form"<?php if ($formAction !== ''): ?> action="<?= h($formAction) ?>"<?php endif; ?>>
  <?= csrf_field() ?>
  <?php foreach ($fields as $k => $f): ?>
    <?php
    $type = isset($f['type']) ? $f['type'] : 'text';
      if (!empty($f['readonly']) && $type !== 'html') {
          if (!empty($f['show_readonly']) && isset($row[$k])) {
              echo '<div class="mb-3"><label class="form-label">' . h($f[0]) . '</label>'
                  . '<div class="form-control-plaintext">' . h($row[$k]) . '</div></div>';
          }
          continue;
      }
      $v = isset($row[$k]) ? $row[$k] : (isset($f['default']) ? $f['default'] : '');
      $req = !empty($f['required']) ? 'required' : '';
      $fid = 'f_' . $k;
      $showWhen = isset($f['show_when']) ? ' data-show-when="' . h($f['show_when']) . '"' : '';
      ?>
    <div class="mb-3"<?= $showWhen ?>>
      <?php if ($type !== 'driver-config'): ?>
        <label class="form-label" for="<?= h($fid) ?>"><?= h($f[0]) ?><?php if ($req): ?> <span class="text-danger">*</span><?php endif; ?></label>
      <?php endif; ?>

      <?php if ($type === 'textarea' || $type === 'json-kv'): ?>
        <textarea class="form-control<?= $type === 'json-kv' ? ' font-monospace' : '' ?>" id="<?= h($fid) ?>" name="<?= h($k) ?>" rows="<?= $type === 'json-kv' ? 5 : 4 ?>" <?= $req ?>><?= h($v) ?></textarea>

      <?php elseif ($type === 'lines'): ?>
        <?php $items = json_decode((string) $v, true);
          $text = is_array($items) ? implode("\n", $items) : (string) $v; ?>
        <textarea class="form-control" id="<?= h($fid) ?>" name="<?= h($k) ?>" rows="4" placeholder="每行一项"><?= h($text) ?></textarea>

      <?php elseif ($type === 'switch' || $type === 'checkbox'): ?>
        <div class="form-check form-switch">
          <input type="hidden" name="<?= h($k) ?>" value="0">
          <input class="form-check-input" type="checkbox" id="<?= h($fid) ?>" name="<?= h($k) ?>" value="1" <?= $v ? 'checked' : '' ?>>
        </div>

      <?php elseif ($type === 'select'): ?>
        <select class="form-select" id="<?= h($fid) ?>" name="<?= h($k) ?>" <?= $req ?>>
          <?php foreach ((array) $f['options'] as $ov => $ol): ?>
            <option value="<?= h($ov) ?>" <?= (string) $v === (string) $ov ? 'selected' : '' ?>><?= h($ol) ?></option>
          <?php endforeach; ?>
        </select>

      <?php elseif ($type === 'multiselect'): ?>
        <?php $sel = array_filter(explode(',', (string) $v), 'strlen'); ?>
        <select class="form-select" id="<?= h($fid) ?>" name="<?= h($k) ?>[]" multiple size="<?= min(8, max(3, count((array) $f['options']))) ?>">
          <?php foreach ((array) $f['options'] as $ov => $ol): ?>
            <option value="<?= h($ov) ?>" <?= in_array((string) $ov, $sel, true) ? 'selected' : '' ?>><?= h($ol) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-help">按住 Ctrl / ⌘ 可多选；不选 = 不限制</div>

      <?php elseif ($type === 'pricing'): ?>
        <?php $pv = json_decode((string) $v, true);
          $pv = is_array($pv) ? $pv : []; ?>
        <div class="row g-2">
          <?php foreach (billing_cycles() as $c => $info): ?>
            <?php $cv = isset($pv[$c]) && (string) $pv[$c] !== '-1' ? $pv[$c] : ''; ?>
            <div class="col-6 col-md-4 col-lg-3">
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= h($info['name']) ?></span>
                <input class="form-control" type="number" step="0.01" min="0" name="<?= h($k . '_' . $c) ?>" value="<?= h($cv) ?>" placeholder="不提供">
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      <?php elseif ($type === 'datetime'): ?>
        <input class="form-control" type="datetime-local" id="<?= h($fid) ?>" name="<?= h($k) ?>" value="<?= $v ? h(date('Y-m-d\TH:i', (int) $v)) : '' ?>">

      <?php elseif ($type === 'lifecycle'): ?>
        <?php $lv = json_arr($v); ?>
        <?php foreach (\app\common\service\LifecycleService::overrideFields() as $lk => $lf): ?>
          <?php $cur = isset($lv[$lk]) ? (string) $lv[$lk] : '';
            $name = $k . '_' . $lk; ?>
          <div class="row g-2 mb-2 align-items-center">
            <div class="col-md-5"><span class="form-text"><?= h($lf[0]) ?></span></div>
            <div class="col-md-7">
              <?php if ($lf[1] === 'bool'): ?>
                <select class="form-select form-select-sm" name="<?= h($name) ?>">
                  <option value="">继承全局</option>
                  <option value="1" <?= $cur === '1' ? 'selected' : '' ?>>启用</option>
                  <option value="0" <?= $cur === '0' ? 'selected' : '' ?>>停用</option>
                </select>
              <?php elseif ($lf[1] === 'mode'): ?>
                <select class="form-select form-select-sm" name="<?= h($name) ?>">
                  <option value="">继承全局</option>
                  <option value="pause" <?= $cur === 'pause' ? 'selected' : '' ?>>暂停保留</option>
                  <option value="remove" <?= $cur === 'remove' ? 'selected' : '' ?>>删除记录</option>
                </select>
              <?php else: ?>
                <input class="form-control form-control-sm" name="<?= h($name) ?>" value="<?= h($cur) ?>" placeholder="继承全局">
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

      <?php elseif ($type === 'driver-config'): ?>
        <?php
        $cfg = $v !== '' ? decrypt_json($v) : [];
          $fixed = !empty($f['driver']) ? $f['driver'] : '';
          $driverField = isset($f['driver_field']) ? $f['driver_field'] : 'driver';
          $curDriver = $fixed !== '' ? $fixed : (isset($row[$driverField]) ? (string) $row[$driverField] : '');
          ?>
        <?php foreach ((array) $f['drivers'] as $dcode => $dinfo): ?>
          <?php if ($fixed !== '' && $dcode !== $fixed) {
              continue;
          } ?>
          <fieldset class="border rounded p-3 mb-2"<?php if ($fixed === ''): ?> data-show-when="#f_<?= h($driverField) ?>=<?= h($dcode) ?>"<?php endif; ?>>
            <legend class="fs-6 fw-semibold w-auto px-1 mb-2"><?= h($dinfo['name']) ?> 配置</legend>
            <?php foreach ((array) $dinfo['fields'] as $df): ?>
              <?php
              $dk = $df['key'];
                $dname = 'cfg_' . $dcode . '_' . $dk;
                $dtype = isset($df['type']) ? $df['type'] : 'text';
                $isCur = $dcode === $curDriver;
                $dv = $isCur && isset($cfg[$dk]) ? $cfg[$dk] : (isset($df['default']) ? $df['default'] : '');
                $secret = !empty($df['secret']) || $dtype === 'password';
                $hasSecret = $secret && $isCur && isset($cfg[$dk]) && $cfg[$dk] !== '';
                ?>
              <div class="mb-2">
                <label class="form-label small mb-1"><?= h($df['label']) ?><?php if (!empty($df['required'])): ?> <span class="text-danger">*</span><?php endif; ?><?php if ($secret): ?> <span class="badge text-bg-light">加密保存</span><?php endif; ?></label>
                <?php if ($dtype === 'select'): ?>
                  <select class="form-select form-select-sm" name="<?= h($dname) ?>">
                    <?php foreach ((array) $df['options'] as $ov => $ol): ?>
                      <option value="<?= h($ov) ?>" <?= (string) $dv === (string) $ov ? 'selected' : '' ?>><?= h($ol) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($dtype === 'textarea'): ?>
                  <textarea class="form-control form-control-sm<?= $secret ? ' font-monospace' : '' ?>" name="<?= h($dname) ?>" rows="3" placeholder="<?= $hasSecret ? '已设置，留空表示不修改' : '' ?>"><?= $secret ? '' : h($dv) ?></textarea>
                <?php else: ?>
                  <input class="form-control form-control-sm" type="<?= $secret ? 'password' : ($dtype === 'number' ? 'number' : 'text') ?>" name="<?= h($dname) ?>" value="<?= $secret ? '' : h($dv) ?>" placeholder="<?= $hasSecret ? '已设置，留空表示不修改' : '' ?>" autocomplete="off">
                <?php endif; ?>
                <?php if (!empty($df['help'])): ?><div class="form-help"><?= h($df['help']) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!empty($dinfo['doc'])): ?>
              <div class="form-help">接口文档：<a href="<?= h($dinfo['doc']) ?>" target="_blank" rel="noopener"><?= h($dinfo['doc']) ?></a></div>
            <?php endif; ?>
          </fieldset>
        <?php endforeach; ?>

      <?php elseif ($type === 'html'): ?>
        <div class="form-text"><?= h(isset($f['help']) ? $f['help'] : '') ?></div>

      <?php else: ?>
        <?php $inputType = in_array($type, ['number', 'password', 'email'], true) ? $type : 'text'; ?>
        <input class="form-control" type="<?= h($inputType) ?>" id="<?= h($fid) ?>" name="<?= h($k) ?>"<?= $type === 'number' ? ' step="any"' : '' ?> value="<?= $type === 'password' ? '' : h($v) ?>" <?= $req ?><?= $type !== 'password' && !empty($f['placeholder']) ? ' placeholder="' . h($f['placeholder']) . '"' : '' ?><?= $type === 'password' && $id ? ' placeholder="留空表示不修改"' : '' ?>>
      <?php endif; ?>

      <?php if (!empty($f['help']) && $type !== 'html'): ?><div class="form-help"><?= h($f['help']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div>
    <button class="btn btn-primary">保存</button>
    <a class="btn btn-outline-secondary" href="<?= h($backUrl) ?>">返回</a>
  </div>
</form>
