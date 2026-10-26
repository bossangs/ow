<?php
namespace app\common\lib;
require __DIR__ . '/../../src/core/application/common/lib/Totp.php';
// RFC 6238 附录 B（SHA1，密钥 "12345678901234567890"，8 位）
$secret = Totp::base32Encode('12345678901234567890');
$v = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];
$ok = 0; $n = 0;
foreach ($v as $t => $exp) { $n++; $got = Totp::code($secret, intdiv($t, 30), 8); if ($got === $exp) { $ok++; } else { echo "FAIL t=$t exp=$exp got=$got\n"; } }
// 6 位 = 8 位的后 6 位
$n++; if (Totp::code($secret, 1, 6) === '287082') { $ok++; } else { echo "FAIL 6 digits\n"; }
// Base32 往返
for ($i = 0; $i < 200; $i++) { $n++; $b = $i % 41 ? random_bytes($i % 41) : ""; if (Totp::base32Decode(Totp::base32Encode($b)) === $b) { $ok++; } else { echo "FAIL base32 len ", strlen($b), "\n"; } }
// ±1 步容差与窗口外拒绝
$s = Totp::newSecret(); $t0 = 1700000000; $st = Totp::step($t0);
foreach ([-1 => true, 0 => true, 1 => true, -2 => false, 2 => false] as $d => $exp) { $n++; $m = Totp::matchStep($s, Totp::code($s, $st + $d), $t0); if (($m !== false) === $exp && ($m === false || $m === $st + $d)) { $ok++; } else { echo "FAIL window $d\n"; } }
$n++; if (strlen($s) === 32 && Totp::matchStep($s, 'abcdef', $t0) === false && Totp::matchStep($s, '12345', $t0) === false) { $ok++; } else { echo "FAIL format\n"; }
$n++; $u = Totp::uri('o2o云 后台', 'admin', $s); if (strpos($u, 'otpauth://totp/o2o%E4%BA%91%20%E5%90%8E%E5%8F%B0:admin?secret=' . $s . '&issuer=') === 0) { $ok++; } else { echo "FAIL uri $u\n"; }
echo "TOTP: $ok/$n passed\n"; exit($ok === $n ? 0 : 1);
