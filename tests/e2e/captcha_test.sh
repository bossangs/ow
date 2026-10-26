#!/bin/bash
# 验证码回归测试：
#  1. 所有场景返回 image/svg+xml + 不缓存，浏览器里 <img> 真能显示；
#  2. SVG 里没有明文答案：只允许 svg/rect/path/circle，无文字节点、title/desc/id/注释等；
#     本地测试还会通过“测试专用取码通道”拿到真实答案，断言答案字符串不出现在 SVG 里；
#  3. 本地：正确验证码（含小写）能通过，错误验证码被拒；测试取码通道在非 cli-server 下关闭。
# 用法：SRCDIR=解压后的包目录 bash tests/e2e/captcha_test.sh [php二进制] [端口]
#      或 BASE_URL=https://站点 bash tests/e2e/captcha_test.sh   （只读检查线上站点，不安装、不取码）
set -u
PHP=${1:-php7.0}
PORT=${2:-8476}
ROOT=/workspace/idcdns
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "  PASS $1"; }
bad(){ FAIL=$((FAIL+1)); echo "  FAIL $1"; }

if [ -z "${BASE_URL:-}" ]; then
  # 测试专用取码通道：只有 PHP 内置服务器（cli-server）+ 这个环境变量时，验证码才会写到该目录
  export O2O_TEST_CAPTCHA_DIR=/tmp/captcha_codes_$PORT
  rm -rf "$O2O_TEST_CAPTCHA_DIR"; mkdir -p "$O2O_TEST_CAPTCHA_DIR"
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

# 非 cli-server（php-fpm / CGI / Apache / IIS / CLI）时测试取码通道必须关闭：CLI 下直接调用也不能写出文件
if [ "$LOCAL" = "1" ]; then
  G=/tmp/captcha_guard_$PORT; rm -rf "$G"; mkdir -p "$G"
  CAPF="${SRCDIR:-$ROOT/src}/core/application/common/lib/Captcha.php" O2O_TEST_CAPTCHA_DIR="$G" $PHP -r '
    require getenv("CAPF");
    $m = new ReflectionMethod("app\\common\\lib\\Captcha", "testHook");
    $m->setAccessible(true);
    $m->invoke(null, "login", "ABCD");'
  [ -z "$(ls -A "$G")" ] && ok "非 cli-server 时不写出验证码（测试通道在生产关闭）" || bad "非 cli-server 也写出了验证码"
  rm -rf "$G"
fi

cat > /tmp/cap_plain_$PORT.py <<'PYEOF'
import sys, re, os, requests
U, LOCAL, D = sys.argv[1], sys.argv[2] == "1", sys.argv[3]
scenes = {"login": "index/auth/captcha&scene=login", "register": "index/auth/captcha&scene=register",
          "reset": "index/auth/captcha&scene=reset", "card": "index/auth/captcha&scene=card", "admin": "admin/login/captcha"}
N = 40 if LOCAL else 6
ALLOWED = {"svg", "rect", "path", "circle"}
forbid = re.compile(r"<(text|tspan|title|desc|metadata|style|script|foreignObject|use|symbol|defs|a)\b|<!--|\bid=|\bclass=|font|aria-|href|data-", re.I)
bad = 0; seen = set()
for name, path in scenes.items():
    s = requests.Session(); fail = None
    for i in range(N):
        svg = s.get(U + "/index.php?s=/" + path, timeout=20).text
        tags = set(t.lower() for t in re.findall(r"<\s*([a-zA-Z]+)", svg))
        if not tags <= ALLOWED:
            fail = "出现不允许的元素 %s" % (tags - ALLOWED); break
        m = forbid.search(svg)
        if m:
            fail = "出现可能泄露答案的内容：%s" % m.group(0); break
        if re.sub(r"<[^>]*>", "", svg).strip():
            fail = "SVG 含文字节点"; break
        if LOCAL:
            code = open(os.path.join(D, name + ".txt")).read().strip()
            seen.add(code)
            if not re.fullmatch(r"[2-9A-HJKMNP-Z]{4}", code):
                fail = "取到的验证码格式不对：%r" % code; break
            if code.lower() in svg.lower():
                fail = "SVG 里出现了答案 %s" % code; break
    if fail:
        print("  FAIL %s：%s" % (name, fail)); bad += 1
    else:
        print("  PASS %s：%d 张 SVG 只有路径/图形、无文字%s" % (name, N, "，答案均未出现在 SVG 中" if LOCAL else ""))
