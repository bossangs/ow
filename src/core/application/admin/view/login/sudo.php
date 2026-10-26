<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">请再次确认身份</h5>
        <p class="text-muted small">您正在进行敏感操作（例如修改支付网关、DNS 服务商密钥、上游接口、审批提现、大额余额调整或修改密码）。请输入谷歌验证器里当前的 6 位验证码，验证通过后 <?= (int) $minutes ?> 分钟内不再询问。</p>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label for="sudo-code">谷歌验证码</label>
            <input id="sudo-code" class="form-control form-control-lg text-center twofa-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" placeholder="6 位数字" required autofocus>
            <div class="form-text">也可以输入一个恢复码（每个只能用一次）。</div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary">确认</button>
            <a class="btn btn-outline-secondary" href="<?= h(url_to('admin/dashboard/index')) ?>">取消</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
