# 04 · 预检失败判读（Preflight Failure Diagnosis）

## 症状
- `POST /{secure_path}/suzu/endpoint/preflight` 返回 overall 不是 passed，切换被卡住。预检是切换前的硬闸，不许绕过——判读每一项 check 的含义，把问题修在落地机侧或契约侧。

## 适用契约
- 全部契约。预检结果存在 binding 的 `last_preflight` 与审计行（action=preflight）里，判读以 checks 数组逐项为准。

## 只读诊断（按 check 名判读）
- `contract_integrity` 失败：现行节点参数与冻结契约漂移。看 detail 里列出的字段，回手册 00 查清漂移来源。契约错了走重新快照；节点被误改就把节点改回契约值。**不要为了过检而无条件重新快照**——那等于把错误参数合法化。
- `probe_credential` 失败：巡检号（配置 `suzu_endpoint.probe.user_id`，默认 429）不存在或无 uuid。查账号是否被误删/禁用。
- `tcp_connect` 失败：目标 IP:server_port 不可达。新机 agent 未部署、端口未监听、防火墙未放行、或 IP 写错。注意 xboard-node 一机一监听：同机同 server_port 只能挂一个节点。
- `tls_handshake` 失败：端口通但 TLS 起不来——对端不是 TLS 服务、证书链损坏、或 SNI 被拒。B/C 组先查 agent 的证书是否已签发（DNS-01 需要域名先解析到本机）。
- `ws_vless_semantic` 失败：WS 升级被拒（路径与契约 `ws_path` 不符，多为新机节点 transport 配置没对齐）或升级成功但 VLESS 无应答（uuid 命名空间不对、新机节点没启用）。先对路径，再对节点 enabled 状态（注意：面板 toggle 关闭后 agent 需要约 170s 才真正卸载/重装监听，短 toggle 不生效）。
- `grpc_trojan_semantic` 失败：HTTP/2 没起来（ALPN h2 未协商、前面挡了普通 Web 服务器）或 gRPC 通了但无中继应答（服务名与契约 `grpc_service_name` 不符——必须逐字 `SuzuStream`；或 Trojan 密码命名空间不对）。
- `reality_dest_certificate`：degraded 专用项。SAN 覆盖伪装 SNI 只证明「像 Reality」，不证明密钥对；overall=degraded 时缺的是 sing-box 引擎（面板镜像应内置 /usr/local/bin/sing-box），不是落地机的错。
- `singbox_tunnel_http` / `singbox_exit_ip` 失败：隧道没建成（密钥/short_id/服务名/路径任一不符都会死在这里）或隧道通但出口 IP 不是候选机（流量被别的出口带走，查新机路由/是否误连了旧机）。

## 修复动作
- 按判读逐项修落地机或节点配置，修一项重跑一次预检，直到 passed。不许多项一起乱改——分不清是哪项起的作用。
- 引擎类问题（sing-box 缺失）：确认面板容器内 `/usr/local/bin/sing-box version` 可用；缺失说明镜像版本不对，按面板发布流程处理，不要在宿主机临时塞二进制。

## 发布前检查
- 最终一次预检 overall=passed，且 checks 里没有 degraded 标记项。
- 预检的 target_ip 与即将切换的目标 IP 逐字一致（cutover 接口会强制比对）。

## 用户侧验证
- 预检本身不改任何生产状态，用户侧无感、无需验证。

## 停手条件
- 同一 check 连续修 3 次仍失败：停手，把完整 checks 数组与落地机配置带回讨论，不要开始猜。
- integrity 漂移涉及 Reality 公钥指纹或 gRPC 服务名：停手上报——这两项变了意味着旧订阅可能已实质失效，属于事故（手册 11）。

## 回滚与审计
- 预检只读，无回滚。每次预检都留审计行，失败判读时引用其 id。
