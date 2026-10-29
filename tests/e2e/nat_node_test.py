#!/usr/bin/env python3
"""NAT 节点后台 端到端测试（全部走真实页面）。

覆盖：添加节点表单全部预填（名称自增、端口段/内网段避开已有节点、常用镜像、IPv6 自动）、
自动字段不可手填、保存后直达安装命令页、快速添加、上游接口 → NAT 类型自动建节点、
脚本地址可匿名下载且 SHA-256 与页面一致、在 Debian 12 容器里运行页面上的安装命令
（IDCNAT_DRY_RUN=1 + 真实注册）、注册后节点在线并自动写入上游、令牌一次性、
重新生成令牌（旧命令失效、开启 2FA 后需要二次验证）、离线判定、列表容量显示。

用法：IDC_BASE=http://127.0.0.1:8480 IDC_DB=idcdns_e2e_8480 python3 tests/e2e/nat_node_test.py
环境变量 NAT_DEBIAN_IMAGE（默认 o2o-nat-debian12:test）不存在时退回在本机（Ubuntu）以非 root 跑脚本。
"""
import hashlib
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flow_test2 as F  # noqa: E402
import twofa_test as T  # noqa: E402
import requests  # noqa: E402
from bs4 import BeautifulSoup  # noqa: E402

check, one, t = F.check, F.one, F.t
IMAGE = os.environ.get("NAT_DEBIAN_IMAGE", "o2o-nat-debian12:test")


def sql(q):
    subprocess.run(["sudo", "mysql", F.DB, "-e", q], check=True)


def soup(html):
    return BeautifulSoup(html, "html.parser")


def cmd_info(html):
    s = soup(html)
    cmds = [p.get_text() for p in s.select("pre.nat-cmd-line")]
    url = s.select_one("code.nat-script-url")
    sha = s.select_one("code.nat-script-sha")
    return cmds, (url.get_text().strip() if url else ""), (sha.get_text().strip() if sha else "")


def node(nid):
    out = subprocess.run(["sudo", "mysql", "-N", "-B", F.DB, "-e",
                          "select name,upstream_id,reg_token,reg_expire,last_heartbeat,public_ip,options from %s where id=%d" % (t("nat_node"), nid)],
                         capture_output=True, text=True).stdout.rstrip("\n")
    if not out:
        return None
    k = ["name", "upstream_id", "reg_token", "reg_expire", "last_heartbeat", "public_ip", "options"]
    return dict(zip(k, out.split("\t")))


def have_image():
    r = subprocess.run(["sudo", "docker", "image", "inspect", IMAGE], capture_output=True)
    return r.returncode == 0


def run_install(cmd, register=True):
    """在 Debian 12 容器（或本机）里执行页面上复制出来的命令"""
    env = ["IDCNAT_DRY_RUN=1", "IDCNAT_DRY_RUN_REGISTER=%d" % (1 if register else 0)]
    if have_image():
        args = ["sudo", "docker", "run", "--rm", "--network", "host"]
        for e in env:
            args += ["-e", e]
        args += [IMAGE, "bash", "-c", "cd /root && " + cmd]
        where = "Debian 12 容器"
    else:
        args = ["env"] + env + ["IDCNAT_TEST_ALLOW_NONROOT=1", "TMPDIR=/tmp/natdry_%d" % os.getpid(), "bash", "-c", "mkdir -p $TMPDIR && cd $TMPDIR && " + cmd]
        where = "本机"
    p = subprocess.run(args, capture_output=True, text=True, timeout=300)
    return p.returncode, p.stdout + p.stderr, where


