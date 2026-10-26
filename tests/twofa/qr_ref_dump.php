<?php
// 输出：版本 掩码 纠错等级 + 矩阵（用于与参考实现逐位比对）
namespace app\common\lib;
require __DIR__ . '/../../src/core/application/common/lib/QrCode.php';
$ecl = (int)$argv[1];
while (($line = fgets(STDIN)) !== false) {
    $t = base64_decode(trim($line));
    $m = QrCode::matrix($t, $ecl);
    echo implode("\n", array_map(function ($r) { return implode('', array_map('intval', $r)); }, $m)), "\n\n";
}
