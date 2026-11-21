# 05 · 回滚（Rollback Procedures）

## 症状
- 切换/重建/部署之后线路反而坏了，需要在最短时间内回到上一个已知好状态。

## 适用契约
- DNS 型绑定（手册 02 的切换）、VX 型绑定（手册 03 的重建）、面板本身（suzuboard 镜像）。

## 只读诊断
1. 先定性：坏的是「新目标本身有问题」还是「切换动作写错了值」？查审计行（`suzu_endpoint_changes` 最新一条）：old/new binding JSON、postcheck 的 read_back 与 verified。
2. 公共 DoH 查入口域名现值，确认 DNS 实际指向与 binding 记录是否一致。

## 修复动作
- **DNS 回滚（首选一键）**：`POST /{secure_path}/suzu/endpoint/rollback`，传原 cutover 的 change_id + 当前 expected_revision + confirm=true。服务会把记录改回 old_binding 里存的旧 IP、读回校验、原审计行标记 rolled_back。
- **一键回滚不可用时**（审计行缺失/旧值为空）：手工用 Cloudflare API 按 record ID 把 content 改回旧 IP，事后补审计说明，并修正 binding 的 current_entry_ip 与现实一致——次序是先改 DNS 救命，再补账。
- **VX 回滚**：按备份定义重建原规则、面板节点 port 改回原入口端口；重建落区不可控，落死区时改走「临时直连」兜底（节点 host/port 直指落地机域名:server_port，参照节点 26/46 的现行做法），并登记待窗口重建。
- **面板回滚**：恢复备份的 compose 旧 tag 后 `docker compose up -d`（备份目录形如 `/root/muse-backups-<日期>-<主题>/`，内含 compose.yaml.bak 与 old-image-tag.txt）。

## 发布前检查
- 回滚目标值来自审计/备份实物，不凭记忆。
- 回滚后等 2×TTL（节点条目 TTL 120s）再验证，不要在缓存未过期时误判回滚失败又二次操作。

## 用户侧验证
- 上海机 sing-box 旧订阅参数 204 ×2、出口 IP = 旧落地 IP。
- 巡检号订阅逐字节与事发前一致。

## 停手条件
- 旧值本身已不可达（旧机已退役/旧 IP 已被封）：回滚无意义，停手，改走「换新目标重新切换」路径（手册 02/10）。
- 回滚的读回校验失败：停手，手工核对 CF 记录现值后再动，防止在不一致状态上叠操作。

## 回滚与审计
- 回滚动作本身也是审计行（action=dns_rollback，related_change_id 指向原 cutover）。事后在事故记录（手册 11）中写明：为什么切、为什么回、回滚是否成功、用户受影响时长。
