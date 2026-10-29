{if $natError}
    <div class="alert alert-danger" role="alert">{$natError|escape}</div>
{elseif !$natInstance}
    <div class="alert alert-info" role="status">暂时没有可显示的 NAT 实例信息。</div>
{else}
    <div class="panel panel-default">
        <div class="panel-heading"><strong>NAT 云主机信息</strong></div>
        <div class="panel-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <tbody>
                        <tr><th>实例名称</th><td>{$natInstance.name|escape}</td></tr>
                        <tr><th>所在节点</th><td>{$natNodeName|escape}</td></tr>
                        <tr><th>运行状态</th><td>{$natInstance.status|escape}</td></tr>
                        <tr><th>系统镜像</th><td>{$natInstance.image|escape}</td></tr>
                        <tr><th>公网 IPv4</th><td>{$natPublicIp|escape}</td></tr>
                        <tr><th>SSH 用户</th><td>root</td></tr>
                        <tr><th>SSH 端口</th><td>{$natInstance.ssh_port|escape}</td></tr>
                        <tr><th>内网 IPv4</th><td>{$natInstance.ipv4_internal|default:'—'|escape}</td></tr>
                        <tr><th>IPv6</th><td>{$natInstance.ipv6|default:'—'|escape}</td></tr>
                        <tr><th>映射端口范围</th><td>{$natInstance.port_start|escape}–{$natInstance.port_end|escape}（范围首端口用于 SSH）</td></tr>
                        <tr><th>CPU</th><td>{$natInstance.cpu|escape} 核</td></tr>
                        <tr><th>内存</th><td>{$natInstance.memory_mb|escape} MB</td></tr>
                        <tr><th>磁盘</th><td>{$natInstance.disk_gb|escape} GB</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-muted">SSH 密码使用本服务的 WHMCS 密码；可通过 WHMCS 的“更改密码”功能重置。</p>
            <p class="text-muted">开机、关机和重启按钮会显示在本服务的操作菜单中。</p>
        </div>
    </div>
{/if}
