#!/usr/bin/env bash
# 两步验证底层库测试：RFC 6238 向量、二维码双解码器验证、与参考实现逐位比对
set -Eeuo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export PHP="${PHP:-php7.0}"
echo "-- TOTP RFC 6238 向量"; $PHP "$HERE/totp_vectors.php"
echo "-- 二维码解码验证"; python3 "$HERE/qr_test.py"
echo "-- 与 qrcodegen 参考实现比对"; python3 "$HERE/qr_ref_test.py"
