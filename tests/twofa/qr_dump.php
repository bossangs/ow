<?php
// 用法：php qr_dump.php <ecl 0-3> < 文本列表（每行一个 base64 文本）；输出每个矩阵的 0/1 行，空行分隔
namespace app\common\lib;
require __DIR__ . '/../../src/core/application/common/lib/QrCode.php';
$ecl = (int)$argv[1];
while (($line = fgets(STDIN)) !== false) {
    $t = base64_decode(trim($line));
    foreach (QrCode::matrix($t, $ecl) as $row) { echo implode('', array_map('intval', $row)), "\n"; }
    echo "\n";
}
