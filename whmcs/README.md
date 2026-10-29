# WHMCS IDC NAT 自动对接模块

这个 WHMCS 包含一个 NAT 产品模块和一个全局配对 Addon。WHMCS 产品开通时会向 IDCDNS 面板查询可用节点并自动分配实例；不需要在 WHMCS「服务器」页面新增 Server，也不需要逐台添加 NAT Agent。

## 压缩包结构

ZIP 以 WHMCS 根目录为解压基准：

```text
modules/servers/idcnat/idcnat.php
modules/servers/idcnat/clientarea.tpl
modules/addons/idcnatbridge/idcnatbridge.php
README.md
```

解压后必须直接得到 `modules/`，不要多出一层 `whmcs/` 或 `whmcs-nat/` 目录。

## 安装与一次性配对

1. 备份 WHMCS 文件，将 ZIP 解压到 WHMCS 根目录。
2. 在 IDCDNS 后台进入「NAT 节点 → WHMCS 自动对接」，点击生成桥接密钥并复制。密钥只显示一次；轮换会立即撤销旧密钥，但不会丢失已建立的实例映射。
3. WHMCS 后台进入「系统设置 → Addon Modules」，找到 **IDC NAT Bridge** 并启用。配置面板根地址（例如 `https://panel.example.com`）和桥接密钥。
4. 打开 WHMCS「Addons → IDC NAT Bridge」确认面板连接成功，并能自动列出节点及容量。
5. 在 NAT 产品的「模块设置」里选择 `IDC NAT（面板自动发现）`。**不要新增 WHMCS Server 记录**；本模块的产品开通、查询和生命周期操作直接读取刚才配对的全局面板配置。
6. 在产品模块设置中选择默认镜像、CPU、内存、磁盘、额外 NAT 端口和带宽。若客户要按单选择规格，可配置这些选项（值用纯数字，不带单位）：
   - `Image` 或 `OS`：如 `debian12`、`ubuntu2404`
   - `CPU Cores`：CPU 核数
   - `Memory MB` 或 `RAM MB`：内存 MB
   - `Disk GB` 或 `Storage GB`：磁盘 GB
   - `NAT Ports`：额外转发端口数（不含独立 SSH 端口）
   - `Bandwidth Mbps`：带宽 Mbps，`0` 表示不单独设置

此流程仍需管理员在 Addon Modules 完成一次信任配对（面板地址和密钥）；WHMCS 官方 API 没有公开的 `AddServer` 命令，因此本包不直接写 WHMCS 内部服务器表，也不会用不受支持的方式偷偷创建服务器记录。完成配对后，添加节点不需要在 WHMCS 手工添加任何服务器或 Agent 字段。

## NAT 节点安装与自动开通

每台新 NAT 主机仍需在 IDCDNS 后台生成的一键安装命令上运行一次，以安装并注册 Agent。注册成功后，节点会自动出现在面板及 WHMCS Addon 状态页；WHMCS 不读取或保存 root 安装命令，也无需人工填写节点编号或注册令牌。

WHMCS 订单达到自动开通条件后，面板自动选择在线且有容量的节点，并记录服务与 NAT 实例的对应关系。模块支持开通重试幂等、暂停、恢复、终止、改密、开机、关机和重启；客户服务页显示节点名、实例状态、IP、SSH 端口、内网地址、端口范围和规格。

## 安全说明

- 桥接密钥在 IDCDNS 数据库中仅保存 SHA-256 摘要；WHMCS Addon 配置用密码字段存储。请限制 WHMCS 管理后台和数据库访问。
- 桥接 API 强制 HTTPS，使用 Bearer 密钥、验证 TLS 证书且不跟随重定向。生产环境不得使用裸 HTTP。
- WHMCS 不会收到 Agent Token、节点注册令牌或 root 安装命令。
- 轮换密钥会立即撤销旧密钥；需同步更新 WHMCS Addon 配置。已有实例映射会保留。
- 终止服务会删除对应 NAT 实例及其数据；启用自动终止前确认 WHMCS 订单策略。

## 版本

`2.1.0` — 全局 Addon 配对、无需 Server 记录、面板自动发现/分配；PHP 7.0+。需要安装带 schema v6 桥接 API 的 IDCDNS 面板；从 v5 升级会自动迁移已有实例映射。
