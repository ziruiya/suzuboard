<?php

namespace App\Services\Log0;

use App\Models\LogProbeResult;
use Carbon\CarbonImmutable;
use Generator;

class Rounds
{
    public function between(int $entryId, CarbonImmutable $from, CarbonImmutable $until): Generator
    {
        // Include the preceding normal-cadence round to confirm failure across
        // the window boundary, plus seven days for the same-time RTT baseline.
        $start = $from->subMinutes(5);
        $results = LogProbeResult::query()->where('entry_id', $entryId)
            ->where('checked_at', '>=', $start->subDays(7)->startOfDay()->format('Y-m-d H:i:s'))
            ->where('checked_at', '<', $until->format('Y-m-d H:i:s'))
            ->orderBy('checked_at')->orderBy('id')->cursor();

        $baseline = [];
        $previousFailures = [];
        $minute = null;
        $samples = [];
        foreach ($results as $result) {
            $bucket = substr($result->checked_at, 0, 16) . ':00';
            if ($samples && $bucket !== $minute) {
                $round = $this->evaluate($minute, $samples, $baseline, $previousFailures);
                if ($minute >= $start->format('Y-m-d H:i:s')) {
                    yield $round;
                }
                $samples = [];
            }
            $minute = $bucket;
            $samples[] = $result;
        }
        if ($samples && $minute >= $start->format('Y-m-d H:i:s')) {
            yield $this->evaluate($minute, $samples, $baseline, $previousFailures);
        }
    }

    private function evaluate(string $minute, array $samples, array &$baseline, array &$previousFailures): array
    {
        $time = CarbonImmutable::parse($minute, 'UTC');
        $slot = intdiv($time->hour * 60 + $time->minute, 5);
        $date = $time->toDateString();
        $sum = $count = 0;
        for ($day = 1; $day <= 7; $day++) {
            [$daySum, $dayCount] = $baseline[$slot][$time->subDays($day)->toDateString()] ?? [0, 0];
            $sum += $daySum;
            $count += $dayCount;
        }

        $points = [];
        foreach ($samples as $sample) {
            $points[$sample->probe_point] = $sample;
        }
        $mainlandPoints = count(array_filter(array_keys($points), fn ($point) => str_starts_with($point, 'cn-')));
        $overseasPoints = count($points) - $mainlandPoints;
        $paired = $mainlandPoints + $overseasPoints >= 2;
        $success = $degraded = $confirmedFailure = $observed = null;
        $overseasSuccess = $chinaSuccess = false;
        $failures = [];
        foreach ($points as $point => $sample) {
            $healthy = $sample->tcp_state === 'ok' && $sample->handshake_state === 'ok';
            $china = str_starts_with($point, 'cn-');
            $highLoss = $sample->loss_pct !== null && $sample->loss_pct >= 20;
            $highRtt = $sample->rtt_ms !== null && $count > 0 && $sample->rtt_ms > 3 * $sum / $count;

            if ($paired && $healthy) {
                $success = max($success, $sample->checked_at);
                $chinaSuccess = $chinaSuccess || $china;
                $overseasSuccess = $overseasSuccess || !$china;
            }
            if ($paired && ($highLoss || $highRtt)) {
                $degraded ??= $sample->checked_at;
                $observed = max($observed, $sample->checked_at);
            }
            if ($paired && $china && !$healthy) {
                $failures[$point] = $sample->checked_at;
            }
            if ($healthy && !$highLoss && !$highRtt && $sample->rtt_ms > 0) {
                [$slotSum, $slotCount] = $baseline[$slot][$date] ?? [0, 0];
                $baseline[$slot][$date] = [$slotSum + $sample->rtt_ms, $slotCount + 1];
            }
        }

        $consecutiveMainlandFailures = 0;
        if ($paired && !$chinaSuccess) {
            $earliest = $time->subMinutes(5)->format('Y-m-d H:i:s');
            foreach (array_intersect_key($previousFailures, $failures) as $point => $previous) {
                if ($previous >= $earliest) {
                    $consecutiveMainlandFailures++;
                    $observed = max($observed, $failures[$point]);
                    $confirmedFailure = $confirmedFailure === null ? $previous : min($confirmedFailure, $previous);
                }
            }
        }
        if ($paired) {
            $previousFailures = $chinaSuccess ? [] : $failures;
        }

        return [
            'minute' => $minute,
            'success' => $success,
            'degraded' => $degraded,
            'failed' => $confirmedFailure,
            'observed_at' => $observed,
            'recovered' => $chinaSuccess && !$failures && $degraded === null,
            'error_class' => $confirmedFailure !== null
                ? ($consecutiveMainlandFailures >= 2 && $overseasSuccess ? 'CN_DIRECTION_FAILURE' : 'CN_PROBE_FAILURE')
                : 'DEGRADED',
            'samples' => $samples,
        ];
    }
}
