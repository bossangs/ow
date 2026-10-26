#!/bin/bash
# 验证码回归测试：所有场景必须返回 image/svg+xml + 不缓存，并且浏览器里 <img> 真能显示。
# （之前前台验证码返回 text/html，浏览器不显示，旧测试只检查了内容，没发现）
# 用法：SRCDIR=解压后的包目录 bash tests/e2e/captcha_test.sh [php二进制] [端口]
#      或 BASE_URL=https://站点 bash tests/e2e/captcha_test.sh   （只读检查线上站点，不安装）
set -u
PHP=${1:-php7.0}
PORT=${2:-8476}
ROOT=/workspace/idcdns
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "  PASS $1"; }
bad(){ FAIL=$((FAIL+1)); echo "  FAIL $1"; }

if [ -z "${BASE_URL:-}" ]; then
  bash "$ROOT/tests/e2e/setup_site.sh" "$PHP" "$PORT" | tail -1 || { echo "安装失败"; exit 1; }
  U=http://127.0.0.1:$PORT
  LOCAL=1
else
  U=${BASE_URL%/}; LOCAL=0
fi

for path in "index/auth/captcha&scene=login" "index/auth/captcha&scene=register" "index/auth/captcha&scene=reset" \
            "index/auth/captcha&scene=card" "index/auth/captcha&scene=evil<x>" "admin/login/captcha"; do
  H=$(curl -s -D - -o /tmp/cap_body_$PORT "$U/index.php?s=/$path")
  ct=$(echo "$H" | grep -i '^content-type:' | tr -d '\r' | sed 's/^[^:]*:[[:space:]]*//' | tail -1)
  cc=$(echo "$H" | grep -i '^cache-control:' | tr -d '\r')
  case "$ct" in image/svg+xml*) ok "$path Content-Type=$ct";; *) bad "$path Content-Type=$ct";; esac
  echo "$cc" | grep -qi "no-store" && ok "$path 不缓存" || bad "$path 缺少 no-store（$cc）"
  head -c 5 /tmp/cap_body_$PORT | grep -q "<svg" && ok "$path 内容是 SVG" || bad "$path 内容不是 SVG"
done
rm -f /tmp/cap_body_$PORT

# 真实浏览器：页面上的验证码图片必须加载成功（naturalWidth > 0）
python3 - "$U" <<'EOF'
import sys, asyncio
from playwright.async_api import async_playwright
U = sys.argv[1]
pages = {"注册": "/index.php?s=/index/auth/register", "登录": "/index.php?s=/index/auth/login",
         "找回密码": "/index.php?s=/index/auth/forgot", "后台登录": "/index.php?s=/admin/login/index"}
async def main():
    bad = 0
    async with async_playwright() as p:
        b = await p.chromium.launch()
        pg = await b.new_page()
        for name, path in pages.items():
            await pg.goto(U + path, wait_until="networkidle")
            r = await pg.evaluate("""() => [...document.images].filter(i => /captcha/.test(i.src))
                                      .map(i => ({w: i.naturalWidth, ok: i.complete}))""")
            if r and all(x["w"] > 0 for x in r):
                print(f"  PASS 浏览器显示验证码：{name}（{r[0]['w']}px）")
            else:
                print(f"  FAIL 浏览器未显示验证码：{name} {r}"); bad += 1
        await b.close()
    sys.exit(1 if bad else 0)
asyncio.run(main())
EOF
[ $? -eq 0 ] && ok "浏览器渲染 4 个页面" || bad "浏览器渲染"

if [ "$LOCAL" = "1" ]; then
  pkill -f "127.0.0.1:$PORT" 2>/dev/null || true
  sudo mysql -e "DROP DATABASE IF EXISTS idcdns_e2e_$PORT;"
fi
echo "captcha: $PASS passed, $FAIL failed"
[ "$FAIL" = "0" ]
