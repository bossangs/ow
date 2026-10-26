<div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-1">提交工单</h1><p class="text-muted mb-0">请提供清晰的问题描述，支持 Markdown。</p></div><a class="btn btn-light" href="<?= h(url_to('index/ticket/index')) ?>">返回列表</a></div>
<div class="card"><div class="card-body"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="row g-3"><div class="col-md-8"><label class="form-label">主题</label><input class="form-control" name="subject" maxlength="191" required></div><div class="col-md-4"><label class="form-label">优先级</label><select class="form-select" name="priority"><?php foreach ($priorities as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div></div>
    <div class="mt-3"><label class="form-label">部门</label><select class="form-select" name="department" required><?php foreach ($departments as $department): ?><option value="<?= h($department['name']) ?>"><?= h($department['name']) ?></option><?php endforeach; ?></select></div>
    <div class="row g-3 mt-0">
        <div class="col-md-6">
            <label class="form-label" for="t_related">关联对象 <span class="text-muted fw-normal">（可选）</span></label>
            <select class="form-select" id="t_related" name="related">
                <option value="">不关联</option>
                <?php if ($orders): ?><optgroup label="订单"><?php foreach ($orders as $o): $v = 'order:' . $o['id']; ?><option value="<?= h($v) ?>" <?= $preRelated === $v ? 'selected' : '' ?>><?= h($o['order_no'] . ' · ' . $o['title']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ($services): ?><optgroup label="云主机"><?php foreach ($services as $sv): $v = 'service:' . $sv['id']; ?><option value="<?= h($v) ?>" <?= $preRelated === $v ? 'selected' : '' ?>><?= h('#' . $sv['id'] . ' ' . $sv['hostname']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ($subs): ?><optgroup label="子域名"><?php foreach ($subs as $sb): $v = 'subdomain:' . $sb['id']; ?><option value="<?= h($v) ?>" <?= $preRelated === $v ? 'selected' : '' ?>><?= h($sb['fqdn']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="t_seller">联系市场卖家 <span class="text-muted fw-normal">（可选）</span></label>
            <select class="form-select" id="t_seller" name="seller_subdomain_id" <?= $sellerSubs ? '' : 'disabled' ?>>
                <option value="0"><?= $sellerSubs ? '不需要，由平台客服处理' : '您没有从市场购买的子域名' ?></option>
                <?php foreach ($sellerSubs as $sb): ?><option value="<?= h($sb['id']) ?>" <?= $preSeller === (int)$sb['id'] ? 'selected' : '' ?>><?= h($sb['fqdn']) ?> 的卖家</option><?php endforeach; ?>
            </select>
            <div class="form-text">选择后工单会同时发给该域名的卖家，卖家超时未回复会自动升级给平台客服。</div>
        </div>
    </div>
    <div class="mt-3"><label class="form-label">问题描述</label><textarea class="form-control" name="content" rows="9" required placeholder="支持 **加粗**、*斜体*、列表和 http/https 链接"></textarea></div>
    <div class="mt-3"><label class="form-label">附件</label><input class="form-control" type="file" name="attachments[]" multiple><div class="form-text">支持图片、PDF、文本、ZIP、Office 文件，大小和数量受系统设置限制。</div></div>
    <button class="btn btn-primary mt-3">提交工单</button>
</form></div></div>
