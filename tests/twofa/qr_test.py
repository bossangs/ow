#!/usr/bin/env python3
"""QR 编码器验证：随机/边界长度文本 × 4 个纠错等级，用 OpenCV 与 zxing-cpp 两个独立解码器解码比对。"""
import base64, os, random, subprocess, sys
import numpy as np, cv2, zxingcpp
from PIL import Image
HERE = os.path.dirname(os.path.abspath(__file__))
PHP = os.environ.get("PHP", "php7.0")
random.seed(7)
texts = []
for n in list(range(1, 60)) + [77, 78, 100, 106, 120, 134, 154, 180, 213, 230, 271, 321, 367, 425, 458, 520, 586, 644, 718]:
    texts.append(bytes(random.choice(b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789:/?&=%-._") for _ in range(n)))
for s in ["otpauth://totp/o2o%E4%BA%91%20%E5%90%8E%E5%8F%B0:admin?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=o2o%E4%BA%91%20%E5%90%8E%E5%8F%B0&algorithm=SHA1&digits=6&period=30",
          "中文 o2o云 二维码测试", "https://example.com/" + "x" * 300]:
    texts.append(s.encode())
det = cv2.QRCodeDetector()
fails = 0; total = 0; vers = set(); cv_miss = 0
for ecl in range(4):
    if ecl > 0:
        sample = texts[::3]
    else:
        sample = texts
    inp = "\n".join(base64.b64encode(t).decode() for t in sample) + "\n"
    out = subprocess.run([PHP, os.path.join(HERE, "qr_dump.php"), str(ecl)], input=inp, capture_output=True, text=True, check=True).stdout
    mats = [m for m in out.split("\n\n") if m.strip()]
    assert len(mats) == len(sample), (len(mats), len(sample))
    for t, m in zip(sample, mats):
        rows = m.strip().split("\n"); n = len(rows); vers.add((n - 17) // 4)
        a = np.array([[0 if c == "1" else 255 for c in r] for r in rows], dtype=np.uint8)
        a = np.pad(a, 4, constant_values=255)
        img = cv2.resize(a, None, fx=6, fy=6, interpolation=cv2.INTER_NEAREST)
        total += 1
        z = zxingcpp.read_barcodes(Image.fromarray(img))
        zt = z[0].bytes if z else None
        cv_ok = True
        if n <= 101:  # OpenCV 对大版本不稳定，只用于中小版本交叉验证
            d, _, _ = det.detectAndDecode(img)
            cv_ok = d.encode("utf-8", "surrogateescape") == t or d == t.decode("utf-8", "ignore")
        if not cv_ok:
            cv_miss += 1
        if zt != t:
            fails += 1
            print("FAIL ecl=%d len=%d ver=%d zxing_ok=%s cv_ok=%s" % (ecl, len(t), (n - 17) // 4, zt == t, cv_ok))
print("QR: %d/%d decoded by zxing-cpp, versions %s (OpenCV 未识别 %d 个，仅供参考)" % (total - fails, total, sorted(vers), cv_miss))
sys.exit(1 if fails else 0)
