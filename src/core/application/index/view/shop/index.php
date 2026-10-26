<?php
// 分组展示；未分组或分组已停用的商品归入「其他」，避免商品上架了却看不到
$groupIds = [];
foreach ($groups as $group) {
    $groupIds[(int)$group['id']] = true;
}
$sections = [];
foreach ($groups as $group) {
    $sections[] = ['name' => $group['name'], 'id' => (int)$group['id']];
}
$hasOther = false;
foreach ($products as $product) {
    if (!isset($groupIds[(int)$product['group_id']])) {
        $hasOther = true;
        break;
    }
}
if ($hasOther) {
    $sections[] = ['name' => $groups ? '其他' : '', 'id' => -1];
}
$specKeys = ['spec_cpu', 'spec_memory', 'spec_disk', 'spec_bandwidth', 'spec_region'];
?>
<section class="section">
    <div class="container">
        <div class="mb-4">
            <h1 class="section-title">云主机</h1>
            <p class="section-sub mb-0">按需选择稳定可靠的计算资源</p>
        </div>
        <?php foreach ($sections as $section): ?>
            <?php if ($section['name'] !== ''): ?><h2 class="h5 mt-4 mb-3"><?= h($section['name']) ?></h2><?php endif; ?>
            <div class="row g-3">
                <?php foreach ($products as $product): ?>
                    <?php
                    $inSection = $section['id'] === -1 ? !isset($groupIds[(int)$product['group_id']]) : (int)$product['group_id'] === $section['id'];
                    if (!$inSection) {
                        continue;
                    }
                    $min = $product['_prices'] ? min($product['_prices']) : 0;
                    $left = isset($product['_available']) ? (int)$product['_available'] : -1;
                    $soldOut = $left === 0 || !$product['_prices'];
                    $chips = [];
                    foreach ($specKeys as $key) {
                        if (isset($product[$key]) && trim((string)$product[$key]) !== '') {
                            $chips[] = $product[$key];
                        }
                    }
                    ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card card-hover h-100 p-4<?= $soldOut ? ' opacity-75' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h3 class="h5 mb-2"><?= h($product['name']) ?></h3>
                                <?php if ($soldOut): ?>
                                    <span class="badge text-bg-secondary">已售罄</span>
                                <?php elseif ($left > 0 && $left <= 5): ?>
                                    <span class="badge text-bg-warning">仅剩 <?= $left ?> 台</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($chips): ?>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php foreach ($chips as $chip): ?><span class="badge rounded-pill text-bg-light border"><?= h($chip) ?></span><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p class="text-muted small mb-2"><?= h(mb_substr((string)$product['description'], 0, 60)) ?></p>
                            <div class="price mt-auto"><?= money($min) ?><small> 起</small></div>
                            <a class="btn <?= $soldOut ? 'btn-outline-secondary' : 'btn-primary' ?> mt-3" href="<?= h(url_to('index/shop/product', ['id' => $product['id']])) ?>"><?= $soldOut ? '查看详情（已售罄）' : '查看详情并购买' ?></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$products): ?><div class="empty">暂无可售商品</div><?php endif; ?>
    </div>
</section>
