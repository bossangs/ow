<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">工单支持</h1>
        <p class="text-muted mb-0">提交问题、查看处理进度并与支持团队沟通。</p>
    </div>
    <a class="btn btn-primary" href="<?= h(url_to('index/ticket/create')) ?>">新建工单</a>
</div>
<form class="card card-body mb-3" method="get" action="<?= h(url_to('index/ticket/index')) ?>">
    <div class="row g-2">
        <div class="col-md-5"><label class="visually-hidden">搜索</label><input class="form-control" name="kw" value="<?= h($kw) ?>" placeholder="搜索工单主题"></div>
        <div class="col-md-4"><label class="visually-hidden">状态</label><select class="form-select" name="status"><option value="">全部状态</option><?php foreach ($statuses as $key => $label): ?><option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><button class="btn btn-outline-primary w-100">筛选</button></div>
    </div>
</form>
<div class="table-card table-responsive">
    <table class="table align-middle mb-0">
        <thead><tr><th>主题</th><th>部门</th><th>优先级</th><th>状态</th><th>更新时间</th></tr></thead>
        <tbody>
        <?php foreach ($tickets as $ticket): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= h(url_to('index/ticket/detail', array('id' => $ticket['id']))) ?>"><?= h($ticket['subject']) ?></a><?php if (!empty($ticket['unread'])): ?> <span class="badge text-bg-danger">未读</span><?php endif; ?><?php if ((int)$ticket['seller_user_id'] === (int)$user['id']): ?> <span class="badge text-bg-info">卖家工单</span><?php endif; ?></td>
                <td><?= h($ticket['department']) ?></td><td><?= h($ticket['priority']) ?></td>
                <td><?= h(isset($statuses[$ticket['status']]) ? $statuses[$ticket['status']] : $ticket['status']) ?></td><td class="text-muted small"><?= h(fmt_time($ticket['update_time'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$tickets): ?><tr><td colspan="5" class="empty py-5">暂无工单</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
