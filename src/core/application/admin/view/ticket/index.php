<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">工单 <span class="text-muted fs-6">共 <?= (int)$total ?> 条</span></h1>
    <div>
        <a class="btn btn-outline-secondary" href="<?= h(url_to('admin/ticket/canned')) ?>">快捷回复</a>
        <a class="btn btn-outline-secondary" href="<?= h(url_to('admin/ticket/departments')) ?>">部门管理</a>
        <a class="btn btn-outline-secondary" href="<?= h(url_to('admin/satisfaction/index')) ?>">满意度报表</a>
    </div>
</div>

<form class="card card-body mb-3" method="get" action="<?= h(url_to('admin/ticket/index')) ?>">
    <?php if (isset($_GET['s'])): ?><input type="hidden" name="s" value="<?= h($_GET['s']) ?>"><?php endif; ?>
    <div class="row g-2">
        <div class="col-6 col-md-2">
            <select class="form-select" name="status">
                <option value="">全部状态</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select" name="department">
                <option value="">全部部门</option>
                <?php foreach ($departments as $departmentRow): ?>
                    <option value="<?= h($departmentRow['name']) ?>" <?= $department === $departmentRow['name'] ? 'selected' : '' ?>><?= h($departmentRow['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select" name="priority">
                <option value="">全部优先级</option>
                <?php foreach ($priorities as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $priority === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select" name="assignee">
                <option value="">全部客服</option>
                <option value="0" <?= isset($_GET['assignee']) && $_GET['assignee'] === '0' ? 'selected' : '' ?>>未分配</option>
                <?php foreach ($admins as $adminId => $adminName): ?>
                    <option value="<?= (int)$adminId ?>" <?= (int)$assignee === (int)$adminId && $assignee ? 'selected' : '' ?>><?= h($adminName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <input class="form-control" name="kw" value="<?= h($kw) ?>" placeholder="主题 / 关联对象">
        </div>
        <div class="col-12 col-md-8 d-flex flex-wrap gap-3 align-items-center">
            <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="overdue" value="1" <?= $overdue ? 'checked' : '' ?>> <span class="form-check-label">仅超时</span></label>
            <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="rating" value="bad" <?= $rating === 'bad' ? 'checked' : '' ?>> <span class="form-check-label">仅差评（≤2 分）</span></label>
        </div>
        <div class="col-12 col-md-4 d-flex gap-2">
            <button class="btn btn-primary flex-fill">筛选</button>
            <a class="btn btn-light" href="<?= h(url_to('admin/ticket/index')) ?>">重置</a>
        </div>
    </div>
</form>

<div class="table-responsive">
    <table class="table align-middle">
        <thead>
            <tr><th>ID</th><th>主题</th><th>用户</th><th>部门</th><th>优先级</th><th>状态</th><th>客服</th><th>更新</th></tr>
        </thead>
        <tbody>
        <?php foreach ($list as $ticket): ?>
            <tr class="<?= !empty($ticket['overdue']) ? 'table-danger' : '' ?>">
                <td>#<?= (int)$ticket['id'] ?></td>
                <td>
                    <?php if (!empty($ticket['unread'])): ?><span class="badge text-bg-danger">新回复</span><?php endif; ?>
                    <a class="fw-semibold" href="<?= h(url_to('admin/ticket/detail', ['id' => $ticket['id']])) ?>"><?= h($ticket['subject']) ?></a>
                    <?php if ((int)$ticket['seller_user_id'] > 0): ?>
                        <span class="badge text-bg-info">卖家 <?= h(isset($users[(int)$ticket['seller_user_id']]) ? $users[(int)$ticket['seller_user_id']] : '#' . (int)$ticket['seller_user_id']) ?></span>
                    <?php endif; ?>
                    <?php if ($ticket['related'] !== ''): ?><div class="small text-muted mono"><?= h($ticket['related']) ?></div><?php endif; ?>
                </td>
                <td><?= h(isset($users[(int)$ticket['user_id']]) ? $users[(int)$ticket['user_id']] : '#' . (int)$ticket['user_id']) ?></td>
                <td><?= h($ticket['department']) ?></td>
                <td><?= h($ticket['priority']) ?></td>
                <td><?= h(isset($statuses[$ticket['status']]) ? $statuses[$ticket['status']] : $ticket['status']) ?></td>
                <td class="small"><?= h(isset($admins[(int)$ticket['last_admin_id']]) ? $admins[(int)$ticket['last_admin_id']] : '—') ?></td>
                <td class="small">
                    <?php if (!empty($ticket['overdue'])): ?>
                        <span class="text-danger fw-semibold">超时 <?= (int)floor((time() - (int)$ticket['waiting_since']) / 3600) ?> 小时</span>
                    <?php else: ?>
                        <?= h(fmt_time($ticket['update_time'])) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?>
            <tr><td colspan="8" class="empty py-5">暂无工单</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= pager_html($total, $page, $size, ['status' => $status, 'department' => $department, 'priority' => $priority, 'kw' => $kw, 'overdue' => $overdue ?: '', 'rating' => $rating], 'admin/ticket/index') ?>
