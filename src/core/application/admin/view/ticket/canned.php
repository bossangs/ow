<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">快捷回复</h1>
    <a class="btn btn-light" href="<?= h(url_to('admin/ticket/index')) ?>">返回工单</a>
</div>

<form method="post" action="<?= h(url_to('admin/ticket/canned')) ?>" class="card card-body mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
    <label class="form-label" for="c_title"><?= $edit ? '编辑快捷回复 #' . (int)$edit['id'] : '新增快捷回复' ?></label>
    <input class="form-control mb-2" id="c_title" name="title" placeholder="标题，例如：解析生效时间说明" required value="<?= h($edit ? $edit['title'] : '') ?>">
    <textarea class="form-control mb-2" name="content" rows="4" placeholder="回复内容，支持 Markdown" required><?= h($edit ? $edit['content'] : '') ?></textarea>
    <div class="d-flex gap-2">
        <button class="btn btn-primary">保存</button>
        <?php if ($edit): ?><a class="btn btn-light" href="<?= h(url_to('admin/ticket/canned')) ?>">取消编辑</a><?php endif; ?>
    </div>
    <div class="form-text">在工单详情的“公开回复”右上角可一键插入。</div>
</form>

<?php foreach ($items as $item): ?>
    <div class="card card-body mb-2">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <b><?= h($item['title']) ?></b>
            <div class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-secondary" href="<?= h(url_to('admin/ticket/canned', ['id' => $item['id']])) ?>">编辑</a>
                <form method="post" action="<?= h(url_to('admin/ticket/canned')) ?>" onsubmit="return confirm('删除这条快捷回复？');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">删除</button>
                </form>
            </div>
        </div>
        <div class="mt-2 small"><?= nl2br(h($item['content'])) ?></div>
    </div>
<?php endforeach; ?>
<?php if (!$items): ?>
    <div class="empty py-5 text-center text-muted">还没有快捷回复</div>
<?php endif; ?>
