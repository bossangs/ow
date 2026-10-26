<?php
// 内置默认图片（未上传时显示），用于预览
$brandDefaults = [
    'site_logo' => brand_logo_url(false, true),
    'site_logo_light' => brand_logo_url(true, true),
    'site_favicon' => brand_favicon_url(),
];
$brandAccept = [
    'site_logo' => '.png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp',
    'site_logo_light' => '.png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp',
    'site_favicon' => '.ico,.png,image/x-icon,image/png',
];
?>
<h2 class="h4 mb-3">系统设置</h2>
<form method="post" class="card p-3" enctype="multipart/form-data" id="settingsForm">
  <?= csrf_field() ?>
  <ul class="nav nav-tabs flex-wrap mb-3" role="tablist">
    <?php $first = true; foreach ($groups as $gid => $g): ?>
      <li class="nav-item">
        <button class="nav-link <?= $first ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#<?= h($gid) ?>" type="button"><?= h($g[0]) ?></button>
      </li>
    <?php $first = false; endforeach; ?>
  </ul>
  <div class="tab-content">
    <?php $first = true; foreach ($groups as $gid => $g): ?>
      <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="<?= h($gid) ?>">
        <div class="row">
          <?php foreach ($g[1] as $key):
              $d = $defs[$key];
              $type = $d[1];
              $v = $values[$key];
              $help = isset($d['help']) ? $d['help'] : '';
              ?>
            <div class="<?= $type === 'image' ? 'col-12 col-lg-4' : 'col-md-6' ?> mb-3">
              <label class="form-label"><?= h($d[0]) ?></label>
              <?php if ($type === 'switch'): ?>
                <div class="form-check form-switch">
                  <input type="hidden" name="<?= h($key) ?>" value="0">
                  <input class="form-check-input" type="checkbox" name="<?= h($key) ?>" value="1" <?= $v === '1' ? 'checked' : '' ?>>
                </div>
              <?php elseif ($type === 'select'): ?>
                <select class="form-select" name="<?= h($key) ?>">
                  <?php foreach ($d[2] as $ov => $ol): ?>
                    <option value="<?= h($ov) ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>><?= h($ol) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($type === 'textarea'): ?>
                <textarea class="form-control" name="<?= h($key) ?>" rows="3"><?= h($v) ?></textarea>
              <?php elseif ($type === 'image'): ?>
                <div class="brand-upload <?= $key === 'site_logo_light' ? 'is-dark' : '' ?>">
                  <div class="brand-preview">
                    <img src="<?= h($brandDefaults[$key]) ?>" alt="<?= h($d[0]) ?>" data-preview="<?= h($key) ?>">
                  </div>
                  <div class="small text-muted mb-2">
                    <?= $v !== '' ? '当前：已上传自定义图片' : '当前：使用内置 o2o云 默认图片' ?>
                  </div>
                  <input class="form-control form-control-sm" type="file" name="<?= h($key) ?>" accept="<?= h($brandAccept[$key]) ?>" data-preview-input="<?= h($key) ?>">
                  <?php if ($v !== ''): ?>
                    <div class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" name="<?= h($key) ?>_reset" value="1" id="<?= h($key) ?>_reset">
                      <label class="form-check-label small" for="<?= h($key) ?>_reset">恢复默认（删除已上传的图片）</label>
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <input class="form-control" type="<?= $type === 'number' ? 'number' : ($type === 'password' ? 'password' : 'text') ?>" name="<?= h($key) ?>" value="<?= $type === 'password' ? '' : h($v) ?>">
              <?php endif; ?>
              <?php if ($help !== ''): ?>
                <div class="form-help"><?= h($help) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($gid === 'smtp'): ?>
          <div class="border rounded p-3 mb-2">
            <label class="form-label" for="testMailTo">发送测试邮件</label>
            <div class="input-group">
              <input class="form-control" type="email" id="testMailTo" name="to" form="testMailForm" placeholder="收件邮箱" value="<?= h(setting('admin_notify_email', '')) ?>">
              <button class="btn btn-outline-primary" type="submit" form="testMailForm">发送</button>
            </div>
            <div class="form-help">请先「保存设置」再测试；测试邮件使用当前的品牌邮件模板。</div>
          </div>
        <?php endif; ?>
        <?php if ($gid === 'telegram'): ?>
          <div class="border rounded p-3 mb-2">
            <label class="form-label" for="testTgChat">发送测试消息</label>
            <div class="input-group">
              <input class="form-control" id="testTgChat" name="chat_id" form="testTgForm" placeholder="Chat ID" value="<?= h(setting('tg_admin_chat_id', '')) ?>">
              <button class="btn btn-outline-primary" type="submit" form="testTgForm">发送</button>
            </div>
            <div class="form-help">请先「保存设置」再测试。</div>
          </div>
        <?php endif; ?>
        <?php if ($gid === 'cron'):
            $cronUrl = url_to('api/cron/run', ['key' => setting('cron_key', '')], true);
            ?>
          <div class="border rounded p-3">
            <p><strong>CLI：</strong><code>php &lt;WEB_ROOT&gt;/cron.php</code></p>
            <p class="mb-1"><strong>Web URL：</strong>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#cron-url">复制</button>
            </p>
            <input id="cron-url" class="form-control mb-2 mono" value="<?= h($cronUrl) ?>" readonly>
            <p class="text-muted">访问触发仅是无计划任务主机的兜底方案。宝塔可设置“访问URL”每 5 分钟；虚拟主机面板可定时访问，也可使用 cron-job.org。</p>
            <p class="mb-1">最近运行：<?= h(setting('cron_last_run', '未运行')) ?>；结果：</p>
            <pre class="bg-light p-2 rounded small"><?= h(setting('cron_last_result', '')) ?></pre>
            <!-- 独立表单（放在设置表单外面，见页面底部），这里只是触发按钮 -->
            <button class="btn btn-outline-danger" type="submit" form="cronKeyForm" data-confirm="重新生成后旧的 Web 计划任务地址立即失效，确定？">重新生成密钥</button>
          </div>
        <?php endif; ?>
      </div>
    <?php $first = false; endforeach; ?>
  </div>
  <div class="mt-3"><button class="btn btn-primary">保存设置</button></div>
