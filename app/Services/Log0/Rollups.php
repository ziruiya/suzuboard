<?php

namespace App\Services\Log0;

use App\Models\LogEntry;
use App\Models\LogEntryDaily;
use App\Models\LogEntryDnsHistory;
use App\Models\LogEntryEvent;
use App\Models\LogEntryMapping;
use App\Models\LogProbeResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Rollups
{
    // Allow two normal five-minute probe intervals so a delayed round can still
    // confirm a current episode without treating the 24-hour replay window as live.
    private const FRESHNESS_MINUTES = 10;

    public function __construct(private Rounds $rounds)
    {
    }

    public function events(): void
    {
        // Revisit the upload buffer's full horizon, including replayed reports.
        $computedAt = CarbonImmutable::now('UTC');
        $until = $computedAt->startOfMinute();
        $from = $until->subDay()->subMinutes(10);
        LogEntry::query()->eachById(function (LogEntry $entry) use ($from, $until, $computedAt) {
            $entryId = $entry->getKey();
            $episodes = [];
            $episode = null;
            $successes = [];
            $recoveries = [];
            foreach ($this->rounds->between($entryId, $from, $until) as $round) {
                // Recovery uses mainland success and therefore does not close
                // an event just because the mainland probe went offline.
                if ($round['recovered']) {
                    $recoveries[] = $round['success'];
                }
                if ($round['recovered'] && $episode !== null) {
                    $episode['closed_at'] = $round['success'];
                    $episode['observed_at'] = max($episode['observed_at'], $round['success']);
                    $episodes[] = $episode;
                    $episode = null;
                }
                $onset = $this->earliest($round['failed'], $round['degraded']);
                if ($onset !== null && $episode === null) {
                    $lastSuccess = null;
                    foreach (array_reverse($successes) as $success) {
                        if (substr($success, 0, 16) < substr($onset, 0, 16)) {
                            $lastSuccess = $success;
                            break;
                        }
                    }
                    $episode = [
                        'opened_at' => $onset,
                        'closed_at' => null,
                        'last_success_at' => $lastSuccess,
                        'first_degraded_at' => null,
                        'first_failed_at' => null,
                        'error_class' => $round['error_class'],
                        'observed_at' => $round['observed_at'],
                    ];
                }
                if ($onset !== null) {
                    $episode['observed_at'] = max($episode['observed_at'], $round['observed_at']);
                    $episode['opened_at'] = min($episode['opened_at'], $onset);
                    $episode['first_degraded_at'] = $this->earliest($episode['first_degraded_at'], $round['degraded']);
                    $episode['first_failed_at'] = $this->earliest($episode['first_failed_at'], $round['failed']);
                    if ($round['failed'] !== null &&
                        ($round['error_class'] === 'CN_DIRECTION_FAILURE' || $episode['error_class'] === 'DEGRADED')) {
                        $episode['error_class'] = $round['error_class'];
                    }
                }
                if ($round['success'] !== null) {
                    $successes[] = $round['success'];
                    // Only successes in the last six buckets can be later than
                    // the start of a newly confirmed failure.
                    if (count($successes) > 7) {
                        array_shift($successes);
                    }
                }
            }
            if ($episode !== null) {
                $episodes[] = $episode;
            }
            $this->persistEpisodes($entryId, $episodes, $recoveries, $from, $computedAt);
        }, 100, 'entry_id');
    }

    private function persistEpisodes(int $entryId, array $episodes, array $recoveries, CarbonImmutable $from,
        CarbonImmutable $computedAt): void
    {
        $freshSince = $computedAt->subMinutes(self::FRESHNESS_MINUTES)->toDateTimeString();
        $computed = $computedAt->toDateTimeString();
        DB::transaction(function () use ($entryId, $episodes, $recoveries, $from, $freshSince, $computed) {
            $events = LogEntryEvent::query()->where('entry_id', $entryId)
                ->whereNotIn('error_class', ['DNS_CHANGED', 'INGEST_CONFLICT'])
                ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $from->toDateTimeString()))
                ->orderBy('opened_at')->lockForUpdate()->get();

            foreach ($episodes as $episode) {
                // Close episodes that began before the replay window if a
                // recovery precedes this new onset.
                foreach ($events->filter(fn (LogEntryEvent $event) => $event->closed_at === null &&
                    ($event->status === 'open' || $event->historical_backfill)) as $event) {
                    foreach ($recoveries as $recovery) {
                        if ($recovery > $event->opened_at && $recovery < $episode['opened_at']) {
                            $this->closeOnRecovery($event, $recovery);
                            $event->computed_at = $computed;
                            $event->save();
                            break;
                        }
                    }
                }
                $existing = $events->first(fn (LogEntryEvent $event) =>
                    ($event->closed_at === null || $event->closed_at >= $episode['opened_at']) &&
                    ($episode['closed_at'] === null || $episode['closed_at'] >= $event->opened_at));
                $historical = $episode['observed_at'] < $freshSince;
                if (!$existing) {
                    $id = 'INC-L0-' . substr(hash('sha256', $entryId . ':' . $episode['opened_at']), 0, 24);
                    LogEntryEvent::query()->insert([
                        ...$episode,
                        'entry_id' => $entryId,
                        'incident_id' => $id,
                        'computed_at' => $computed,
                        'historical_backfill' => $historical,
                        'status' => $episode['closed_at'] !== null ? 'closed' : ($historical ? 'monitoring' : 'open'),
                    ]);
                    continue;
                }

                $events = $events->reject(fn (LogEntryEvent $event) => $event->is($existing));
                if ($episode['opened_at'] < $existing->opened_at &&
                    $episode['last_success_at'] !== null) {
                    $existing->last_success_at = $episode['last_success_at'];
                } elseif ($episode['last_success_at'] !== null && $episode['last_success_at'] < $existing->opened_at) {
                    $existing->last_success_at = max($existing->last_success_at, $episode['last_success_at']);
                }
                $existing->opened_at = min($existing->opened_at, $episode['opened_at']);
                foreach (['first_degraded_at', 'first_failed_at'] as $field) {
                    if ($episode[$field] !== null && ($existing->$field === null || $episode[$field] < $existing->$field)) {
                        $existing->$field = $episode[$field];
                    }
                }
                if ($episode['error_class'] === 'CN_DIRECTION_FAILURE' || $existing->error_class === 'DEGRADED') {
                    $existing->error_class = $episode['error_class'];
                }
                $existing->observed_at = max($existing->observed_at, $episode['observed_at']);
                $existing->computed_at = $computed;
                $this->closeOnRecovery($existing, $episode['closed_at']);
                $existing->save();
            }

            foreach ($events->filter(fn (LogEntryEvent $event) => $event->closed_at === null &&
                    ($event->status === 'open' || $event->historical_backfill)) as $event) {
                foreach ($recoveries as $recovery) {
                    if ($recovery > $event->opened_at) {
                        $this->closeOnRecovery($event, $recovery);
                        $event->computed_at = $computed;
                        $event->save();
                        break;
                    }
                }
            }
        }, 3);
    }

    private function earliest(?string $left, ?string $right): ?string
    {
        return $left === null || $right === null ? ($left ?? $right) : min($left, $right);
    }

    private function closeOnRecovery(LogEntryEvent $event, ?string $recovery): void
    {
        if ($recovery === null) {
            return;
        }
        if ($event->status === 'open' || ($event->historical_backfill &&
            ($event->closed_at === null || $recovery < $event->closed_at))) {
            $event->status = 'closed';
            $event->closed_at = $recovery;
            $event->observed_at = max($event->observed_at, $recovery);
        }
    }

    public function daily(): void
    {
        $today = CarbonImmutable::now('Asia/Shanghai')->startOfDay();
        // Rebuild yesterday and the two previous CST days for buffered uploads.
        $from = $today->subDays(3)->utc();
        $until = $today->utc();
        LogEntry::query()->eachById(function (LogEntry $entry) use ($from, $until) {
            $entryId = $entry->getKey();
            $days = [];
            $failures = [];
            foreach ($this->rounds->between($entryId, $from, $until) as $round) {
                if ($round['minute'] < $from->toDateTimeString()) {
                    continue;
                }
                $local = CarbonImmutable::parse($round['minute'], 'UTC')->setTimezone('Asia/Shanghai');
                $date = $local->toDateString();
                $days[$date][] = $round;
                if ($round['failed'] !== null) {
                    $first = CarbonImmutable::parse($round['failed'], 'UTC')->setTimezone('Asia/Shanghai');
                    $failures[$first->toDateString()][$first->format('H:i')] = true;
                    $failures[$date][$local->format('H:i')] = true;
                }
            }
            for ($start = $from; $start->lt($until); $start = $start->addDay()) {
                $date = $start->setTimezone('Asia/Shanghai')->toDateString();
                $this->writeDay($entryId, $date, $start, $days[$date] ?? [], count($failures[$date] ?? []));
            }
        }, 100, 'entry_id');
    }

    private function writeDay(int $entryId, string $date, CarbonImmutable $start, array $rounds, int $failed): void
    {
        $end = $start->addDay()->toDateTimeString();
        $start = $start->toDateTimeString();
        $ips = LogEntryMapping::query()->confirmed()->where('entry_id', $entryId)->where('valid_from', '<', $end)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $start))
            ->pluck('entry_ip')->flip()->all();
        $events = LogEntryEvent::query()->where('entry_id', $entryId)->where('opened_at', '<', $end)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $start))
            ->orderBy('opened_at')->orderBy('incident_id')->pluck('incident_id');

        $total = $ok = $degraded = 0;
        foreach ($rounds as $round) {
            $degraded += (int) ($round['degraded'] !== null);
            foreach ($round['samples'] as $sample) {
                $total++;
                $ok += (int) ($sample->tcp_state === 'ok' && $sample->handshake_state === 'ok');
                $ips[$sample->target_ip] = true;
            }
        }
        $eventIds = '';
        foreach ($events as $id) {
            $next = $eventIds === '' ? $id : $eventIds . ',' . $id;
            if (strlen($next) > 255) {
                break;
            }
            $eventIds = $next;
        }

        LogEntryDaily::query()->upsert([[
            'entry_id' => $entryId,
            'date_cst' => $date,
            'probe_total' => $total,
            'probe_ok' => $ok,
            'degraded_minutes' => $degraded,
            'failed_minutes' => $failed,
            'distinct_ips_seen' => count($ips),
            'event_ids' => $eventIds === '' ? null : $eventIds,
        ]], ['entry_id', 'date_cst']);
    }

    public function retention(): void
    {
        $utc = CarbonImmutable::now('UTC');
        $cstDate = $utc->setTimezone('Asia/Shanghai')->subDays(90)->toDateString();
        foreach ([
            LogEntryDnsHistory::query()->where('last_seen_at', '<', $utc->subDays(180)->toDateTimeString()),
            LogProbeResult::query()->where('checked_at', '<', $utc->subDays(14)->toDateTimeString()),
            LogEntryDaily::query()->where('date_cst', '<', $cstDate),
        ] as $expired) {
            do {
                $deleted = (clone $expired)->limit(1000)->delete();
            } while ($deleted === 1000);
        }
    }
}
