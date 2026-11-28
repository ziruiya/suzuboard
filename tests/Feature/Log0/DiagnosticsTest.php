<?php

namespace Tests\Feature\Log0;

use App\Models\LogEntry;
use App\Models\LogEntryDnsHistory;
use App\Models\LogEntryEvent;
use App\Models\LogEntryMapping;
use App\Models\LogProbeResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const INGEST = '/api/v2/diagnostics/log0/ingest';
    private const KEY = 'log0-fixture-secret';
    private string $admin;
    private string $counterPath;
    private LogEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-28T04:00:00Z'));
        $this->admin = '/api/v2/' . admin_setting('secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/log0';
        $this->counterPath = sys_get_temp_dir() . '/log0-test-' . bin2hex(random_bytes(8));
        config(['log0.probe_keys.cn-shanghai' => self::KEY, 'log0.counter_path' => $this->counterPath]);
        $this->entry = LogEntry::query()->create($this->entryData());
    }

    protected function tearDown(): void
    {
        if (isset($this->counterPath)) {
            File::deleteDirectory($this->counterPath);
        }
        parent::tearDown();
    }

    public function test_admin_token_and_half_open_mapping_chain(): void
    {
        $path = $this->admin . '/entries/' . $this->entry->getKey() . '/mappings';
        $this->postJson($path, $this->mapping(), $this->probeHeaders())->assertForbidden();
        $this->authenticateAdmin();
        $registered = $this->postJson($this->admin . '/entries', [
            ...$this->entryData(), 'entry_key' => 'direct-hk', 'config_version' => 99,
        ])->assertCreated()->assertJsonPath('config_version', 1)->json('entry_id');
        $this->patchJson($this->admin . "/entries/$registered", ['note' => 'synthetic fixture'])
            ->assertOk()->assertJsonPath('note', 'synthetic fixture');
        $this->patchJson($this->admin . "/entries/$registered", ['note' => 'https://example.test/path'])
            ->assertUnprocessable();

        $first = $this->postJson($path, $this->mapping())->assertCreated()->json();
        $second = $this->postJson($path, [
            ...$this->mapping(),
            'entry_ip' => '2001:db8::1', 'valid_from' => '2026-11-28T02:05:00Z',
            'valid_to' => '2026-11-28T02:20:00Z', 'created_by' => 'forged',
        ])->assertCreated()->assertJsonPath('valid_to', null)->json();
        $this->postJson($path, $this->mapping())->assertUnprocessable();
        $this->assertDatabaseHas('log_entry_mappings', [
            ...$first, 'valid_to' => $second['valid_from'],
        ]);
        $this->assertDatabaseHas('log_entries', ['entry_id' => $this->entry->getKey(), 'config_version' => 2]);
        $this->assertSame(1, LogEntryMapping::query()->whereNull('valid_to')->count());
        $this->assertSame(2, LogEntryMapping::query()->count());
        $this->getJson($this->admin . '/mappings/resolve?' . http_build_query([
            'backend_node_id' => 1, 'at' => '2026-11-28T02:05:00Z',
        ]))->assertOk()->assertJsonPath('candidates.0.mapping_id', $second['mapping_id'])->assertJsonCount(1, 'candidates');
        $this->postJson($this->admin . "/entries/$registered/mappings", $this->mapping())->assertCreated();
        $this->getJson($this->admin . '/mappings/resolve?' . http_build_query([
            'backend_node_id' => 1, 'at' => '2026-11-28T02:05:00Z',
        ]))->assertOk()->assertJsonPath('mapping_confidence', 'AMBIGUOUS')->assertJsonCount(2, 'candidates');
        $this->assertDatabaseCount('v2_admin_audit_log', 0);
    }

    public function test_ingest_auth_allowlist_utc_and_repeat_ack(): void
    {
        $result = [
            ...$this->sample('2026-11-28T02:02:00Z'), 'target_ip' => '2001:0db8:0::1',
            'resolutions' => [
                ['resolver' => 'alidns', 'resolved_ip' => '2001:db8::1'],
                ['resolver' => 'google', 'resolved_ip' => '2001:db8::1'],
                ['resolver' => 'cloudflare', 'resolved_ip' => '2001:db8::1'],
            ],
        ];
        $report = ['result' => $result, 'dropped_total' => 2];
        $this->postJson(self::INGEST, $report)->assertUnauthorized();
        $this->postJson(self::INGEST, $report, [...$this->probeHeaders(), 'X-Log0-Probe' => 'cn-alt'])
            ->assertUnauthorized();
        $this->postJson(self::INGEST, ['dropped_total' => 0, 'result' => [...$result, 'sni' => 'reject.test']],
            $this->probeHeaders())->assertUnprocessable();
        $this->postJson(self::INGEST, ['dropped_total' => 0, 'result' => [...$result, 'target_ip' => null]],
            $this->probeHeaders())->assertUnprocessable();
        $this->postJson(self::INGEST, $report, $this->probeHeaders())->assertOk()->assertExactJson(['ack' => true]);
        $original = LogProbeResult::query()->sole()->getAttributes();
        $this->travel(1)->minutes();
        $equivalent = [...$result, 'rtt_ms' => '100', 'error_class' => null,
            'resolutions' => array_reverse($result['resolutions'])];
        $equivalent['resolutions'][0]['resolved_ip'] = '2001:0db8:0::1';
        $this->postJson(self::INGEST, ['result' => $equivalent, 'dropped_total' => 2], $this->probeHeaders())
            ->assertOk()->assertExactJson(['ack' => true]);
        $this->assertSame($original, LogProbeResult::query()->sole()->getAttributes());
        $this->assertDatabaseCount('log_entry_events', 0);

        $this->postJson(self::INGEST, [
            ...$report,
            'result' => [...$result, 'resolutions' => [['resolved_ip' => '192.0.2.44', 'resolver' => 'alidns']]],
        ], $this->probeHeaders())->assertConflict()->assertExactJson(['ack' => false, 'code' => 'INGEST_CONFLICT']);
        $this->assertSame($original, LogProbeResult::query()->sole()->getAttributes());
        $this->assertDatabaseMissing('log_entry_dns_history', ['resolved_ip' => '192.0.2.44']);
        $this->assertDatabaseHas('log_entry_events', [
            'entry_id' => $this->entry->getKey(), 'error_class' => 'INGEST_CONFLICT', 'status' => 'closed',
            'observed_at' => '2026-11-28 02:02:00', 'computed_at' => '2026-11-28 04:01:00',
        ]);
        $this->postJson(self::INGEST, ['dropped_total' => 3], $this->probeHeaders())->assertOk();
        $this->assertSame(3, Cache::build(['driver' => 'file', 'path' => $this->counterPath])->get('dropped:cn-shanghai'));
        $this->assertDatabaseCount('log_probe_results', 1);
        $this->assertDatabaseHas('log_probe_results', [
            'probe_point' => 'cn-shanghai', 'target_ip' => '2001:db8::1',
            'checked_at' => '2026-11-28 02:02:00', 'received_at' => '2026-11-28 04:00:00',
        ]);

        foreach (['02:07', '01:57'] as $time) {
            $result['checked_at'] = '2026-11-28T' . $time . ':00Z';
            $this->postJson(self::INGEST, ['result' => $result, 'dropped_total' => 3],
                $this->probeHeaders())->assertOk();
        }
        $this->assertDatabaseCount('log_entry_dns_history', 3);
        $this->assertDatabaseHas('log_entry_dns_history', [
            'resolver' => 'alidns', 'ip_family' => 'v6', 'first_seen_at' => '2026-11-28 01:57:00',
            'last_seen_at' => '2026-11-28 02:07:00',
        ]);
    }

    public function test_dns_candidates_are_replay_safe_and_do_not_attribute_cause(): void
    {
        $this->authenticateAdmin();
        $path = $this->admin . '/entries/' . $this->entry->getKey() . '/mappings';
        $this->postJson($path, $this->mapping())->assertCreated();
        $this->postJson(self::INGEST, ['dropped_total' => 0, 'result' => [
            ...$this->sample('2026-11-28T02:02:00Z'), 'target_ip' => '192.0.2.3', 'dns_state' => 'changed',
            'resolutions' => [
                ['resolved_ip' => '192.0.2.3', 'resolver' => 'alidns'],
                ['resolved_ip' => '192.0.2.4', 'resolver' => 'google'],
            ],
        ]], $this->probeHeaders())->assertOk();

        $this->artisan('log0:maintain candidates')->assertSuccessful();
        $this->artisan('log0:maintain candidates')->assertSuccessful();
        $mappings = LogEntryMapping::query()->orderBy('valid_from')->get();
        $this->assertCount(3, $mappings);
        $this->assertNull($mappings[0]->valid_to);
        $this->assertSame($mappings[1]->valid_from, $mappings[1]->valid_to);
        $this->assertSame($mappings[2]->valid_from, $mappings[2]->valid_to);
        $this->getJson($this->admin . '/mappings/resolve?backend_node_id=1&at=2026-11-28T02:10:00Z')
            ->assertOk()->assertJsonCount(1, 'candidates')->assertJsonPath('candidates.0.entry_ip', '192.0.2.1');
        $this->assertSame(['AMBIGUOUS', 'AMBIGUOUS'], $mappings->slice(1)->pluck('mapping_confidence')->all());
        $this->assertDatabaseHas('log_entry_events', ['error_class' => 'DNS_CHANGED', 'attribution_status' => 'UNKNOWN']);
        $this->assertDatabaseCount('log_entry_events', 2);
    }

    public function test_single_dns_candidate_stays_out_of_current_mapping_until_manual_confirmation(): void
    {
        $this->authenticateAdmin();
        $path = $this->admin . '/entries/' . $this->entry->getKey() . '/mappings';
        $this->postJson($path, $this->mapping())->assertCreated();
        $stamp = '2026-11-28T02:02:00Z';
        $this->postJson(self::INGEST, ['dropped_total' => 0, 'result' => [
            ...$this->sample($stamp),
            'resolutions' => [['resolved_ip' => '192.0.2.3', 'resolver' => 'alidns']],
        ]], $this->probeHeaders())->assertOk();

        $this->artisan('log0:maintain candidates')->assertSuccessful();
        $candidate = LogEntryMapping::query()->where('provenance', 'patrol-detection')->sole();
        $this->assertSame('AMBIGUOUS', $candidate->mapping_confidence);
        $lookup = $this->admin . '/mappings/resolve?backend_node_id=1&at=' . $stamp;
        $this->getJson($lookup)->assertOk()->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.entry_ip', '192.0.2.1');
        $this->travel(1)->days();
        $this->artisan('log0:maintain daily')->assertSuccessful();
        $this->assertDatabaseHas('log_entry_daily', ['date_cst' => '2026-11-28', 'distinct_ips_seen' => 1]);

        $this->postJson($path, [...$this->mapping(), 'entry_ip' => '192.0.2.3', 'valid_from' => $stamp])
            ->assertCreated();
        $this->getJson($lookup)->assertOk()->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.entry_ip', '192.0.2.3')
            ->assertJsonPath('mapping_confidence', 'VERIFIED_DIRECT');
    }

    public function test_one_mainland_point_and_one_overseas_failure_remain_observational(): void
    {
        foreach (['03:52', '03:57'] as $time) {
            foreach (['cn-shanghai', 'overseas-hk'] as $point) {
                $this->storeSample("2026-11-28 $time:00", $point, ['handshake_state' => 'fail']);
            }
        }
        $this->artisan('log0:maintain events')->assertSuccessful();
        $this->assertSame('CN_PROBE_FAILURE', LogEntryEvent::query()->sole()->error_class);
        $this->storeSample('2026-11-28 03:58:00', 'cn-shanghai', ['handshake_state' => 'fail']);
        $this->storeSample('2026-11-28 03:58:00', 'cn-alt', ['handshake_state' => 'fail']);
        $this->storeSample('2026-11-28 03:58:00', 'overseas-hk');
        $this->artisan('log0:maintain events')->assertSuccessful();
        $this->assertSame('CN_PROBE_FAILURE', LogEntryEvent::query()->sole()->error_class);
    }

    public function test_historical_upload_revises_episode_without_opening_a_live_event(): void
    {
        config(['log0.probe_keys.cn-alt' => self::KEY, 'log0.probe_keys.overseas-hk' => self::KEY]);
        foreach (['04:52', '04:57'] as $time) {
            foreach (['cn-shanghai', 'cn-alt', 'overseas-hk'] as $point) {
                $this->postJson(self::INGEST, ['dropped_total' => 0, 'result' => [
                    ...$this->sample("2026-11-27T$time:00Z"),
                    'handshake_state' => $point === 'overseas-hk' ? 'ok' : 'fail',
                ]], [...$this->probeHeaders(), 'X-Log0-Probe' => $point])->assertOk();
            }
        }
        $this->artisan('log0:maintain events')->assertSuccessful();
        $event = LogEntryEvent::query()->sole();
        $this->assertTrue($event->historical_backfill);
        $this->assertSame('monitoring', $event->status);
        $this->assertSame('2026-11-27 04:52:00', $event->opened_at);
        $this->assertSame('2026-11-27 04:57:00', $event->observed_at);
        $this->assertSame('2026-11-28 04:00:00', $event->computed_at);
        $this->assertNull($event->closed_at);

        foreach (['cn-shanghai', 'cn-alt', 'overseas-hk'] as $point) {
            foreach (['04:48' => 'fail', '04:58' => 'ok'] as $time => $state) {
                $this->postJson(self::INGEST, ['dropped_total' => 0, 'result' => [
                    ...$this->sample("2026-11-27T$time:00Z"),
                    'handshake_state' => $point === 'overseas-hk' ? 'ok' : $state,
                ]], [...$this->probeHeaders(), 'X-Log0-Probe' => $point])->assertOk();
            }
        }
        $this->travel(1)->minutes();
        $this->artisan('log0:maintain events')->assertSuccessful();
        $this->assertDatabaseCount('log_entry_events', 1);
        $event->refresh();
        $this->assertTrue($event->historical_backfill);
        $this->assertSame('closed', $event->status);
        $this->assertSame('2026-11-27 04:48:00', $event->opened_at);
        $this->assertSame('2026-11-27 04:58:00', $event->closed_at);
        $this->assertSame('2026-11-27 04:58:00', $event->observed_at);
        $this->assertSame('2026-11-28 04:01:00', $event->computed_at);
    }

    public function test_event_three_points_and_single_point_absence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-28T03:17:00Z'));
        $cn = 'cn-shanghai';
        $hk = 'overseas-hk';
        $this->storeSample('2026-11-27 03:07:00', $cn);
        $this->storeSample('2026-11-27 03:07:00', $hk);
        $this->storeSample('2026-11-28 03:03:00', $cn);
        $this->storeSample('2026-11-28 03:03:00', $hk);
        $this->storeSample('2026-11-28 03:07:00', $cn, ['rtt_ms' => 301]);
        $this->storeSample('2026-11-28 03:07:00', $hk);
        foreach (['08', '09', '11', '12', '13', '14', '15', '16'] as $minute) {
            $this->storeSample("2026-11-28 03:$minute:00", $cn, ['handshake_state' => 'fail']);
        }
        $this->storeSample('2026-11-28 03:11:00', $hk);
        $this->artisan('log0:maintain events')->assertSuccessful();
        $incident = LogEntryEvent::query()->sole();
        $this->assertNull($incident->first_failed_at);
        $this->storeSample('2026-11-28 03:16:00', $hk);
        $this->storeSample('2026-11-28 03:11:00', 'cn-alt', ['handshake_state' => 'fail']);
        $this->storeSample('2026-11-28 03:16:00', 'cn-alt', ['handshake_state' => 'fail']);
        $this->storeSample('2026-11-28 03:17:00', $hk);
        $this->artisan('log0:maintain events')->assertSuccessful();
        $incident->refresh();
        $this->assertSame('2026-11-28 03:03:00', $incident->last_success_at);
        $this->assertSame('2026-11-28 03:07:00', $incident->first_degraded_at);
        $this->assertSame('2026-11-28 03:11:00', $incident->first_failed_at);
        $this->assertSame('CN_DIRECTION_FAILURE', $incident->error_class);
        $this->assertSame('UNKNOWN', $incident->attribution_status);
        $this->assertSame('open', $incident->status);
        $this->assertFalse($incident->historical_backfill);

        $this->authenticateAdmin();
        $path = $this->admin . '/events/' . $incident->getKey();
        $this->patchJson($path, ['attribution_status' => 'AUTO_BLOCKED'])->assertUnprocessable();
        $this->patchJson($path, [
            'provider_notice' => 'Provider support, 2026-11-28 03:00 UTC',
            'hypothesis' => 'Awaiting provider confirmation', 'confidence' => 'low',
            'attribution_status' => 'INSUFFICIENT_EVIDENCE', 'status' => 'monitoring',
        ])->assertOk();
        $this->artisan('log0:maintain events')->assertSuccessful();
        $this->assertSame('monitoring', $incident->refresh()->status);
        $this->assertSame('INSUFFICIENT_EVIDENCE', $incident->attribution_status);
    }

    public function test_daily_cst_cutoff_retention_and_red_line_columns(): void
    {
        $this->storeSample('2026-11-27 15:59:00', 'cn-shanghai');
        $this->storeSample('2026-11-27 16:00:00', 'cn-shanghai');
        $this->storeSample('2026-11-27 16:00:00', 'overseas-hk', ['loss_pct' => 20]);
        $this->storeSample('2026-11-14 03:59:59', 'cn-shanghai');
        $this->storeSample('2026-11-14 04:00:00', 'cn-shanghai');
        LogEntryDnsHistory::query()->insert([
            'entry_id' => $this->entry->getKey(), 'resolved_ip' => '192.0.2.1', 'ip_family' => 'v4',
            'resolver' => 'alidns', 'source' => 'probe', 'first_seen_at' => '2026-05-01 00:00:00',
            'last_seen_at' => '2026-05-01 00:00:00',
        ]);

        $this->artisan('log0:maintain daily')->assertSuccessful();
        $this->assertDatabaseHas('log_entry_daily', [
            'date_cst' => '2026-11-27', 'probe_total' => 1, 'distinct_ips_seen' => 1,
        ]);
        $this->assertDatabaseMissing('log_entry_daily', ['date_cst' => '2026-11-28']);
        $this->travel(1)->days();
        $this->artisan('log0:maintain daily')->assertSuccessful();
        $this->assertDatabaseHas('log_entry_daily', [
            'date_cst' => '2026-11-28', 'probe_total' => 2, 'probe_ok' => 2, 'degraded_minutes' => 1,
        ]);
        $this->travel(-1)->days();
        $this->artisan('log0:maintain retention')->assertSuccessful();
        $this->assertDatabaseMissing('log_probe_results', ['checked_at' => '2026-11-14 03:59:59']);
        $this->assertDatabaseHas('log_probe_results', ['checked_at' => '2026-11-14 04:00:00']);
        $this->assertDatabaseCount('log_entry_dns_history', 0);

        $tables = ['log_entries', 'log_entry_dns_history', 'log_entry_mappings',
            'log_probe_results', 'log_entry_events', 'log_entry_daily'];
        $this->assertEqualsCanonicalizing($tables, array_values(array_filter(array_column(Schema::getTables(), 'name'),
            fn (string $table) => str_starts_with($table, 'log_'))));
        foreach ($tables as $table) {
            $this->assertSame([], array_values(array_intersect(Schema::getColumnListing($table), [
                'source_ip', 'src_ip', 'user_id', 'sni', 'url', 'payload', 'dns_query', 'target_domain', 'target_port',
            ])));
        }
    }

    private function authenticateAdmin(): void
    {
        $user = User::query()->create([
            'email' => 'log0-admin@example.test', 'password' => bcrypt('fixture'), 'is_admin' => 1,
            'uuid' => '11111111-2222-4333-8444-555555555555', 'token' => 'log0-user-token',
        ]);
        $this->withToken($user->createToken('log0-test')->plainTextToken);
    }

    private function entryData(): array
    {
        return ['provider' => 'fixture', 'entry_key' => 'fixture-entry', 'kind' => 'direct'];
    }

    private function mapping(): array
    {
        return [
            'entry_ip' => '192.0.2.1', 'ingress_port' => 443, 'backend_node_id' => 1,
            'backend_port' => 443, 'valid_from' => '2026-11-28T02:00:00Z', 'provenance' => 'manual_check',
            'mapping_confidence' => 'VERIFIED_DIRECT',
        ];
    }

    private function probeHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . self::KEY, 'X-Log0-Probe' => 'cn-shanghai'];
    }

    private function sample(string $checkedAt): array
    {
        return [
            'entry_id' => $this->entry->getKey(), 'checked_at' => $checkedAt, 'target_ip' => '192.0.2.1',
            'dns_state' => 'ok', 'tcp_state' => 'ok', 'handshake_state' => 'ok', 'rtt_ms' => 100,
        ];
    }

    private function storeSample(string $checkedAt, string $point, array $overrides = []): void
    {
        LogProbeResult::query()->insert([
            ...$this->sample($checkedAt), 'probe_point' => $point, 'received_at' => $checkedAt, ...$overrides,
        ]);
    }
}
