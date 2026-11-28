# LOG-0 self-check

Baseline `77cc4ce6f2e3`. All implementation changes are in the working tree. The branch and index were left in their original state.

## Changed files

| Path | Purpose |
| --- | --- |
| `database/migrations/2026_11_28_000001_create_log0_diagnostic_tables.php` | Adds and rolls back the six diagnostic tables |
| `app/Models/LogEntry.php` | Entry registry and UTC model timestamps |
| `app/Models/LogEntryDnsHistory.php` | DNS observations keyed by entry, IP and resolver |
| `app/Models/LogEntryMapping.php` | Append-only mapping history |
| `app/Models/LogProbeResult.php` | Diagnostic probe samples and their receipt times |
| `app/Models/LogEntryEvent.php` | Incident timeline and attribution enum |
| `app/Models/LogEntryDaily.php` | Entry summaries keyed by CST date |
| `app/Http/Routes/V2/Log0Route.php` | Probe ingest and authenticated v2 admin routes |
| `app/Http/Controllers/V2/Diagnostics/Log0IngestController.php` | Point-scoped authentication, allowlisted reports and replay-safe ACKs |
| `app/Http/Controllers/V2/Admin/Log0Controller.php` | Entry, mapping and event writes and historical mapping lookup |
| `config/log0.php` | Point keys and dedicated drop-counter storage path |
| `app/Services/Log0/Mappings.php` | Mapping append transaction, resolution and DNS candidates |
| `app/Services/Log0/Rounds.php` | Multi-point round evaluation and seven-day same-slot RTT baseline |
| `app/Services/Log0/Rollups.php` | Incident timeline, CST daily summaries and retention |
| `app/Console/Commands/Log0Maintain.php` | Four maintenance tasks |
| `app/Console/Kernel.php` | Schedules the four tasks |
| `tools/log0-probe/probe.py` | Regional probe, adaptive cadence and bounded retry spool |
| `tools/log0-probe/README.md` | Probe configuration and panel API |
| `tests/Feature/Log0/DiagnosticsTest.php` | LOG-0 PHPUnit coverage |
| `LOG0-SELFCHECK.md` | This handoff |

## Test commands and results

PHPUnit used the repository test configuration and the root SQLite file named `:memory:`. Runs were serial. PHP 8.2.36 ran in a container with no external network. The final run shared a loopback-only namespace with a disposable Redis instance for the existing suite. No production configuration or live notification channel was used.

| Command | Result |
| --- | --- |
| `php vendor/bin/phpunit tests/Feature/Log0/DiagnosticsTest.php` | Passed on PHP 8.2.36. Five tests, 77 assertions |
| `php vendor/bin/phpunit` | 504 tests, 2,767 assertions, two failures in `TrafficBackfillTest`, five skips |
| `php vendor/bin/phpunit` in a clean archive of `77cc4ce6f2e3` | 499 tests, 2,690 assertions, the same two failures and five skips |
| `bash /tmp/log0-mysql-smoke.sh` | Passed on disposable MySQL 5.7.44. Verified schema up/down, sample replay, DNS first/last seen, mapping closure, single-point suppression, daily upsert and retention |
| `PYTHONDONTWRITEBYTECODE=1 python3 /tmp/log0-probe-smoke.py` | Passed on local fixtures. Verified successful AnyTLS, Trojan and VLESS transit, invalid credentials, 503 buffering, expiry, overflow, replay, drop acknowledgements, resolver identities and adaptive cadence |
| `php artisan schedule:list` | All four LOG-0 tasks registered at the intended intervals |
| `php -l <each changed PHP file>` and `git diff --check` | Passed |

The two full-suite failures reproduced at baseline in `test_force_clears_obsolete_due_time_when_system_reset_is_disabled` and `test_scheduled_reset_clears_obsolete_due_time_and_preserves_usage`. Both passed when filtered and run alone at baseline. An initial full run without Redis produced connection-refused failures in the existing suite. The table reports the completed runs with isolated Redis.

## Implementation decisions and deviations

- The retry queue lives at the probe point. The panel stores each accepted report and its DNS observations in one diagnostic transaction and sends the ACK after persistence. The raw probe table is the rollup input. The ingest accepts timestamps within the 24-hour retry horizon with a five-minute clock-skew allowance. The panel uses a dedicated file store for drop totals. There is no Laravel queue worker to provision for LOG-0.
- A DNS rotation generates a mapping candidate and an event with `error_class=DNS_CHANGED`. Attribution remains `UNKNOWN` until an administrator writes it, as required by §1.4. Entries need a known backend mapping before the candidate task can fill the required backend fields. Concurrent new IPs are recorded as ambiguous, with separate effective seconds so the mapping chain has no overlapping intervals.
- The timeline uses UTC minute buckets with at least two reporting points. A mainland failure requires the same reporting mainland point to fail in two consecutive eligible rounds within five minutes, with no successful mainland observation in either round. The last-success marker is relative to the incident onset. The RTT baseline is the mean of healthy checks in the same five-minute slot on the preceding seven UTC dates. Daily degraded and failed minute counts use observed minute buckets.
- Automatic incident identifiers are generated locally. The external `health-state.md` numbering source, fleet-sweep launcher and new-entry watch workflow are outside this working tree. The probe supports a shared patrol lock, and the mapping rows expose the effective times for the downstream watch workflow.
- Verification used synthetic local endpoints. Regional deployment, confirmation of Shanghai patrol scheduling and resilience tests against live subscription and order flows remain for deployment acceptance.
