<h2 class="h4">修改密码</h2>
<form method="post" class="card p-3" style="max-width:520px" autocomplete="off">
  <?= csrf_field() ?>
  <label class="mb-3">原密码<input class="form-control" type="password" name="old_password" autocomplete="current-password" required></label>
  <label class="mb-1">新密码<input class="form-control" type="password" name="password" minlength="8" autocomplete="new-password" required></label>
  <div class="form-text mb-3">8-64 位，必须同时包含字母和数字，不能与用户名或邮箱相同。修改后其他设备上的后台登录会退出，并发邮件通知您。</div>
  <label class="mb-3">确认新密码<input class="form-control" type="password" name="password2" minlength="8" autocomplete="new-password" required></label>
  <div><button class="btn btn-primary">保存</button></div>
</form>
