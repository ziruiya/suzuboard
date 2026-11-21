# 00 · 契约清单与盘点（Contract Inventory）

## 症状
- 需要确认某条线路在客户端订阅里到底冻结了什么参数（域名、端口、协议、SNI、Reality 公钥指纹、gRPC 服务名、WS 路径），或怀疑面板里的节点参数已经被人改过、与用户手里的旧订阅不一致。
- 典型触发：换机/换 IP 前摸底；用户报告「订阅没更新但线路突然不能用」；VX 窗口重建前核对。

## 适用契约
- 全部契约：`suzu_endpoint_contracts` 中 `status='active'` 的行，一行对应一个逻辑节点（`logical_node_id` = v2_server.id）。
- 分组口径：A 组 = VLESS+Reality 直连；B 组 = Trojan+gRPC（服务名 `SuzuStream`）；C 组 = VLESS+WS+TLS（路径 `/SuzuWebSocket`）；VIP = VLESS+Reality（经 VX 中转或临时直连）；browser 组 = http 代理（show=0，不进订阅）。

## 只读诊断
1. 面板容器内执行：`php artisan suzu:endpoint-snapshot --verify` —— 逐条比对契约哈希与现行节点参数，输出每条契约 OK / DRIFT 及漂移字段。
2. 管理 API：`GET /{secure_path}/suzu/endpoint/contracts` 看全部契约+绑定；`GET .../suzu/endpoint/contract?id=<契约id>` 看单条的完整性校验与最近 50 条审计。
3. 对照订阅实物：用巡检号（id 429，永不删除）拉一份订阅，逐项核对 host/port/协议参数与契约是否一致。

## 修复动作
- 本手册只盘点，不修复。发现 DRIFT 时不要直接重新快照——先查清是谁、什么时候、为什么改了节点参数（面板审计日志 + suzu_endpoint_changes 里的 supersede 记录）。
- 确认漂移是合法变更（如运营侧主动轮换）后，才执行重新快照（见手册 02 的发布前检查）；非法或不明漂移按事故处理，走手册 11 取证。

## 发布前检查
- `--verify` 输出 0 drifted，或每条 drift 都有书面解释并已签收。
- 契约表里不得出现任何私钥/明文凭据字段——契约只存 Reality 公钥的 SHA-256 指纹；发现异常字段立即停手上报。

## 用户侧验证
- 盘点本身不改任何东西，用户侧无感。若盘点伴随订阅对照，确认巡检号订阅与盘点前逐字节一致。

## 停手条件
- `--verify` 大面积 DRIFT（≥5 条）且无人能解释：停手，说明有未知的批量改动发生，先取证（手册 11）。
- 契约表缺失（迁移未跑）：停手，先确认面板镜像版本与迁移状态，不要手工建表。

## 回滚与审计
- 盘点为只读操作，无回滚。所有快照/取代动作都会自动写入 `suzu_endpoint_changes`（action=snapshot/supersede），盘点结论引用其中的记录 id 作为依据。
