<?php
/** @var string $msg */
/** @var int $status */
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>提示</title>
<link rel="stylesheet" href="<?= h(static_url('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= h(static_url('css/app.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-5" style="max-width:560px">
  <div class="card shadow-sm border-0">
    <div class="card-body p-4 text-center">
      <div class="display-6 mb-3 text-warning"><?= (int)$status >= 500 ? '⚙' : '!' ?></div>
      <h1 class="h5 mb-3"><?= h($msg) ?></h1>
      <div class="d-flex justify-content-center gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="javascript:history.back()">返回上一页</a>
        <a class="btn btn-primary btn-sm" href="<?= h(url_to('')) ?>">回到首页</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
