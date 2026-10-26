<?php
$statusName = isset($statuses[$ticket['status']]) ? $statuses[$ticket['status']] : $ticket['status'];
$byReply = [];
foreach ($attachments as $attachment) {
    $byReply[(int)$attachment['reply_id']][] = $attachment;
}
$adminNames = [];
foreach ($admins as $adminRow) {
    $adminNames[(int)$adminRow['id']] = $adminRow['username'];
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">#<?= (int)$ticket['id'] ?> <?= h($ticket['subject']) ?></h1>
        <div class="small text-muted d-flex flex-wrap gap-2">
            <span class="badge text-bg-primary"><?= h($statusName) ?></span>
            <span>部门：<?= h($ticket['department']) ?></span>
            <span>优先级：<?= h($ticket['priority']) ?></span>
            <span>创建：<?= h(fmt_time($ticket['create_time'])) ?></span>
            <?php if ((int)$ticket['remind_count'] > 0): ?>
                <span class="text-danger">已催单 <?= (int)$ticket['remind_count'] ?> 次</span>
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-light" href="<?= h(url_to('admin/ticket/index')) ?>">返回列表</a>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-body">
                <?php foreach ($replies as $reply): ?>
                    <?php if (!empty($reply['is_system'])): ?>
                        <div class="text-center small text-muted py-2 border-bottom"><?= h($reply['content']) ?> · <?= h(fmt_time($reply['create_time'])) ?></div>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php
                    if ($reply['is_internal']) {
                        $who = '内部备注' . (isset($adminNames[(int)$reply['admin_id']]) ? '（' . $adminNames[(int)$reply['admin_id']] . '）' : '');
                    } elseif ($reply['party'] === 'admin') {
                        $who = '客服' . (isset($adminNames[(int)$reply['admin_id']]) ? ' ' . $adminNames[(int)$reply['admin_id']] : '');
                    } elseif ($reply['party'] === 'seller') {
                        $who = '卖家' . ($seller ? ' ' . $seller['username'] : '');
                    } else {
                        $who = '用户' . ($owner ? ' ' . $owner['username'] : '');
                    }
                    ?>
                    <article class="border-bottom py-3 <?= $reply['is_internal'] ? 'bg-warning-subtle px-2 rounded' : '' ?>">
                        <div class="small mb-2"><b><?= h($who) ?></b> <span class="text-muted">· <?= h(fmt_time($reply['create_time'])) ?></span></div>
                        <div class="ticket-content"><?= $reply['html'] ?></div>
                        <?php if (!empty($byReply[(int)$reply['id']])): ?>
                            <div class="mt-2">
                                <?php foreach ($byReply[(int)$reply['id']] as $attachment): ?>
                                    <a class="badge text-bg-light border me-1" href="<?= h(url_to('admin/ticket/download', ['id' => $attachment['id']])) ?>">附件：<?= h($attachment['original_name']) ?>（<?= h(round($attachment['size'] / 1024, 1)) ?> KB）</a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reply">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <label class="form-label mb-0" for="t_reply">公开回复</label>
                        <?php if ($canned): ?>
                            <select class="form-select form-select-sm w-auto" id="t_canned">
                                <option value="">插入快捷回复…</option>
                                <?php foreach ($canned as $item): ?>
                                    <option value="<?= h($item['content']) ?>"><?= h($item['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <textarea class="form-control mb-2" id="t_reply" name="content" rows="5" required placeholder="支持 Markdown，用户和卖家可见"></textarea>
                    <div class="row g-2 mb-2">
                        <div class="col-md-5">
                            <select class="form-select" name="status">
                                <option value="waiting_user">回复后：等待用户</option>
                                <option value="processing">回复后：处理中</option>
                                <option value="resolved">回复后：已解决</option>
                            </select>
                        </div>
                        <div class="col-md-7"><input class="form-control" type="file" name="attachments[]" multiple></div>
                    </div>
                    <button class="btn btn-primary">发送回复</button>
                </form>
                <hr>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="note">
                    <label class="form-label" for="t_note">内部备注（用户和卖家不可见）</label>
                    <textarea class="form-control mb-2" id="t_note" name="content" rows="3" required></textarea>
                    <button class="btn btn-warning">保存备注</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">提交人</div>
            <div class="card-body small">
                <?php if ($owner): ?>
                    <div><b><?= h($owner['username']) ?></b>（#<?= (int)$owner['id'] ?>）</div>
                    <div class="text-muted"><?= h($owner['email']) ?></div>
                    <div class="text-muted">注册于 <?= h(fmt_time($owner['create_time'])) ?></div>
                    <a href="<?= h(url_to('admin/user/edit', ['id' => $owner['id']])) ?>">查看用户</a>
                <?php else: ?>
                    <span class="text-muted">用户 #<?= (int)$ticket['user_id'] ?>（已删除）</span>
                <?php endif; ?>
                <?php if ($ticket['related'] !== ''): ?>
                    <div class="mt-2">关联对象：<span class="mono"><?= h($ticket['related']) ?></span></div>
                <?php endif; ?>
                <?php if ($seller): ?>
                    <div class="mt-2">市场卖家：<b><?= h($seller['username']) ?></b>（#<?= (int)$seller['id'] ?>），卖家可查看并回复此工单</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="post" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <label class="form-label">状态</label>
                    <div class="input-group">
                        <select class="form-select" name="status">
                            <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= $ticket['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-primary">更新</button>
                    </div>
                </form>
                <form method="post" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign">
                    <label class="form-label">分配客服</label>
                    <div class="input-group">
                        <select class="form-select" name="admin_id">
                            <option value="0">未分配</option>
                            <?php foreach ($admins as $adminRow): ?>
                                <option value="<?= (int)$adminRow['id'] ?>" <?= (int)$ticket['last_admin_id'] === (int)$adminRow['id'] ? 'selected' : '' ?>><?= h($adminRow['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-secondary">分配</button>
                    </div>
                </form>
                <form method="post" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="priority">
                    <label class="form-label">优先级</label>
                    <div class="input-group">
                        <select class="form-select" name="priority">
                            <?php foreach ($priorities as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= $ticket['priority'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-secondary">更新</button>
                    </div>
                </form>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="department">
                    <label class="form-label">转移部门</label>
                    <div class="input-group">
                        <select class="form-select" name="department">
                            <?php foreach ($departments as $departmentRow): ?>
                                <option value="<?= h($departmentRow['name']) ?>" <?= $ticket['department'] === $departmentRow['name'] ? 'selected' : '' ?>><?= h($departmentRow['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-secondary">转移</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">满意度</div>
            <div class="card-body">
                <?php if ($rating): ?>
                    <div class="fs-5 <?= (int)$rating['score'] <= 2 ? 'text-danger' : 'text-success' ?>"><?= (int)$rating['score'] ?> / 5</div>
                    <div class="small text-muted"><?= h($rating['comment']) ?: '无文字评价' ?></div>
                <?php else: ?>
                    <span class="text-muted small">用户尚未评价（工单解决或关闭后可评价）</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var sel = document.getElementById('t_canned');
    var box = document.getElementById('t_reply');
    if (!sel || !box) { return; }
    sel.addEventListener('change', function () {
        if (!sel.value) { return; }
        box.value = box.value ? box.value.replace(/\s+$/, '') + "\n\n" + sel.value : sel.value;
        sel.value = '';
        box.focus();
    });
})();
</script>
