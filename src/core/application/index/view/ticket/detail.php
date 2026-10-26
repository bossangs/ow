<?php
$isSeller = isset($party) && $party === 'seller';
$statusName = isset($statuses[$ticket['status']]) ? $statuses[$ticket['status']] : $ticket['status'];
$statusColor = [
    'open' => 'primary', 'processing' => 'info', 'waiting_user' => 'warning',
    'resolved' => 'success', 'closed' => 'secondary',
];
$color = isset($statusColor[$ticket['status']]) ? $statusColor[$ticket['status']] : 'secondary';
$reopenDays = (int)setting('ticket_reopen_days', '7');
$canReopen = !$isSeller && $ticket['status'] === 'closed'
    && time() - (int)$ticket['update_time'] <= $reopenDays * 86400;
$byReply = [];
foreach ($attachments as $attachment) {
    $byReply[(int)$attachment['reply_id']][] = $attachment;
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1"><?= h($ticket['subject']) ?></h1>
        <div class="text-muted small d-flex flex-wrap gap-2 align-items-center">
            <span class="badge text-bg-<?= h($color) ?>"><?= h($statusName) ?></span>
            <span>#<?= (int)$ticket['id'] ?></span>
            <span>优先级：<?= h($ticket['priority']) ?></span>
            <span>部门：<?= h($ticket['department']) ?></span>
            <?php if ($ticket['related'] !== ''): ?>
                <span>关联：<span class="mono"><?= h($ticket['related']) ?></span></span>
            <?php endif; ?>
            <?php if ((int)$ticket['seller_user_id'] > 0): ?>
                <span class="badge text-bg-light border"><?= $isSeller ? '买家咨询您出售的域名' : '已同时发送给市场卖家' ?></span>
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-light" href="<?= h(url_to('index/ticket/index')) ?>">返回列表</a>
</div>

<div class="card mb-3">
    <div class="card-body ticket-thread">
        <?php foreach ($replies as $reply): ?>
            <?php if (!empty($reply['is_system'])): ?>
                <div class="text-center small text-muted py-2 border-bottom"><?= h($reply['content']) ?> · <?= h(fmt_time($reply['create_time'])) ?></div>
                <?php continue; ?>
            <?php endif; ?>
            <?php
            if ($reply['party'] === 'admin') {
                $who = '客服';
                $whoClass = 'text-primary';
            } elseif ($reply['party'] === 'seller') {
                $who = $isSeller ? '我（卖家）' : '卖家';
                $whoClass = 'text-success';
            } else {
                $who = $isSeller ? '买家' : '我';
                $whoClass = 'text-body';
            }
            ?>
            <article class="border-bottom py-3">
                <div class="small mb-2">
                    <b class="<?= h($whoClass) ?>"><?= h($who) ?></b>
                    <span class="text-muted">· <?= h(fmt_time($reply['create_time'])) ?></span>
                </div>
                <div class="ticket-content"><?= $reply['html'] ?></div>
                <?php if (!empty($byReply[(int)$reply['id']])): ?>
                    <div class="mt-2">
                        <?php foreach ($byReply[(int)$reply['id']] as $attachment): ?>
                            <a class="badge text-bg-light border me-1" href="<?= h(url_to('index/ticket/download', ['id' => $attachment['id']])) ?>">附件：<?= h($attachment['original_name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$isSeller && in_array($ticket['status'], ['resolved', 'closed'], true)): ?>
    <div class="card mb-3">
        <div class="card-header">服务满意度</div>
        <div class="card-body">
            <?php if ($rating): ?>
                <div class="text-success">
                    已评价：<?= h(isset($ratingLabels[(int)$rating['score']]) ? $ratingLabels[(int)$rating['score']] : $rating['score'] . ' 分') ?>
                    <?php if ($rating['comment']): ?>，<?= h($rating['comment']) ?><?php endif; ?>
                </div>
            <?php else: ?>
                <form method="post" action="<?= h(url_to('index/ticket/rate')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($ratingLabels as $score => $label): ?>
                            <label class="form-check-label">
                                <input class="form-check-input me-1" type="radio" name="score" value="<?= (int)$score ?>" required><?= h($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <textarea class="form-control my-2" name="comment" maxlength="1000" rows="2" placeholder="补充评价（可选）"></textarea>
                    <button class="btn btn-outline-primary">提交评价</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($ticket['status'] !== 'closed'): ?>
    <form method="post" enctype="multipart/form-data" class="card mb-2">
        <div class="card-body">
            <?= csrf_field() ?>
            <label class="form-label" for="t_reply">回复</label>
            <textarea class="form-control mb-2" id="t_reply" name="content" rows="5" required placeholder="支持 Markdown"></textarea>
            <input class="form-control mb-2" type="file" name="attachments[]" multiple>
            <div class="form-text mb-2">可上传截图、日志等附件，仅工单相关人员可下载。</div>
            <button class="btn btn-primary">发送回复</button>
        </div>
    </form>
    <?php if (!$isSeller): ?>
        <form method="post" action="<?= h(url_to('index/ticket/close')) ?>" class="mt-2" onsubmit="return confirm('确定关闭此工单？');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
            <button class="btn btn-outline-secondary">问题已解决，关闭工单</button>
        </form>
    <?php endif; ?>
<?php elseif ($canReopen): ?>
    <form method="post" action="<?= h(url_to('index/ticket/reopen')) ?>" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
        <button class="btn btn-outline-primary">问题仍未解决，重开工单</button>
        <span class="small text-muted ms-2">关闭后 <?= $reopenDays ?> 天内可重开</span>
    </form>
<?php else: ?>
    <div class="text-muted small">工单已关闭<?= $isSeller ? '' : '，如有新问题请提交新工单' ?>。</div>
<?php endif; ?>
