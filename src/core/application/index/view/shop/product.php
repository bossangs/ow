<?php
/**
 * 云主机商品详情 + 下单表单（shop/product 与 host/buy 共用）
 * 变量：$product, $prices(周期 => 价格), $os(系统列表), $available(-1=不限, 0=售罄)
 */
$specLabels = [
    'spec_cpu' => 'CPU', 'spec_memory' => '内存', 'spec_disk' => '硬盘', 'spec_bandwidth' => '带宽',
    'spec_traffic' => '流量', 'spec_ip' => 'IP', 'spec_region' => '地域', 'spec_line' => '线路',
];
$specs = [];
foreach ($specLabels as $key => $label) {
    if (isset($product[$key]) && trim((string)$product[$key]) !== '') {
        $specs[] = [$label, $product[$key]];
    }
}
if (!empty($product['spec_extra'])) {
    $extra = json_decode((string)$product['spec_extra'], true);
    $extra = is_array($extra) ? $extra : preg_split('/\r?\n/', (string)$product['spec_extra']);
    foreach ($extra as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/[:：]/u', $line, 2);
        $specs[] = count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', $line];
    }
}
$available = isset($available) ? (int)$available : -1;
$soldOut = $available === 0 || !$prices;
$loggedIn = !empty($user);
$minPrice = $prices ? min($prices) : 0;
$firstCycle = $prices ? array_keys($prices)[0] : '';
$monthly = isset($prices['monthly']) ? (float)$prices['monthly'] : 0;
?>
<section class="section">
    <div class="container">
        <nav class="small mb-3"><a href="<?= h(url_to('index/shop/index')) ?>">云主机</a> / <?= h($product['name']) ?></nav>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card p-4 mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <h1 class="h3 mb-1"><?= h($product['name']) ?></h1>
                        <?php if ($soldOut): ?>
                            <span class="badge text-bg-secondary fs-6">已售罄</span>
                        <?php elseif ($available > 0 && $available <= 5): ?>
                            <span class="badge text-bg-warning fs-6">仅剩 <?= $available ?> 台</span>
                        <?php else: ?>
                            <span class="badge text-bg-success fs-6">有货</span>
                        <?php endif; ?>
                    </div>
                    <div class="price my-2"><?= money($minPrice) ?><small> 起</small></div>
                    <?php if ($specs): ?>
                        <div class="row g-2 my-2">
                            <?php foreach ($specs as $spec): ?>
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-2 h-100 bg-light">
                                        <div class="small text-muted"><?= h($spec[0] ?: '规格') ?></div>
                                        <div class="fw-semibold"><?= h($spec[1]) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (trim((string)$product['description']) !== ''): ?>
                        <h2 class="h6 mt-3">产品介绍</h2>
                        <div class="text-muted"><?= nl2br(h($product['description'])) ?></div>
                    <?php endif; ?>
                </div>

                <div class="card p-4">
                    <h2 class="h6">价格</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>付款周期</th><th>价格</th><th>折合每月</th></tr></thead>
                            <tbody>
                            <?php foreach ($prices as $cycle => $price): ?>
                                <?php
                                $cycles = billing_cycles();
                                $months = isset($cycles[$cycle]['months']) ? (float)$cycles[$cycle]['months'] : 0;
                                $perMonth = $months > 0 ? $price / $months : 0;
                                $save = ($monthly > 0 && $months > 1) ? round((1 - $perMonth / $monthly) * 100) : 0;
                                ?>
                                <tr>
                                    <td><?= h(cycle_name($cycle)) ?></td>
                                    <td class="fw-semibold"><?= money($price) ?></td>
                                    <td class="small text-muted">
                                        <?= $months > 0 ? money($perMonth) . ' / 月' : '—' ?>
                                        <?php if ($save > 0): ?><span class="badge text-bg-danger ms-1">省 <?= (int)$save ?>%</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$prices): ?><tr><td colspan="3" class="text-muted">暂未开放购买</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card p-4 position-sticky" style="top: 1rem">
                    <h2 class="h5">配置并下单</h2>
                    <?php if ($soldOut): ?>
                        <div class="alert alert-secondary mb-3">该商品已售罄，补货后可再次购买。可联系客服或选择其他配置。</div>
                        <button class="btn btn-secondary w-100" disabled>已售罄</button>
                    <?php elseif (!$loggedIn): ?>
                        <p class="text-muted">登录后即可选择周期、填写主机名和 root 密码下单。</p>
                        <a class="btn btn-primary w-100" href="<?= h(url_to('index/auth/login', ['back' => url_to('index/shop/product', ['id' => $product['id']])])) ?>">登录后下单</a>
                        <a class="btn btn-link w-100" href="<?= h(url_to('index/auth/register')) ?>">没有账号？立即注册</a>
                    <?php else: ?>
                        <form method="post" action="<?= h(url_to('index/host/buy', ['product_id' => $product['id']])) ?>"
                              data-coupon-form data-coupon-type="host_new" data-coupon-url="<?= h(url_to('index/order/coupon_preview')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

                            <label class="form-label" for="b_cycle">付款周期</label>
                            <select id="b_cycle" name="cycle" class="form-select mb-3">
                                <?php foreach ($prices as $cycle => $price): ?>
                                    <option value="<?= h($cycle) ?>" <?= $cycle === $firstCycle ? 'selected' : '' ?>><?= h(cycle_name($cycle)) ?> · <?= money($price) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <?php if (!isset($product['allow_hostname']) || (int)$product['allow_hostname']): ?>
                                <label class="form-label" for="b_host">主机名</label>
                                <input id="b_host" name="hostname" class="form-control mb-3" maxlength="63" pattern="[A-Za-z0-9][A-Za-z0-9\-]{0,62}" placeholder="如 web-01（字母、数字、短横线）" required>
                            <?php endif; ?>

                            <label class="form-label" for="b_os">操作系统</label>
                            <?php if ($os): ?>
                                <select id="b_os" name="os" class="form-select mb-3">
                                    <?php foreach ($os as $item): ?>
                                        <?php $name = is_array($item) ? $item['name'] : $item; ?>
                                        <option value="<?= h($name) ?>"><?= h($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input id="b_os" name="os" class="form-control mb-3" placeholder="默认系统（可选）">
                            <?php endif; ?>

                            <label class="form-label" for="b_pw">root 密码</label>
                            <div data-pw-tools class="mb-3">
                                <div class="input-group">
                                    <input id="b_pw" name="password" type="password" minlength="8" maxlength="32" class="form-control" autocomplete="new-password" required>
                                    <button type="button" class="btn btn-outline-secondary" data-pw-toggle>显示</button>
                                    <button type="button" class="btn btn-outline-secondary" data-pw-generate>生成</button>
                                </div>
                                <div class="progress mt-2" style="height: 6px"><div class="progress-bar" data-pw-bar style="width: 0"></div></div>
                                <div class="form-help mt-1" data-pw-hint>8-32 位，需包含大写字母、小写字母、数字中的至少三类；不要包含空格和引号。</div>
                            </div>

                            <?php include __DIR__ . '/../_coupon.php'; ?>
                            <button class="btn btn-primary w-100">创建订单并去支付</button>
                            <div class="small text-muted mt-2">下单后库存会为你保留一段时间，超时未支付自动释放。</div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
