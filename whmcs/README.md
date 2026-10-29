# WHMCS IDC NAT 服务器模块

将 `modules/servers/idcnat/` 放进 WHMCS 根目录后，WHMCS 会在产品的「模块设置」中识别 **IDC NAT 节点**。模块直接调用 IDCDNS NAT Agent，不需要手动录入每个服务的节点 ID、注册令牌，也不需要复制每个服务的开通命令。

## 安装包目录

```text
modules/servers/idcnat/idcnat.php
modules/servers/idcnat/clientarea.tpl
README.md
```

压缩包以 WHMCS 根目录为解压基准；解压后应得到 `modules/servers/idcnat/`，而不是多一层 `whmcs-nat/` 目录。

## 安装与首次配置

1. 备份 WHMCS 后，将压缩包直接解压到 WHMCS 网站根目录。
2. 在 WHMCS 后台「系统设置 → 产品/服务 → 服务器」添加 NAT 节点服务器：
   - **模块**：`IDC NAT 节点`
   - **主机名或 IP**：Agent 的公网地址；若主机名中填写完整 `https://` URL，模块会沿用该协议。
   - **端口**：默认 `9180`；若 Agent 使用其他端口则填写实际端口。
   - **Password**：该节点 Agent Token（节点 `/etc/idcnat/agent.json` 中的 `agent_token`）。这是 WHMCS 服务器凭据，不是用户的 WHMCS 密码。
   - 配好有效证书与 HTTPS 后再勾选 **Secure**。若使用裸 HTTP，开通/改密请求中的 root 密码和实例资料会以明文传输；生产环境请使用 HTTPS 和有效证书。
3. 在 WHMCS 产品的「模块设置」中选择 **IDC NAT 节点**，设置默认镜像、CPU、内存、磁盘、额外 NAT 端口和带宽；选择已添加的 NAT 节点服务器或服务器组。
4. 若要让客户在下单时选规格，在 WHMCS 产品中另建「可配置选项」组。模块按选项名称读取下列值（值使用纯数字，不要附加单位）：
   - `Image` 或 `OS`：如 `debian12`、`ubuntu2404`
   - `CPU Cores`：核数
   - `Memory MB` 或 `RAM MB`：内存 MB
   - `Disk GB` 或 `Storage GB`：磁盘 GB
   - `NAT Ports`：额外转发端口数量（不含独立 SSH 端口）
   - `Bandwidth Mbps`：带宽 Mbps，`0` 表示不单独设置

**只用固定套餐时，不必创建可配置选项组**：产品的模块默认值即可开通服务。WHMCS 中仍需一次性创建服务器并把服务器分配给产品；这是 WHMCS 的标准设置步骤。之后每个服务的名字会按服务 ID 自动生成（`whmcs-服务ID`），规格从产品默认值或可配置选项自动带入，不需要逐单填命令。

## 节点安装与自动开通

如使用现有 IDCDNS 控制面板管理节点，先在面板「NAT 节点」新增节点。面板现有功能会把节点 ID 和一次性注册令牌直接写进一键安装命令；在节点服务器上运行一次即可安装并注册 Agent。注册令牌只用于节点安装注册；WHMCS 后续与 Agent 通信使用的是 Agent Token。

WHMCS 订单达到自动开通条件时，模块会自动：

- 以 `whmcs-服务ID` 作为稳定实例名，避免 WHMCS 超时重试时重复创建；
- 检查节点启用的镜像，并创建对应的 NAT 实例；
- 在客户服务页显示实例状态、IP、SSH 端口、内网 IP、端口范围与规格；
- 响应 WHMCS 的暂停、恢复、终止、更改密码，并提供开机、关机、重启操作。

Agent 的 `9180/TCP`（或你配置的实际端口）必须允许 WHMCS 服务器访问；节点防火墙还应放行其配置的 NAT 端口段。模块调用方向是 WHMCS 服务器 → NAT Agent。

## 已支持的 Agent API

- `GET /v1/info`
- `GET /v1/instances/{name}`
- `POST /v1/instances`
- `POST /v1/instances/{name}/action`
- `DELETE /v1/instances/{name}`

请求使用 Agent Token 做带时间戳和随机 nonce 的 HMAC-SHA256 签名，不会把 Agent Token 放进 URL 或请求正文。Agent 需启用 IDCDNS NAT Agent 的兼容 API。

## 安全提示

- Agent Token 只应保存在 WHMCS 的服务器配置中；不要放入产品可配置选项、客户自定义字段、欢迎邮件或公开安装命令。
- 推荐用有效 TLS 证书保护 Agent API。不要为规避证书验证而关闭 HTTPS 校验。
- `Terminate` 会删除 NAT 实例及其数据；执行前应确认 WHMCS 的到期与终止策略符合预期。
- 更换或重置 Agent Token 后，也要同步更新 WHMCS 服务器的 Password。

## 版本

`1.0.0` — 首版；PHP 7.0+，遵循 WHMCS 标准服务器模块接口。