if LOCAL and len(seen) < N * len(scenes) * 0.9:
    print("  FAIL 验证码随机性不足（%d 张里只有 %d 个不同）" % (N * len(scenes), len(seen))); bad += 1
sys.exit(1 if bad else 0)
PYEOF
python3 /tmp/cap_plain_$PORT.py "$U" "$LOCAL" "${O2O_TEST_CAPTCHA_DIR:-}"
[ $? -eq 0 ] && ok "SVG 无明文答案" || bad "SVG 无明文答案"
rm -f /tmp/cap_plain_$PORT.py

# 本地：用测试通道拿到的正确验证码能通过（含小写），错误验证码被拒
if [ "$LOCAL" = "1" ]; then
cat > /tmp/cap_login_$PORT.py <<'PYEOF'
import sys, re, os, html, requests
from bs4 import BeautifulSoup
U, D = sys.argv[1], sys.argv[2]
def msg(t):
    m = re.search(r'class="alert[^"]*"[^>]*>\s*([^<]{2,80})', t)
    return m.group(1).strip() if m else "?"
def attempt(login_path, cap_path, scene, user_field, account, mode):
    s = requests.Session()
    r = s.get(U + "/index.php?s=" + login_path, timeout=20)
    form = [f for f in BeautifulSoup(r.text, "html.parser").find_all("form") if f.find("input", attrs={"name": "captcha"})][0]
    data = {i.get("name"): i.get("value", "") for i in form.find_all("input") if i.get("name")}
    s.get(U + "/index.php?s=" + cap_path, timeout=20)
    code = open(os.path.join(D, scene + ".txt")).read().strip()
    data["captcha"] = {"right": code, "lower": code.lower(), "wrong": "ZZZZ" if code != "ZZZZ" else "YYYY"}[mode]
    data[user_field] = account
    data["password"] = "wrong-password-x"
    act = html.unescape(form.get("action") or ("/index.php?s=" + login_path))
    act = act if act.startswith("http") else U + act
    return msg(s.post(act, data=data, timeout=20).text)
bad = 0
cases = [("前台", "/index/auth/login", "/index/auth/captcha&scene=login", "login", "account", "nobody_cap@example.invalid"),
         ("后台", "/admin/login/index", "/admin/login/captcha", "admin", "username", "nobody_cap")]
for name, lp, cp, scene, uf, acc in cases:
    for mode in ("right", "lower", "wrong"):
        m = attempt(lp, cp, scene, uf, acc, mode)
        passed = ("验证码" in m) if mode == "wrong" else ("验证码" not in m and "密码错误" in m)
        label = {"right": "正确", "lower": "正确(小写)", "wrong": "错误"}[mode]
        print("  %s %s登录 验证码=%s → %s" % ("PASS" if passed else "FAIL", name, label, m))
        bad += 0 if passed else 1
sys.exit(1 if bad else 0)
PYEOF
python3 /tmp/cap_login_$PORT.py "$U" "$O2O_TEST_CAPTCHA_DIR"
[ $? -eq 0 ] && ok "正确/错误验证码登录判定" || bad "正确/错误验证码登录判定"
rm -f /tmp/cap_login_$PORT.py
fi

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
  rm -rf "$O2O_TEST_CAPTCHA_DIR"
fi
echo "captcha: $PASS passed, $FAIL failed"
[ "$FAIL" = "0" ]
