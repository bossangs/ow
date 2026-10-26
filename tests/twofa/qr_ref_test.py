#!/usr/bin/env python3
"""与 Nayuki qrcodegen（参考实现）逐位比对：同版本/纠错等级下必须与某个掩码的输出完全一致。"""
import base64, random, subprocess, sys, os
from qrcodegen import QrCode, QrSegment
HERE = os.path.dirname(os.path.abspath(__file__))
PHP = os.environ.get("PHP", "php7.0")
random.seed(11)
texts = [bytes(random.randrange(256) for _ in range(n)) for n in list(range(0, 40)) + [41, 60, 77, 78, 100, 106, 120, 134, 154, 169, 180, 213, 230, 271, 321, 367, 425, 458, 520, 586, 644, 718, 900, 1200]]
texts.append(b"otpauth://totp/o2o%E4%BA%91%20%E5%90%8E%E5%8F%B0:admin?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=o2o%E4%BA%91%20%E5%90%8E%E5%8F%B0&algorithm=SHA1&digits=6&period=30")
E = [QrCode.Ecc.LOW, QrCode.Ecc.MEDIUM, QrCode.Ecc.QUARTILE, QrCode.Ecc.HIGH]
ok = tot = 0; vers = set()
for ecl in range(4):
    out = subprocess.run([PHP, os.path.join(HERE, "qr_ref_dump.php"), str(ecl)], input="\n".join(base64.b64encode(t).decode() for t in texts) + "\n", capture_output=True, text=True, check=True).stdout
    mats = out.split("\n\n")[:len(texts)]
    for t, m in zip(texts, mats):
        ours = [[int(c) for c in r] for r in m.strip().split("\n")]
        n = len(ours); ver = (n - 17) // 4; vers.add(ver); tot += 1
        hit = False
        for mask in range(8):
            q = QrCode.encode_segments([QrSegment.make_bytes(t)], E[ecl], ver, ver, mask, True)
            if q.get_size() == n and all(q.get_module(x, y) == bool(ours[y][x]) for y in range(n) for x in range(n)):
                hit = True; break
        # 版本也必须是最小可用版本
        minq = QrCode.encode_segments([QrSegment.make_bytes(t)], E[ecl], 1, 40, -1, True)
        if hit and minq.get_version() == ver:
            ok += 1
        else:
            print("FAIL ecl=%d len=%d ver=%d ref_ver=%d hit=%s" % (ecl, len(t), ver, minq.get_version(), hit))
print("QR reference bit-exact: %d/%d, versions %d..%d" % (ok, tot, min(vers), max(vers)))
sys.exit(0 if ok == tot else 1)
