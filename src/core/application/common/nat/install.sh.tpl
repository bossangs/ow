#!/usr/bin/env bash
set -euo pipefail
PANEL_URL='__PANEL_URL__'; NODE_ID='__NODE_ID__'; REG_TOKEN='__REG_TOKEN__'; PORT='__AGENT_PORT__'; BACKEND='__BACKEND__'; STORAGE_DRIVER='__STORAGE_DRIVER__'; STORAGE_SIZE='__STORAGE_SIZE_GB__'; POOL_PATH='__POOL_PATH__'; PORT_START='__PORT_START__'; PORT_END='__PORT_END__'; BRIDGE_V4='__BRIDGE_V4__'; ENABLE_IPV6='__ENABLE_IPV6__'; IMAGES='__IMAGES__'; FORWARD_MODE='__FORWARD_MODE__'; MAX_INSTANCES='__MAX_INSTANCES__'; TLS='__TLS__'; AGENT_B64='__AGENT_PY_B64__'
log(){ printf '[idcnat] %s\n' "$*"; }
die(){ printf '[idcnat] error: %s\n' "$*" >&2; exit 1; }
if [ "${IDCNAT_TEST_ALLOW_NONROOT:-0}" != 1 ] && [ "$(id -u)" -ne 0 ]; then die '请使用 root 运行'; fi
# shellcheck disable=SC1091
. /etc/os-release 2>/dev/null || true
if [ "$ENABLE_IPV6" = auto ]; then if ip -6 route get 2606:4700:4700::1111 >/dev/null 2>&1; then ENABLE_IPV6=1; else ENABLE_IPV6=0; fi; log "IPv6 自动检测：$([ "$ENABLE_IPV6" = 1 ] && printf '有公网 IPv6，已启用' || printf '无公网 IPv6，不启用')"; fi
run(){ if [ "${IDCNAT_DRY_RUN:-0}" = 1 ]; then printf '+ '; printf '%q ' "$@"; printf '\n'; else "$@"; fi; }
if [ "${IDCNAT_SKIP_INSTALL:-0}" != 1 ]; then
  case "${ID:-}" in debian|ubuntu) ;; *) die '仅支持 Debian/Ubuntu 系统';; esac
  run apt-get update -y; run apt-get install -y curl ca-certificates python3 openssl iptables nftables
  if [ "$BACKEND" = lxd ]; then run apt-get install -y snapd; run snap install lxd --channel=5.21/stable || true
  elif ! command -v incus >/dev/null 2>&1; then
    if [ "${ID:-}" = debian ] && [ "${VERSION_ID:-}" = 12 ]; then run apt-get install -y -t bookworm-backports incus || true; fi
    if ! command -v incus >/dev/null 2>&1; then run install -d -m 755 /etc/apt/keyrings; run curl -fsSL https://pkgs.zabbly.com/key.asc -o /etc/apt/keyrings/zabbly.asc; run apt-get update -y; run apt-get install -y incus; fi
  fi
  case "$STORAGE_DRIVER" in btrfs) run apt-get install -y btrfs-progs;; zfs) run apt-get install -y zfsutils-linux;; lvm) run apt-get install -y lvm2;; esac
