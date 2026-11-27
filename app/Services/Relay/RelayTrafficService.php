<?php

namespace App\Services\Relay;

use App\Models\RelayRule;
use App\Models\RelayRuleStat;
use App\Models\RelayTrafficBatch;
use App\Models\ServerMachine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * SB-RELAY-01 traffic accounting. The agent reports immutable batches
 * (machine, rule, boot, seq); a batch is booked only when its ledger insert
 * succeeds against the UNIQUE keys, so replaying a batch — even ten times —
 * counts exactly once. Booked bytes are split across UTC day boundaries
 * into per-rule stats (record_type 'd' at the day boundary, 'm' at the
 * month boundary). Relay traffic NEVER touches v2_user.u/d.
 */
class RelayTrafficService
{
    public const DELETED_RULE_GRACE_SECONDS = 86400;
    /**
     * @param array $batches each: rule_code, boot_id, seq, period_start,
     *                        period_end, bytes_in, bytes_out, connections?
     * @return array{accepted:int, duplicates:int, rejected:int, rejected_batches:array}
     *
     * SB-ER-014 (panel half): every batch is judged independently and
     * the response now names each rejected batch (rule_code / boot_id
     * / seq / reason). The agent-side defect — one rejected batch
     * causing the whole pending queue to be dropped, including later
     * valid batches — can only be fixed in xboard-node (batch 4), but
     * it needs this per-batch verdict to drop exactly the rejected
     * batches and keep the rest; aggregate counts alone cannot say
     * WHICH batches died. The field is additive: older agents reading
     * only the counts are unaffected.
     */
    public function ingest(ServerMachine $machine, array $batches): array
    {
        $accepted = 0;
        $duplicates = 0;
        $rejected = 0;
        $rejectedBatches = [];

        foreach ($batches as $batch) {
            $ruleCode = (string) ($batch['rule_code'] ?? '');
            $bootId = (string) ($batch['boot_id'] ?? '');
            $seq = (int) ($batch['seq'] ?? -1);
            $start = (int) ($batch['period_start'] ?? 0);
            $end = (int) ($batch['period_end'] ?? 0);
            if ($bootId === '' || $seq < 0 || $start <= 0 || $end <= $start) {
                $rejected++;
                $rejectedBatches[] = [
                    'rule_code' => $ruleCode,
                    'boot_id' => $bootId,
                    'seq' => $seq,
                    'reason' => 'invalid_batch',
                ];
                continue;
            }
            $bytesIn = max(0, (int) ($batch['bytes_in'] ?? 0));
            $bytesOut = max(0, (int) ($batch['bytes_out'] ?? 0));

            $booked = DB::transaction(function () use ($machine, $ruleCode, $bootId, $seq, $start, $end, $bytesIn, $bytesOut, $batch) {
                $rule = RelayRule::where('machine_id', $machine->id)->where('rule_code', $ruleCode)
                    ->lockForUpdate()->first();
                $deletedAt = null;
                if ($rule) {
                    $ruleId = (int) $rule->id;
                } else {
                    $tombstone = DB::table('suzu_relay_rule_tombstones')
                        ->where('machine_id', $machine->id)->where('rule_code', $ruleCode)
                        ->where('deleted_at', '>=', now()->timestamp - self::DELETED_RULE_GRACE_SECONDS)
                        ->lockForUpdate()->first();
                    if (!$tombstone) {
                        return null;
                    }
                    $ruleId = (int) $tombstone->rule_id;
                    $deletedAt = (int) $tombstone->deleted_at;
                }

                $identity = ['machine_id' => $machine->id, 'rule_id' => $ruleId, 'boot_id' => $bootId, 'seq' => $seq];
                try {
                    // A savepoint keeps the outer transaction readable
                    // after a unique violation, including on PostgreSQL.
                    $receipt = DB::transaction(fn () => RelayTrafficBatch::create($identity + [
                        'batch_id' => $machine->id . ':' . $ruleId . ':' . $bootId . ':' . $seq,
                        'period_start' => $start,
                        'period_end' => $end,
                        'bytes_in' => $bytesIn,
                        'bytes_out' => $bytesOut,
                        'connections' => max(0, (int) ($batch['connections'] ?? 0)),
                        'rule_deleted_at' => $deletedAt,
                        'created_at' => now(),
                    ]));
                } catch (UniqueConstraintViolationException $e) {
                    if (RelayTrafficBatch::where($identity)->lockForUpdate()->first(['id'])) {
                        return false;
                    }
                    throw $e;
                }
                if (!$receipt->exists) {
                    throw new \RuntimeException('Relay traffic receipt save was rejected');
                }
                $this->bookIntoStats($ruleId, $start, $end, $bytesIn, $bytesOut);
                return true;
            });

            if ($booked === null) {
                $rejected++;
                $rejectedBatches[] = [
                    'rule_code' => $ruleCode, 'boot_id' => $bootId, 'seq' => $seq, 'reason' => 'unknown_rule',
                ];
            } else {
                $booked ? $accepted++ : $duplicates++;
            }
        }

        return [
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'rejected_batches' => $rejectedBatches,
        ];
    }

    /**
     * Split [start, end) at UTC day boundaries; each day segment books its
     * pro-rata share (last segment takes the remainder so totals are exact)
     * into the day row and the month row of that segment.
     */
    private function bookIntoStats(int $ruleId, int $start, int $end, int $bytesIn, int $bytesOut): void
    {
        $total = $end - $start;
        $segments = [];
        $cursor = $start;
        while ($cursor < $end) {
            $dayStart = (int) (floor($cursor / 86400) * 86400);
            $segEnd = min($end, $dayStart + 86400);
            $segments[] = [$dayStart, $cursor, $segEnd];
            $cursor = $segEnd;
        }

        $bookedIn = 0;
        $bookedOut = 0;
        foreach ($segments as $i => [$dayStart, $segStart, $segEnd]) {
            $isLast = $i === count($segments) - 1;
            $in = $isLast ? $bytesIn - $bookedIn : intdiv($bytesIn * ($segEnd - $segStart), $total);
            $out = $isLast ? $bytesOut - $bookedOut : intdiv($bytesOut * ($segEnd - $segStart), $total);
            $bookedIn += $in;
            $bookedOut += $out;
            $monthStart = (int) strtotime(gmdate('Y-m-01 00:00:00', $dayStart) . ' UTC');
            $this->addStat($ruleId, 'd', $dayStart, $in, $out);
            $this->addStat($ruleId, 'm', $monthStart, $in, $out);
        }
    }

    private function addStat(int $ruleId, string $type, int $recordAt, int $in, int $out): void
    {
        if ($in === 0 && $out === 0) {
            return;
        }
        RelayRuleStat::firstOrCreate(
            ['rule_id' => $ruleId, 'record_type' => $type, 'record_at' => $recordAt],
            ['u' => 0, 'd' => 0]
        );
        $query = RelayRuleStat::where('rule_id', $ruleId)->where('record_type', $type)->where('record_at', $recordAt);
        if ($in > 0) {
            $query->increment('u', $in);
        }
        if ($out > 0) {
            RelayRuleStat::where('rule_id', $ruleId)->where('record_type', $type)->where('record_at', $recordAt)
                ->increment('d', $out);
        }
    }
}
