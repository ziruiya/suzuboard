<?php

namespace App\Services\Log0;

use App\Models\LogEntry;
use App\Models\LogEntryDnsHistory;
use App\Models\LogEntryEvent;
use App\Models\LogEntryMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Mappings
{
    public function append(int $entryId, array $mapping): LogEntryMapping
    {
        return DB::transaction(function () use ($entryId, $mapping) {
            $entry = LogEntry::query()->lockForUpdate()->findOrFail($entryId);

            return $this->appendLocked($entry, $this->current($entry), $mapping);
        }, 3);
    }

    public function resolveNode(int $nodeId, CarbonImmutable $at): array
    {
        $at = $at->utc()->toDateTimeString();
        $candidates = LogEntryMapping::query()->confirmed()->where('backend_node_id', $nodeId)
            ->where('valid_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $at))
            ->orderBy('entry_id')->get();

        return [
            'mapping_confidence' => $candidates->count() > 1 ? 'AMBIGUOUS' : $candidates->first()?->mapping_confidence,
            'candidates' => $candidates,
        ];
    }

    public function candidates(): void
    {
        LogEntry::query()->eachById(function (LogEntry $entry) {
            DB::transaction(function () use ($entry) {
                $entry = LogEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
                $current = $this->current($entry);
                if (!$current) {
                    return;
                }

                $now = now('UTC')->format('Y-m-d H:i:s');
                $newIps = LogEntryDnsHistory::query()->where('entry_id', $entry->getKey())
                    ->where('first_seen_at', '>=', $current->valid_from)
                    ->where('first_seen_at', '<=', $now)
                    ->whereNotIn('resolved_ip', LogEntryMapping::query()->select('entry_ip')
                        ->where('entry_id', $entry->getKey()))
                    ->selectRaw('resolved_ip, MIN(first_seen_at) AS discovered_at')
                    ->groupBy('resolved_ip')->orderBy('discovered_at')->orderBy('resolved_ip')->get();

                $candidateTime = LogEntryMapping::query()->where('entry_id', $entry->getKey())
                    ->where('provenance', 'patrol-detection')->max('valid_from');
                foreach ($newIps as $discovery) {
                    $validFrom = $candidateTime === null ? $discovery->discovered_at : max(
                        $discovery->discovered_at,
                        CarbonImmutable::parse($candidateTime, 'UTC')->addSecond()->toDateTimeString()
                    );
                    if ($validFrom > $now) {
                        break;
                    }

                    // An observation has an empty effective interval and cannot close a confirmed mapping.
                    $candidate = new LogEntryMapping();
                    $candidate->forceFill([
                        ...$current->only(['ingress_port', 'relay_rule_id', 'backend_node_id', 'backend_port']),
                        'entry_id' => $entry->getKey(),
                        'entry_ip' => $discovery->resolved_ip,
                        'valid_from' => $validFrom,
                        'valid_to' => $validFrom,
                        'provenance' => 'patrol-detection',
                        'mapping_confidence' => 'AMBIGUOUS',
                        'created_by' => 'patrol-detection',
                        'created_at' => $now,
                    ])->save();
                    $candidateTime = $validFrom;
                    LogEntryEvent::query()->insert([
                        'incident_id' => 'INC-DNS-' . $candidate->getKey(),
                        'entry_id' => $entry->getKey(),
                        'opened_at' => $discovery->discovered_at,
                        'closed_at' => $discovery->discovered_at,
                        'error_class' => 'DNS_CHANGED',
                        'status' => 'closed',
                    ]);
                }
            }, 3);
        }, 100, 'entry_id');
    }

    private function current(LogEntry $entry): ?LogEntryMapping
    {
        return LogEntryMapping::query()->confirmed()->where('entry_id', $entry->getKey())->whereNull('valid_to')->first();
    }

    private function appendLocked(LogEntry $entry, ?LogEntryMapping $current, array $mapping): LogEntryMapping
    {
        if ($current && $mapping['valid_from'] <= $current->valid_from) {
            throw ValidationException::withMessages(['valid_from' => 'Must follow the current mapping.']);
        }

        if ($current) {
            LogEntryMapping::query()->whereKey($current->getKey())->whereNull('valid_to')
                ->update(['valid_to' => $mapping['valid_from']]);
        }

        $next = new LogEntryMapping();
        $next->forceFill([
            ...$mapping,
            'entry_id' => $entry->getKey(),
            'valid_to' => null,
            'created_at' => now('UTC')->format('Y-m-d H:i:s'),
        ])->save();
        if ($current) {
            $entry->increment('config_version', 1, ['updated_at' => now('UTC')->format('Y-m-d H:i:s')]);
        }

        return $next;
    }
}
