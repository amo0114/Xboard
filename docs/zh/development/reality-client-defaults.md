# VLESS Reality 客户端订阅默认值

Xboard 自动为 VLESS + Reality 节点生成以下客户端选项。新建节点和没有
`reality_client` 字段的已有节点都会生效，无须修改数据库、逐节点补字段或修改 V2bX。

| 客户端 | 默认输出 |
| --- | --- |
| Mihomo / Clash Meta | `reality-opts.support-x25519mlkem768: true` |
| Mihomo / Clash Meta | 未指定 uTLS 指纹时使用 `client-fingerprint: chrome` |
| Loon | `tls-profile=safari-ios-26` |

Clash Party、Mihomo Party 的常见 User-Agent 会匹配 ClashMeta 订阅处理器。
普通 TLS、明文节点及其他协议不会获得这些 Reality 专用订阅字段。

## 节点覆盖

默认情况下不需要设置以下内容。需要兼容特定客户端或服务端时，可通过管理 API
在节点的 `protocol_settings` 中显式设置：

```json
{
  "reality_client": {
    "support_x25519mlkem768": false,
    "loon_tls_profile": "global"
  }
}
```

- 缺失、`null`、空对象均继承默认值，模型读取与保存行为一致。
- 显式 `false` 保留，并关闭 Mihomo 的 MLKEM 选项输出。
- 指定 Loon profile 会保留。可选值由 ServerSave 的规则验证。
- 已指定的 Mihomo uTLS 指纹会保留。

这些字段只控制客户端订阅，`ServerService::buildNodeConfig()` 不会将它们发送给
V2bX，因此不会改变服务端的 Reality 密钥、SNI、监听端口或协议。

## 验证

在已安装项目 Composer 依赖的环境执行：

```sh
php tests/Regression/reality_client_defaults.php
```

检查不需要数据库或 `.env`，覆盖新旧节点、缺省及空值、显式覆盖、YAML 序列化、
普通 TLS 保持原输出、XHTTP flow、服务端 API 隔离和 Party 订阅识别。

发布前还应使用实际订阅验证新 Xray 与既有 sing-box 后端的认证及 HTTPS 转发。
TCP 端口探测不能替代协议验收；客户端需要更新订阅才能获得新字段。

## 部署代码

将以下四个文件纳入部署镜像或持久挂载：

- `app/Models/Server.php`
- `app/Http/Requests/Admin/ServerSave.php`
- `app/Protocols/ClashMeta.php`
- `app/Protocols/Loon.php`

生产容器与持久部署源应使用相同内容。升级面板源码时应合并保留本修改，
并运行上述回归检查；单独升级 V2bX 不会更新 Xboard 的订阅生成代码。
