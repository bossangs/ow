<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">工单部门</h1>
    <a class="btn btn-light" href="<?= h(url_to('admin/ticket/index')) ?>">返回工单</a>
</div>

<form method="post" action="<?= h(url_to('admin/ticket/departments')) ?>" class="card card-body mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="row g-2">
        <div class="col-md-7"><input class="form-control" name="name" maxlength="32" placeholder="部门名称，例如：财务、技术支持、域名市场" required></div>
        <div class="col-md-3"><input class="form-control" type="number" name="sort" value="0" placeholder="排序（越小越靠前）"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">新增</button></div>
    </div>
    <div class="form-text">每个部门可在“系统设置 → 工单”里单独设置超时催单小时数。停用的部门不会出现在用户提交页。</div>
</form>

<div class="table-responsive">
    <table class="table align-middle">
        <thead><tr><th>部门</th><th>工单数</th><th style="width: 220px">排序</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= h($item['name']) ?></td>
                <td><?= (int)(isset($counts[$item['name']]) ? $counts[$item['name']] : 0) ?></td>
                <td>
                    <form method="post" action="<?= h(url_to('admin/ticket/departments')) ?>" class="input-group input-group-sm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="sort">
                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                        <input class="form-control" type="number" name="sort" value="<?= (int)$item['sort'] ?>">
                        <button class="btn btn-outline-secondary">保存</button>
                    </form>
                </td>
                <td><?= (int)$item['status'] ? '<span class="badge text-bg-success">启用</span>' : '<span class="badge text-bg-secondary">停用</span>' ?></td>
                <td>
                    <form method="post" action="<?= h(url_to('admin/ticket/departments')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                        <button class="btn btn-sm <?= (int)$item['status'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"><?= (int)$item['status'] ? '停用' : '启用' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
