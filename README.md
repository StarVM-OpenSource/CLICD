# CLICD 魔方财务对接模块

这是用于智简魔方/IDCSMART 的 CLICD 服务器模块。模块通过 CLICD API 完成实例开通、删除、开关机、重装、改密、资源变更、流量重置、NAT 端口映射管理和 WebSSH。

## 文件结构

```text
clicd.php
README.md
handlers/
  webssh.php
templates/
  info.html
  nat.html
```

安装时请保持目录结构不变，将整个 `clicd` 目录放入魔方服务器模块目录：

```text
public/plugins/servers/clicd/
```

## 服务器配置

在魔方后台添加服务器时，模块名称选择 `clicd`。

CLICD 面板地址建议直接填写 HTTPS 地址：

```text
主机名 = https://0.0.0.0:8999
```

也可以拆分填写：

```text
IP地址 = 0.0.0.0
端口      = 8999
secure    = 开启
```

API Key 可以填写在以下任意一个字段中：

```text
Hash
密码
```

模块请求 CLICD 时会同时携带：

```text
X-API-Key: clicd_sk_xxxx
Authorization: Bearer clicd_sk_xxxx
Content-Type: application/json
```

## 产品配置项

| 字段 | 说明 |
| --- | --- |
| `virtualization` | 虚拟化类型，`lxc` 或 `kvm` |
| `template_id` | CLICD 模板/镜像 ID |
| `vcpu` | CPU 核心数 |
| `cpu_percent` | CPU 使用率限制，`0` 表示不额外限制 |
| `ram_mb` | 内存，单位 MB |
| `disk_gb` | 系统盘，单位 GB |
| `network_bw_mbps` | 带宽，单位 Mbps |
| `traffic_mode` | `total` 总流量，或 `in_out` 入/出分开 |
| `monthly_traffic_gb` | 月流量 GB |
| `traffic_in_gb` | 入站流量 GB，`in_out` 模式使用 |
| `traffic_out_gb` | 出站流量 GB，`in_out` 模式使用 |
| `io_speed_mbps` | 磁盘 IO 限制，`0` 表示不限制 |
| `port_mapping_count` | 开通时分配的 NAT 端口数量，最小 2 |
| `snapshot_limit` | 快照配额 |
| `extra_ports` | 额外映射的容器端口，逗号分隔，例如 `80,443` |
| `assign_ipv6` | 开通时是否自动分配 IPv6 |
| `sync_expiry` | 是否同步魔方到期时间到 CLICD |

客户产品的 `domain` 会作为 CLICD 容器名称。模块会自动把不适合作为容器名的字符替换为 `-`。

## 开通后字段同步

开通、同步、重装、改密后，模块会从 CLICD 容器详情拉取最新信息并写回魔方主机表：

| 魔方字段 | 写入内容 |
| --- | --- |
| `dedicatedip` | NAT 外网 IP，优先使用 API 返回的公网字段，否则使用服务器 IP |
| `username` | 固定写入 `root` |
| `password` | CLICD 返回的 SSH 密码，兼容魔方 `cmf_encrypt()` |
| `port` | CLICD 返回的 `ssh_port` |
| `domainstatus` | CLICD 状态为 `running` 时写 `Active`，否则写 `Suspended` |

如果接口返回的密码是 `***` 这类脱敏值，模块不会覆盖魔方里已有密码。

## 客户区页面

模块提供两个客户区选项卡：

```text
实例信息
NAT转发
```

客户区按钮提供：

```text
WebSSH
```

### 实例信息

实例信息页展示基础实例信息、SSH 信息、资源配置、到期时间、流量统计和资源图表。

图表数据通过客户区懒加载接口刷新，不会强制刷新整个魔方页面。当前展示项包括：

- CPU 使用率
- 内存使用
- 负载
- 磁盘使用
- 月流量进度
- 网络流量曲线
- 磁盘 IO 曲线

模块会尝试调用：

```text
GET /api/v1/containers/{name}/usage
GET /api/v1/containers/{name}/traffic
```

如果 CLICD 返回字段不完整，页面会显示 `-` 或 `0`，不会卡在“加载中”。

### NAT 转发

NAT 转发是独立页面，支持：

- 查看端口映射
- 获取随机可用端口
- 添加端口映射
- 修改端口映射
- 删除端口映射

使用的 CLICD API：

```text
GET    /api/v1/containers/{id|uuid|name}
GET    /api/v1/containers/{id}/random-port
POST   /api/v1/containers/{id}/port-mappings
PUT    /api/v1/containers/{id}/port-mappings/{index}
DELETE /api/v1/containers/{id}/port-mappings/{index}
```

添加/修改 NAT 映射时必须使用 JSON 请求体，例如：

