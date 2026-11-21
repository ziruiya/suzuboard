# 02 · DNS 切换到新落地机（DNS Cutover）

## 症状
- 落地机 IP 被封或机器退役，需要把某个冻结域名（suzu.club / zexqoravi.com 上的节点入口名）指向新落地 IP，而客户端订阅里的域名、端口、协议参数一个字都不能变。

## 适用契约
- `entry_mode='dns'` 的绑定（A 组直连、B/C 组各条目、台湾 IPv6 条目除外，见手册 06）。
- 入口记录 TTL 为 120s（suzu.club 节点条目约定）；切换后至少等 2×TTL 再下结论。

## 只读诊断
1. 确认契约与绑定：管理 API 查 contract/binding，记下 `dns_zone_id`、`dns_record_id`、`current_entry_ip`、当前 `revision`。
2. 确认新机已按手册 10 接入并在原 `server_port` 上提供与契约完全一致的服务（同协议、同 SNI 证书、同 gRPC 服务名/WS 路径、同 Reality 密钥对）。
3. 用 Cloudflare API 读一遍记录现值，与 binding 的 `current_entry_ip` 对照；不一致先查清（有人手工改过 DNS？），不要带着不一致硬切。

## 修复动作
1. 预检：`POST /{secure_path}/suzu/endpoint/preflight`（contract_id、target_ip=新 IP、confirm=true）。必须 overall=`passed`；`degraded`（如无 sing-box 引擎时 Reality 无法验真）不许切换。
2. 切换：`POST .../suzu/endpoint/cutover`（contract_id、new_ip、expected_revision=当前 revision、confirm=true）。服务会：读旧值存审计 → 按 record ID 改 A 记录 → 读回校验 → 更新 binding 并 revision+1。
3. 等 120s×2 后做用户侧验证。

## 发布前检查
- 预检 passed 且 target_ip 与本次切换目标一致（API 会强制校验，不一致直接 422）。
- 新机证书覆盖契约 SNI（B/C 组域名证书由 agent DNS-01 签发，DNS 未生效前不要 toggle 节点，证书签不下来）。
- 备份在位：面板 compose 与镜像 tag 已备份到 `/root/muse-backups-<日期>-<主题>/`（若本次同时动面板）。

## 用户侧验证
- 上海机 sing-box 用旧订阅原参数：generate_204 ×2、出口 IP = 新 IP。
- 公共 DoH 查询入口域名，确认 A 记录已是新 IP（注意本地缓存，至少两个解析源一致）。

## 停手条件
- 预检 failed/degraded/error 任一：停手，先按手册 04 判读，不要绕过预检手工改 DNS。
- cutover 返回 revision conflict：有人并发操作，停手 reload 现状再评估。
- 读回校验失败（服务会抛错并把审计行标 failed）：停手，手工核对 CF 记录现值，按手册 05 处理。

## 回滚与审计
- 一键回滚：`POST .../suzu/endpoint/rollback`（change_id=本次 cutover 的审计行 id、expected_revision、confirm=true），恢复切换前存下的旧值并读回校验。
- 每次切换/回滚都在 `suzu_endpoint_changes` 留痕（old/new binding JSON、预检结果、读回结果）。手工在 CF 控制台改记录视为违规操作——会造成 binding 与现实脱节，下次切换先被只读诊断第 3 步拦下。