def main():
    sql("delete from %s" % t("auth_guard"))
    sql("insert into %s(name,value) values('captcha_enable','0') on duplicate key update value='0'" % t("setting"))
    sql("delete from %s" % t("nat_node"))
    sql("delete from %s where driver='natnode'" % t("upstream"))
    T.cli("reset-2fa", "admin")
    sql("update %s set login_fail=0, lock_until=0 where username='admin'" % t("admin"))
    T.clear_cache()

    admin, r, al = T.login_admin("nat-admin", F.ADMIN_PW)
    check("[NAT] 后台登录", F.clean(r) and "login" not in r.url, (r.url, al))

    # ---------- 1. 添加节点表单：全部预填 ----------
    r = admin.get("admin/nat_node/edit")
    s = soup(r.text)
    val = lambda n: (s.select_one('[name="%s"]' % n) or {}).get("value", "")  # noqa: E731
    selv = lambda n: (s.select_one('select[name="%s"] option[selected]' % n) or {}).get("value", "")  # noqa: E731
    check("[NAT] 表单名称默认「节点-1」", val("name") == "节点-1", val("name"))
    check("[NAT] 面板地址默认站点网址", val("panel_url").rstrip("/") == F.BASE, val("panel_url"))
    check("[NAT] 端口段默认 20000-30000、Agent 端口 9180", (val("port_start"), val("port_end"), val("agent_port")) == ("20000", "30000", "9180"))
    check("[NAT] 内网段默认 10.88.0.1/16", val("bridge_v4") == "10.88.0.1/16", val("bridge_v4"))
    check("[NAT] 后端/存储/转发/IPv6 均为中文下拉且有默认值",
          selv("backend") == "auto" and selv("storage_driver") == "dir" and selv("forward_mode") != "" and selv("enable_ipv6") == "auto"
          and "自动" in s.select_one('select[name="enable_ipv6"]').get_text(), (selv("backend"), selv("storage_driver"), selv("enable_ipv6")))
    imgs = sorted(x.get("value") for x in s.select('input[name="images[]"][checked]'))
    check("[NAT] 常用镜像已预选", {"debian12", "ubuntu2404", "ubuntu2204"} <= set(imgs), imgs)
    names = {x.get("name") for x in s.select("form.nat-form [name]")}
    check("[NAT] 自动字段不出现在表单（令牌/过期/状态/IP/心跳/原始配置）",
          not (names & {"reg_token", "reg_expire", "status", "public_ip", "public_ipv6", "last_heartbeat", "options", "config"}), names)
    check("[NAT] 表单有「快速添加」和令牌有效期（默认 24 小时）",
          s.select_one("button.nat-quick") is not None and selv("token_hours") == "24", selv("token_hours"))
    check("[NAT] 中文标签齐全", all(x in r.text for x in ["面板地址", "后端", "存储类型", "存储大小", "转发端口起", "Agent 端口", "内网段", "小鸡 IPv6", "预下载系统镜像", "转发方式", "最大实例数", "TLS"]))

    # ---------- 2. 直接保存 → 落到安装命令页 ----------
    t0 = time.time()
    r, al = admin.submit("admin/nat_node/edit", {}, match="name")
    check("[NAT] 保存后直接进入安装命令页", "nat_node/command" in r.url and "一键安装命令" in r.text, (r.url, al))
    cmds, url, sha = cmd_info(r.text)
    nid = int(re.search(r"id=(\d+)", r.url).group(1))
    n1 = node(nid)
    check("[NAT] 自动生成一次性注册令牌，默认 24 小时过期",
          n1 and re.fullmatch(r"[0-9a-f]{40,}", n1["reg_token"] or "") and abs(int(n1["reg_expire"]) - t0 - 86400) < 120, n1)
    check("[NAT] 页面显示两条命令、脚本地址、SHA-256、过期时间、复制按钮",
          len(cmds) == 2 and cmds[0].startswith("bash <(curl -fsSL '") and "sha256sum -c" in cmds[1] and re.fullmatch(r"[0-9a-f]{64}", sha)
          and url.startswith(F.BASE) and "令牌过期" in r.text and len(soup(r.text).select("[data-copy-text]")) >= 3, (cmds, url, sha))
    check("[NAT] 注意事项：仅 Debian/Ubuntu、放行 Agent 端口和端口段", "Debian" in r.text and "Ubuntu" in r.text and "9180" in r.text and "20000-30000" in r.text and "安全组" in r.text)
    check("[NAT] 页面不泄露完整令牌以外的密钥（不含 agent_token）", "agent_token" not in r.text)

    # ---------- 3. 脚本地址：匿名可下载、哈希一致 ----------
    sr = requests.get(url, timeout=30)
    body = sr.content
    check("[NAT] 脚本地址返回渲染后的脚本（匿名可访问）", sr.status_code == 200 and body.startswith(b"#!") and ("NODE_ID='%d'" % nid).encode() in body, sr.status_code)
    check("[NAT] 脚本 SHA-256 与页面显示一致", hashlib.sha256(body).hexdigest() == sha, hashlib.sha256(body).hexdigest())
    check("[NAT] 错误令牌拿不到脚本", requests.get(re.sub(r"token=[0-9a-f]+", "token=" + "0" * 48, url), timeout=30).status_code in (401, 403, 404))

    # ---------- 4. 快速添加：第二个节点自动避开端口段/内网段 ----------
    r, al = admin.post("admin/nat_node/quick", {}, referer=F.BASE + "/index.php?s=/admin/nat_node/index")
    nid2 = int(re.search(r"id=(\d+)", r.url).group(1)) if "nat_node/command" in r.url else 0
    n2 = node(nid2) if nid2 else None
    check("[NAT] 快速添加直接显示安装命令", nid2 and len(cmd_info(r.text)[0]) == 2, (r.url, al))
    if nid2:
        rcq, outq, whereq = run_install(cmd_info(r.text)[0][0], register=False)
        check("[NAT] %s 运行「直接运行」命令（不注册）：日志里的 agent_token 已打码" % whereq,
              rcq == 0 and '"agent_token":"***"' in outq and not re.search(r'"agent_token": *"[0-9a-f]{16,}', outq), outq[-600:])
    check("[NAT] 快速添加名称自增「节点-2」，端口段 30001-40000、内网段 10.89.0.1/16",
          n2 and n2["name"] == "节点-2" and '"port_start":30001' in n2["options"] and '"port_end":40000' in n2["options"] and "10.89.0.1/16" in n2["options"], n2)
    r = admin.get("admin/nat_node/edit")
    s = soup(r.text)
    check("[NAT] 再打开表单：名称「节点-3」、端口段 40001-50000、内网段 10.90.0.1/16",
          s.select_one('[name="name"]').get("value") == "节点-3" and s.select_one('[name="port_start"]').get("value") == "40001"
          and s.select_one('[name="bridge_v4"]').get("value") == "10.90.0.1/16")

    # ---------- 5. 列表：状态/心跳/容量/按钮 ----------
    r = admin.get("admin/nat_node/index")
    s = soup(r.text)
    card = s.select_one('.nat-node-card[data-node-id="%d"]' % nid)
    check("[NAT] 列表显示待安装状态、最后心跳、容量、生成安装命令按钮",
          card is not None and card.select_one(".nat-state").get("data-state") == "pending" and "最后心跳" in card.get_text()
          and "0 / " in card.get_text() and card.select_one("[data-nat-cmd]") is not None)
    pr = admin.get("admin/nat_node/command", id=nid, partial=1)
    check("[NAT] 弹窗片段（partial）只含命令内容，不含整页布局", pr.status_code == 200 and "nat-cmd-line" in pr.text and "<html" not in pr.text.lower())

    # ---------- 6. 在 Debian 12 容器里运行页面上的命令 → 注册 ----------
    rc, out, where = run_install(cmds[1])
    check("[NAT] %s 运行「下载+校验」命令：SHA-256 校验通过" % where, "idcnat.sh: OK" in out, out[-800:])
    check("[NAT] %s 安装脚本（DRY_RUN）走完并注册成功" % where, rc == 0 and "安装完成" in out, out[-1500:])
    n1 = node(nid)
    check("[NAT] 注册后令牌作废、写入公网 IP 与心跳、自动建上游",
          n1 and n1["reg_token"] in ("", "NULL") and int(n1["last_heartbeat"]) >= int(t0) and n1["public_ip"] not in ("", "NULL") and int(n1["upstream_id"]) > 0, n1)
    up = int(n1["upstream_id"])
    check("[NAT] 自动创建的上游为 natnode 驱动", one("select driver from %s where id=%d" % (t("upstream"), up)) == "natnode")
    r = admin.get("admin/nat_node/index")
    card = soup(r.text).select_one('.nat-node-card[data-node-id="%d"]' % nid)
    check("[NAT] 列表显示「在线」、IP 与关联上游", card is not None and card.select_one(".nat-state").get("data-state") == "online"
          and n1["public_ip"] in card.get_text(), card.get_text(" ", strip=True)[:300] if card else "")

    # ---------- WHMCS bridge: one-time pairing, HTTPS, auth, discovery, rotation ----------
    sql("insert into %s(name,value) values('trust_proxy_header','X-Forwarded-Proto') on duplicate key update value='X-Forwarded-Proto'" % t("setting"))
    whmcs_table = t("whmcs_nat_instance")
    sql("alter table %s change bridge_id bridge_hash char(64) not null" % whmcs_table)
    sql("delete from %s" % whmcs_table)
    sql("insert into %s (bridge_hash,external_id,node_id,instance_name,status,create_time,update_time) values('%s','99999999999999999999',0,'wabcde-99999999999999999999','active',1,1)" % (whmcs_table, "a" * 64))
    sql("delete from %s where name='nat_whmcs_bridge_id'" % t("setting"))
    sql("update %s set value='5' where name='schema_version'" % t("setting"))
    T.clear_cache()
    r = admin.get("admin/whmcsbridge/index")
    check("[WHMCS] NAT 页面可进入桥接配置", "WHMCS NAT 自动桥接" in r.text and "生成/轮换桥接密钥" in r.text)
    migrated_id = one("select bridge_id from %s where external_id='99999999999999999999'" % whmcs_table)
    migrated_name = one("select instance_name from %s where external_id='99999999999999999999'" % whmcs_table)
    check("[WHMCS] v5 到 v6 自动迁移保留旧服务映射和实例名",
          one("select value from %s where name='schema_version'" % t("setting")) == "6"
          and re.fullmatch(r"[a-f0-9]{32}", migrated_id) is not None and migrated_name == "wabcde-99999999999999999999", (migrated_id, migrated_name))
    r, al = admin.submit("admin/whmcsbridge/index", {}, match="admin/whmcsbridge/rotate")
    token_match = re.search(r"<code[^>]*>([a-f0-9]{64})</code>", r.text)
    bridge_token = token_match.group(1) if token_match else ""
    bridge_id = one("select value from %s where name='nat_whmcs_bridge_id'" % t("setting"))
    check("[WHMCS] 配对密钥只在轮换后显示一次并有稳定桥接身份", bridge_token != "" and re.fullmatch(r"[a-f0-9]{32}", bridge_id) is not None, (r.url, al))
    bridge_url = F.BASE + "/index.php?s=/api/nat/whmcsInfo"
    unauth = requests.get(bridge_url, timeout=30)
    check("[WHMCS] 桥接 API 拒绝未授权请求", unauth.status_code == 401)
    no_tls = requests.get(bridge_url, headers={"Authorization": "Bearer " + bridge_token}, timeout=30)
    check("[WHMCS] 桥接 API 拒绝未加密请求", no_tls.status_code == 400)
    headers = {"Authorization": "Bearer " + bridge_token, "X-Forwarded-Proto": "https"}
    info = requests.get(bridge_url, headers=headers, timeout=30)
    try:
        info_json = info.json()
    except ValueError:
        info_json = {}
    discovered = info_json.get("data", {}).get("nodes", []) if isinstance(info_json, dict) else []
    discovered_node = next((x for x in discovered if int(x.get("id", 0)) == nid), {})
    check("[WHMCS] 配对后自动发现已注册 NAT 节点及剩余容量", info.status_code == 200 and info_json.get("code") == 0
          and discovered_node.get("state") == "online" and int(discovered_node.get("available", 0)) > 0, discovered_node)
    node_payload = str(discovered).lower()
    check("[WHMCS] 自动发现响应不泄露 Agent Token 或安装注册令牌",
          all(secret not in node_payload for secret in ("agent_token", "reg_token", "agent_url", "agent_port")), node_payload[:300])
    r, al = admin.submit("admin/whmcsbridge/index", {}, match="admin/whmcsbridge/rotate")
    token_match = re.search(r"<code[^>]*>([a-f0-9]{64})</code>", r.text)
    rotated_token = token_match.group(1) if token_match else ""
    old_key = requests.get(bridge_url, headers=headers, timeout=30)
    new_headers = {"Authorization": "Bearer " + rotated_token, "X-Forwarded-Proto": "https"}
    new_key = requests.get(bridge_url, headers=new_headers, timeout=30)
    check("[WHMCS] 轮换立即撤销旧密钥、稳定身份不变且新密钥可用",
          rotated_token != "" and rotated_token != bridge_token and one("select value from %s where name='nat_whmcs_bridge_id'" % t("setting")) == bridge_id
          and old_key.status_code == 401 and new_key.status_code == 200, (old_key.status_code, new_key.status_code))

    rc2, out2, _ = run_install(cmds[0])
    check("[NAT] 令牌一次性：同一命令再次运行失败，并提示重新生成令牌", rc2 != 0 and "重新生成令牌" in out2, out2[-500:])
    r = admin.get("admin/nat_node/command", id=nid)
    check("[NAT] 已注册节点的命令页提示已注册、不再显示旧命令", "节点已注册" in r.text and not cmd_info(r.text)[0])
    r = admin.get("admin/upstream/edit", id=up)
    check("[NAT] 上游编辑页显示节点状态（只读，Agent 地址已自动填写）",
          "nat-auto" in r.text and "在线" in r.text and "http" in (soup(r.text).select_one(".nat-agent-url") or soup("<i></i>")).get_text() and "高级：手动填写" in r.text)
    r, al = admin.submit("admin/upstream/edit", {}, match="name", id=up)
    from_db = subprocess.run(["sudo", "mysql", "-N", "-B", F.DB, "-e", "select config from %s where id=%d" % (t("upstream"), up)], capture_output=True, text=True).stdout.strip()
    check("[NAT] 直接保存上游（不勾选手动填写）不会清空自动填写的配置", len(from_db) > 40 and "nat_node" not in r.url, (len(from_db), al))

    # ---------- 7. 重新生成令牌 ----------
    r, al = admin.post("admin/nat_node/regen", {"id": nid}, referer=F.BASE + "/index.php?s=/admin/nat_node/index")
    cmds3, url3, sha3 = cmd_info(r.text)
    check("[NAT] 重新生成令牌后直接显示新命令", "nat_node/command" in r.url and len(cmds3) == 2 and url3 != url, (r.url, al))
    check("[NAT] 重新生成后旧脚本地址失效", requests.get(url, timeout=30).status_code in (401, 403, 404))
    check("[NAT] 新脚本地址可用", requests.get(url3, timeout=30).status_code == 200)

    # ---------- 8. 上游接口 → NAT 类型：保存即自动建节点并显示命令 ----------
    r, al = admin.submit("admin/upstream/edit", {"name": "香港 NAT", "driver": "natnode"}, match="name")
    nid4 = int(re.search(r"id=(\d+)", r.url).group(1)) if "nat_node/command" in r.url else 0
    n4 = node(nid4) if nid4 else None
    check("[NAT] 上游接口选 NAT 类型保存后直接显示安装命令（无需手填 Agent）", nid4 and len(cmd_info(r.text)[0]) == 2, (r.url, al))
    up4 = int(n4["upstream_id"]) if n4 else 0
    check("[NAT] 自动节点关联到刚建的上游，端口段继续避让", up4 and one("select name from %s where id=%d" % (t("upstream"), up4)) == "香港 NAT"
          and '"port_start":40001' in n4["options"], n4)
    r = admin.get("admin/upstream/index")
    check("[NAT] 上游列表 NAT 行有「节点 / 安装命令」入口", "节点 / 安装命令" in r.text)
    r, al = admin.post("admin/upstream/test", {"id": up4}, referer=F.BASE + "/index.php?s=/admin/upstream/index")
    check("[NAT] 未注册的 NAT 上游测试连接给出中文指引", any("还没有节点注册" in m for _, m in al), al)
    r, al = admin.submit("admin/upstream/edit", {"name": "手填 NAT", "driver": "natnode"}, match="name")
    check("[NAT] 上游新建但缺 Agent 时仍自动建节点（不报必填错误）", "nat_node/command" in r.url, (r.url, al))

    # ---------- 9. 离线判定 ----------
    sql("update %s set last_heartbeat=%d where id=%d" % (t("nat_node"), int(time.time()) - 3600, nid))
    card = soup(admin.get("admin/nat_node/index").text).select_one('.nat-node-card[data-node-id="%d"]' % nid)
    check("[NAT] 心跳超时显示「离线」", card is not None and card.select_one(".nat-state").get("data-state") == "offline")

    # ---------- 10. 开启 2FA 后：重新生成令牌需要二次验证 ----------
    r = admin.get("admin/profile/twofa")
    secret = T.page_secret(r.text)
    r, al = admin.submit("admin/profile/twofa", {"code": T.fresh_code(secret, "admin", 1), "password": F.ADMIN_PW}, match="admin/profile/enable")
    check("[NAT] 管理员开启 2FA", int(one("select totp_enabled from %s where id=1" % t("admin"))) == 1, al)
    sql("insert into %s(name,value) values('admin_sudo_minutes','1') on duplicate key update value='1'" % t("setting"))
    T.clear_cache()
    a2 = F.UI("nat-admin-2fa")
    r, al = a2.submit("admin/login/index", {"username": "admin", "password": F.ADMIN_PW})
    r, al = a2.submit("admin/login/twofa", {"code": T.fresh_code(secret, "admin", 1)})
    check("[NAT] 2FA 登录成功", "login" not in r.url, (r.url, al))
    print("  等待二次验证窗口过期（admin_sudo_minutes=1）...", flush=True)
    time.sleep(62)
    tok_before = node(nid)["reg_token"]
    r, al = a2.post("admin/nat_node/regen", {"id": nid}, referer=F.BASE + "/index.php?s=/admin/nat_node/index")
    check("[NAT] 重新生成令牌需要先输入谷歌验证码", "admin/login/sudo" in r.url and node(nid)["reg_token"] == tok_before, r.url)
    r, al = a2.submit("admin/login/sudo", {"code": T.fresh_code(secret, "admin", 1)})
    r, al = a2.post("admin/nat_node/regen", {"id": nid}, referer=F.BASE + "/index.php?s=/admin/nat_node/index")
    check("[NAT] 二次验证后可以重新生成", "nat_node/command" in r.url and node(nid)["reg_token"] != tok_before, (r.url, al))
    r, al = a2.post("admin/nat_node/quick", {}, referer=F.BASE + "/index.php?s=/admin/nat_node/index")
    check("[NAT] 审计日志记录节点创建与令牌重置",
          int(one("select count(*) from %s where action in ('admin.nat_node.create','admin.nat_node.regen')" % t("audit_log"))) >= 4)

    # 收尾：还原 2FA 与设置，避免影响其他套件
    T.cli("reset-2fa", "admin")
    sql("update %s set value='5' where name='admin_sudo_minutes'" % t("setting"))
    T.clear_cache()
    return F.summary() if hasattr(F, "summary") else None


if __name__ == "__main__":
    main()
    fails = [x for x in getattr(F, "RESULTS", []) if not x[1]]
    print("NAT 节点测试：%d passed, %d failed" % (len(getattr(F, "RESULTS", [])) - len(fails), len(fails)))
    sys.exit(1 if fails else 0)
