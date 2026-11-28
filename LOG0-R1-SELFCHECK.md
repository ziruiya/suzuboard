# LOG-0 R01–R04 返工自查

工作树基线为 `d16c54cdb05c`。六张诊断表、保留期、探针常态间隔和账本隔离设计沿用初版。

## 改动和覆盖

| 项目 | 文件 | 修法与验证 |
| --- | --- | --- |
| R01 | `app/Services/Log0/Mappings.php`、`app/Models/LogEntryMapping.php`、`app/Services/Log0/Rollups.php`、增量迁移 | 自动候选统一为 `AMBIGUOUS`，使用空有效区间；节点映射解析、当前映射查询和日汇总按来源过滤。迁移修复旧候选截断的人工区间。测试覆盖单候选、多候选、日汇总和同一秒的人工确权。 |
| R02 | `app/Services/Log0/Rollups.php`、`app/Services/Log0/Rounds.php`、`app/Models/LogEntryEvent.php`、`app/Http/Controllers/V2/Admin/Log0Controller.php`、增量迁移 | `opened_at` 保存观测起点，`observed_at` 保存最新事件证据的采样时间，`computed_at` 保存计算时间。首次落库时，证据超过两个常态探针间隔（10 分钟）的事件标记为 `historical_backfill`，待恢复状态使用 `monitoring`，收到恢复证据后转为 `closed`。补传测试覆盖回溯起点和恢复。 |
| R03 | `app/Http/Controllers/V2/Diagnostics/Log0IngestController.php`、`tools/log0-probe/probe.py`、`app/Services/Log0/Rollups.php`、增量迁移 | 对含规范化 DNS 集合的采样计算 SHA-256，同键同摘要保留首达记录并 ACK，同键异摘要保留独立的 `INGEST_CONFLICT` 事件并返回 409。探针记录冲突并计入丢弃，随后独立上报计数。测试覆盖 DNS 排序、IPv6 表示等价、DNS 内容冲突和首达记录不变。 |
| R04 | `app/Services/Log0/Rounds.php` | 大陆方向确认要求至少两个不同大陆点各自在前一合格轮次连续失败，且当前轮有境外成功对照。测试覆盖大陆和境外各一点失败、第二大陆点仅出现一轮、两个大陆点连续失败，以及单点缺报。 |

PHP 回归用例集中在 `tests/Feature/Log0/DiagnosticsTest.php`。新增迁移为 `database/migrations/2026_11_28_000002_isolate_log0_diagnostic_records.php`。

已有采样只保留聚合 DNS 历史，缺少可验证的逐报告 DNS 集合。升级后遇到这类样本的同键重放，返回 409 并留痕。丢弃累计值按探针级指标独立更新。历史补传标记按首次落库的证据确定，后续回放更新观测和计算时间以及事件起止。确权与自动候选可保留同一秒的记录。如需回滚迁移到旧唯一键，须先处理这类同一秒记录。

## 串行测试

沿用 `phpunit.xml`。PHP 8.2.36 容器仅共享 disposable Redis 的回环网络，测试使用 SQLite、数组邮件和合成数据。未加载生产配置或接触真实通知通道。根目录的 `:memory:` 由测试使用，未手工处理。

| 命令 | 结果 |
| --- | --- |
| 改动前 `vendor/bin/phpunit` | 504 个测试，2,767 条断言，2 项失败，5 项跳过。日志 `/tmp/log0-r1-before.log` |
| 改动后 `vendor/bin/phpunit --filter DiagnosticsTest` | 全过。8 个测试，135 条断言。日志 `/tmp/log0-r1-targeted.log` |
| 改动后 `vendor/bin/phpunit` | 507 个测试，2,825 条断言，2 项失败，5 项跳过。日志 `/tmp/log0-r1-after.log` |
| 探针本地 409 模拟 | 通过。验证标准错误输出、队列剔除、丢弃计数递增及单独计数 ACK |
| 改动 PHP 文件 `php -l`，以及 `git diff --check` | 通过 |
| `bash /tmp/log0-r1-mysql.sh` | MySQL 5.7.44 的本地隔离冒烟通过。覆盖增量迁移、旧候选隔离及人工有效期修复、同内容重放、日汇总和无同秒碰撞时的回滚 |

## 全量失败集对账

以初版验收使用的生产基线 `77cc4ce6f2e3`（`LOG0-SELFCHECK.md`，原始日志 `/tmp/log0-baseline-full.log`）为参照，对照改动前后的串行运行。按测试全名与失败断言逐条对账，归一化每次运行生成的 Unix 时间戳后，两项均一致。新增、消失及断言变化各为零，LOG-0 相关失败为零。

| 基线失败 | 对账结果 |
| --- | --- |
| `Tests\Feature\Batch3\TrafficBackfillTest::test_force_clears_obsolete_due_time_when_system_reset_is_disabled` | 三次运行均断言 `next_reset_at` 应为 null，实际仍为时间戳。既有失败，与本轮返工无关。 |
| `Tests\Feature\Batch3\TrafficBackfillTest::test_scheduled_reset_clears_obsolete_due_time_and_preserves_usage` | 三次运行均观察到 `next_reset_at` 仍为时间戳，`u/d` 为 0/0，预期为 null 和 23/45。既有失败，与本轮返工无关。 |

改动留在工作树，未提交或推送。