```json
{
  "container_port": 8080,
  "host_port": 61320,
  "protocol": "tcp",
  "description": "HTTP"
}
```

### WebSSH

WebSSH 按钮会调用：

```text
POST /api/v1/ssh-ticket
```

请求体：

```json
{
  "container_name": "example-vm"
}
```

接口返回 60 秒有效票据后，模块会打开本地 handler：

```text
/plugins/servers/clicd/handlers/webssh.php
```

浏览器会从该页面直连 CLICD：

```text
wss://0.0.0.0:8999/api/ssh?container=example-vm
Sec-WebSocket-Protocol: clicd-ticket.xxxxx
```

注意：魔方客户区通常是 HTTPS，因此 CLICD 面板也必须启用 HTTPS/WSS。请把魔方服务器配置里的 `server_host` 改为 `https://...`，或把 `secure` 设为 `1`，否则浏览器会阻止从 HTTPS 页面发起不安全的 `ws://` 连接。

## 支持的魔方操作

| 魔方操作 | CLICD API |
| --- | --- |
| 连接测试 | `GET /api/v1/dashboard` |
| 开通 | `POST /api/v1/containers` |
| 删除 | `DELETE /api/v1/containers/{name}/delete` |
| 开机 | `POST /api/v1/containers/{name}/start` |
| 关机 | `POST /api/v1/containers/{name}/stop` |
| 重启 | `POST /api/v1/containers/{name}/restart` |
| 重装 | `POST /api/v1/containers/{name}/reinstall` |
| 改密 | `POST /api/v1/containers/{name}/reset-password` |
| 重置流量 | `POST /api/v1/containers/{name}/traffic-reset` |
| 变更资源 | `PUT /api/v1/containers/{name}/resource-limit` |
| 变更流量 | `PUT /api/v1/containers/{name}/traffic-limit` |
| 同步到期 | `PUT /api/v1/containers/{name}/expiry` |
| WebSSH | `POST /api/v1/ssh-ticket` |

## 建议 API 权限

API Key 至少需要以下权限，具体名称以 CLICD 后端实际权限系统为准：

```text
dashboard:read
container:read
container:create
container:power
container:delete
container:reinstall
container:password
container:traffic
container:resize
container:port
task:read
ssh-ticket:create
```

如果 API Key 使用 `*` 或 `admin:*`，通常可以覆盖上述权限。

## 建议先测试的 curl

连接测试：

```bash
curl -H "X-API-Key: clicd_sk_xxxx" \
  https://0.0.0.0:8999/api/v1/dashboard
```

容器详情：

```bash
curl -H "X-API-Key: clicd_sk_xxxx" \
  https://0.0.0.0:8999/api/v1/containers/example-vm
```

修改 NAT：

```bash
curl --location --request PUT \
  "https://0.0.0.0:8999/api/v1/containers/10/port-mappings/1" \
  --header "X-API-Key: clicd_sk_xxxx" \
  --header "Authorization: Bearer clicd_sk_xxxx" \
  --header "Content-Type: application/json" \
  --data-raw '{"container_port":8081,"host_port":61320,"protocol":"tcp","description":"HTTP"}'
```

创建 WebSSH 票据：

```bash
curl --location --request POST \
  "https://0.0.0.0:8999/api/v1/ssh-ticket" \
  --header "X-API-Key: clicd_sk_xxxx" \
  --header "Content-Type: application/json" \
  --data-raw '{"container_name":"example-vm"}'
```

## 常见问题

### NAT 修改不生效

确认请求体必须是 JSON，不要使用 `multipart/form-data`。正确请求头：

```text
Content-Type: application/json
```

### WebSSH 打不开或提示不安全 WebSocket

请确认 CLICD 面板已经启用 HTTPS/WSS，并且魔方服务器配置使用 HTTPS：

```text
主机名 = https://0.0.0.0:8999
```

如果仍然使用 `http://`，模块会生成 `ws://` 地址，HTTPS 客户区页面会被浏览器拦截。

### 开通后魔方里的 IP、端口、密码不对

执行“同步状态”或重装/改密后，模块会重新拉取容器详情。请确认 CLICD 容器详情接口能返回：

```text
ssh_port
ssh_password
status
```

公网 IP 优先使用 `nat_public_ip/public_ip/host_ip/external_ip/node_ip/nat_host` 等字段；如果接口没有返回，则使用魔方服务器配置的 IP。

### 图表数据为空

模块会尝试兼容多种字段名，例如 `cpu_percent`、`memory_used`、`rx_bytes`、`tx_bytes`、`disk_read_bps`、`disk_write_bps` 等。若后端没有返回对应字段，页面会显示默认值，不会影响基础实例信息和 NAT 页面使用。