</form>
<form method="post" id="testMailForm" action="<?= h(url_to('admin/settings/testMail')) ?>"><?= csrf_field() ?></form>
<form method="post" id="testTgForm" action="<?= h(url_to('admin/settings/testTelegram')) ?>"><?= csrf_field() ?></form>
<form method="post" id="cronKeyForm" action="<?= h(url_to('admin/settings/regenerateCronKey')) ?>"><?= csrf_field() ?></form>
<script>
(function () {
  // 选择图片后立即预览（仅本地预览，保存后才生效）
  document.querySelectorAll('[data-preview-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      var img = document.querySelector('[data-preview="' + input.getAttribute('data-preview-input') + '"]');
      if (img && input.files && input.files[0] && window.URL) {
        img.src = URL.createObjectURL(input.files[0]);
      }
    });
  });
  // 保存后回到原来的标签页
  var tabs = document.querySelectorAll('[data-bs-toggle="tab"]');
  tabs.forEach(function (b) {
    b.addEventListener('click', function () {
      try { sessionStorage.setItem('idc_settings_tab', b.getAttribute('data-bs-target')); } catch (e) {}
    });
  });
  var last = null;
  try { last = location.hash || sessionStorage.getItem('idc_settings_tab'); } catch (e) {}
  // bootstrap.bundle 在页面底部加载，等 load 后再切换标签
  window.addEventListener('load', function () {
    if (last && window.bootstrap && /^#[a-z_]+$/.test(last)) {
      var btn = document.querySelector('[data-bs-target="' + last + '"]');
      if (btn) { new bootstrap.Tab(btn).show(); }
    }
  });
})();
</script>