fi
if command -v incus >/dev/null 2>&1 || [ "$BACKEND" = incus ]; then HV=incus; else HV=lxc; fi
if [ "${IDCNAT_SKIP_INSTALL:-0}" != 1 ]; then
  run "$HV" storage create default "$STORAGE_DRIVER" size="${STORAGE_SIZE}GB" ${POOL_PATH:+source="$POOL_PATH"} || true
  run "$HV" network create idcnatbr0 ipv4.address="$BRIDGE_V4" ipv4.nat=true ipv6.address="$([ "$ENABLE_IPV6" = 1 ] && printf auto || printf none)" ipv6.nat="$([ "$ENABLE_IPV6" = 1 ] && printf true || printf false)" || true
  run "$HV" profile device add default root disk path=/ pool=default || true
  run "$HV" profile device add default eth0 nic nictype=bridged parent=idcnatbr0 || true
  run install -d -m 755 /etc/sysctl.d
  { printf 'net.ipv4.ip_forward=1\n'; if [ "$ENABLE_IPV6" = 1 ]; then printf 'net.ipv6.conf.all.forwarding=1\n'; fi; } | run tee /etc/sysctl.d/99-idcnat.conf >/dev/null
  run sysctl --system
  for image in ${IMAGES//,/ }; do case "$image" in debian11|debian12|debian13|ubuntu2004|ubuntu2204|ubuntu2404|alpine320|alpine321|rocky9|alma9) run "$HV" image copy "images:$image" local: --alias "$image" || true;; *) log "warning: skipping unknown image $image";; esac; done
fi
if [ "${IDCNAT_DRY_RUN:-0}" = 1 ]; then ROOT=${TMPDIR:-/tmp}/idcnat-dry; CONF="$ROOT/agent.json"; mkdir -p "$ROOT"; chmod 700 "$ROOT"; else ROOT=/opt/idcnat; CONF=/etc/idcnat/agent.json; run install -d -m 700 "$ROOT" /etc/idcnat /var/lib/idcnat /etc/systemd/system; fi
printf '%s' "$AGENT_B64" | base64 -d > "${ROOT}/idcnat_agent.py"; chmod 755 "${ROOT}/idcnat_agent.py"
if [ ! -s "$CONF" ] || [ "${IDCNAT_RESET_TOKEN:-0}" = 1 ]; then TOKEN=$(openssl rand -hex 32); else TOKEN=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("agent_token",""))' "$CONF"); [ -n "$TOKEN" ] || TOKEN=$(openssl rand -hex 32); fi
PUBLIC4=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++)if($i=="src"){print $(i+1);exit}}' || true); PUBLIC6=$(ip -6 route get 2606:4700:4700::1111 2>/dev/null | awk '{for(i=1;i<=NF;i++)if($i=="src"){print $(i+1);exit}}' || true); HOST=$(hostname)
python3 - "$CONF" "$PANEL_URL" "$NODE_ID" "$TOKEN" "$PORT" "$BACKEND" "$PORT_START" "$PORT_END" "$MAX_INSTANCES" "$IMAGES" "$FORWARD_MODE" "$TLS" "$PUBLIC4" "$PUBLIC6" <<'PY'
import json,os,sys
f,u,n,t,p,b,ps,pe,m,imgs,fm,tls,ip4,ip6=sys.argv[1:]
c={'panel_url':u,'node_id':n,'agent_token':t,'port':int(p),'backend':b,'port_start':int(ps),'port_end':int(pe),'max_instances':int(m),'images':[x for x in imgs.split(',') if x],'forward_mode':fm,'tls':tls=='1','state_file':'/var/lib/idcnat/state.json','public_ipv4':ip4,'public_ipv6':ip6}
if c['tls']: c['tls_cert']='/etc/idcnat/server.crt'; c['tls_key']='/etc/idcnat/server.key'
os.makedirs(os.path.dirname(f),exist_ok=True)
with open(f,'w') as h: json.dump(c,h)
os.chmod(f,0o600)
PY
if [ "$TLS" = 1 ]; then run openssl req -x509 -nodes -newkey rsa:2048 -days 825 -subj "/CN=$HOST" -keyout /etc/idcnat/server.key -out /etc/idcnat/server.crt; fi
if [ "${IDCNAT_DRY_RUN:-0}" != 1 ]; then cat > /etc/systemd/system/idcnat-agent.service <<UNIT
[Unit]
Description=IDCDNS NAT Agent
After=network-online.target
[Service]
ExecStart=/usr/bin/python3 /opt/idcnat/idcnat_agent.py
Restart=always
[Install]
WantedBy=multi-user.target
UNIT
fi
run systemctl daemon-reload; run systemctl enable --now idcnat-agent
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q active; then run ufw allow "${PORT}/tcp"; fi
if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then run firewall-cmd --permanent --add-port="${PORT}/tcp"; run firewall-cmd --reload; fi
PAYLOAD=$(TLS_RENDER="$TLS" python3 - "$NODE_ID" "$REG_TOKEN" "$PUBLIC4" "$PUBLIC6" "$PORT" "$BACKEND" "$TOKEN" "$HOST" "$PORT_START" "$PORT_END" "$MAX_INSTANCES" "$IMAGES" <<'PY'
import json,os,sys
n,r,i4,i6,p,b,t,h,ps,pe,m,imgs=sys.argv[1:]
print(json.dumps({'node_id':n,'reg_token':r,'agent_url':('https' if os.environ.get('TLS_RENDER')=='1' else 'http')+'://'+(i4 or ('['+i6+']' if i6 else ''))+':'+p,'agent_token':t,'public_ipv4':i4,'public_ipv6':i6,'backend':b,'hostname':h,'cpu_cores':os.cpu_count() or 1,'mem_mb':0,'disk_gb':0,'port_start':int(ps),'port_end':int(pe),'max_instances':int(m),'images':[x for x in imgs.split(',') if x],'agent_version':'1.1.0'}, separators=(',',':')))
PY
)
if [ "${IDCNAT_DRY_RUN:-0}" = 1 ] && [ "${IDCNAT_DRY_RUN_REGISTER:-0}" != 1 ]; then log "dry-run registration payload: $(printf '%s' "$PAYLOAD" | sed 's/"agent_token": *"[^"]*"/"agent_token":"***"/')"; else
  RESPONSE=$(curl -fsS -X POST -H 'Content-Type: application/json' --data "$PAYLOAD" "$PANEL_URL/index.php?s=/api/nat/register") || { printf '%s\n' "$PAYLOAD" | sed 's/"agent_token": *"[^"]*"/"agent_token":"***"/'; exit 1; }
  printf '%s' "$RESPONSE" | grep -q '"code"[[:space:]]*:[[:space:]]*0' || { printf '%s\n' "$PAYLOAD" | sed 's/"agent_token": *"[^"]*"/"agent_token":"***"/'; exit 1; }
fi
log "安装完成: backend=$BACKEND agent_port=$PORT public_ipv4=$PUBLIC4 public_ipv6=$PUBLIC6"
