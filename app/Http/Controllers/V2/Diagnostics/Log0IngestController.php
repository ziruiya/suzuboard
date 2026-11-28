<?php

namespace App\Http\Controllers\V2\Diagnostics;

use App\Http\Controllers\Controller;
use App\Models\LogEntry;
use App\Models\LogEntryDnsHistory;
use App\Models\LogEntryEvent;
use App\Models\LogProbeResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class Log0IngestController extends Controller
{
    public function __invoke(Request $request)
    {
        $received = CarbonImmutable::now('UTC');
        $point = $request->header('X-Log0-Probe');
        $keys = config('log0.probe_keys');
        $expected = is_string($point) ? ($keys[$point] ?? null) : null;
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $request->bearerToken() ?? '')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (array_diff(array_keys($request->all()), ['result', 'dropped_total'])) {
            throw ValidationException::withMessages(['report' => 'Unexpected report field.']);
        }

        $data = $request->validate([
            'dropped_total' => 'required|integer|min:0|max:9007199254740991',
            'result' => 'nullable|array:entry_id,checked_at,target_ip,dns_state,tcp_state,handshake_state,loss_pct,rtt_ms,error_class,resolutions',
            'result.entry_id' => 'required_with:result|integer|min:1',
            'result.checked_at' => 'required_with:result|date_format:Y-m-d\TH:i:s\Z',
            'result.target_ip' => 'required_with:result|ip',
            'result.dns_state' => 'required_with:result|in:ok,changed,nxdomain,error',
            'result.tcp_state' => 'required_with:result|in:ok,timeout,refused,error',
            'result.handshake_state' => 'required_with:result|in:ok,fail,na',
            'result.loss_pct' => 'nullable|numeric|between:0,100',
            'result.rtt_ms' => 'nullable|integer|between:0,2147483647',
            'result.error_class' => 'nullable|in:DNS_NXDOMAIN,DNS_ERROR,TCP_TIMEOUT,TCP_REFUSED,TCP_ERROR,HANDSHAKE_FAILED,PACKET_LOSS,RTT_DEGRADED',
            'result.resolutions' => 'sometimes|array|max:128',
            'result.resolutions.*' => 'required|array:resolver,resolved_ip',
            'result.resolutions.*.resolver' => 'required|string|max:64|regex:/\A[a-zA-Z0-9_-]+\z/',
            'result.resolutions.*.resolved_ip' => 'required|ip',
        ]);

        $result = $data['result'] ?? null;
        if ($result !== null) {
            if (!isset($result['checked_at'])) {
                throw ValidationException::withMessages(['result' => 'A result must contain a sample.']);
            }
            $checked = CarbonImmutable::parse($result['checked_at'], 'UTC');
            if ($checked->lt($received->subDay()->subMinutes(5)) || $checked->gt($received->addMinutes(5))) {
                throw ValidationException::withMessages(['result.checked_at' => 'Outside the diagnostic upload window.']);
            }
            if (($result['tcp_state'] === 'ok') === ($result['handshake_state'] === 'na')) {
                throw ValidationException::withMessages(['result.handshake_state' => 'Inconsistent with TCP result.']);
            }
        }

        try {
            if ($result !== null && !$this->store($result, $point, $checked, $received)) {
                return response()->json(['ack' => false, 'code' => 'INGEST_CONFLICT'], 409);
            }

            $counters = Cache::build(['driver' => 'file', 'path' => config('log0.counter_path')]);
            $counters->lock('drop-counters:' . $point, 5)->block(2, function () use ($counters, $point, $data) {
                $key = 'dropped:' . $point;
                if ($data['dropped_total'] > $counters->get($key, 0) &&
                    !$counters->forever($key, (int) $data['dropped_total'])) {
                    throw new \RuntimeException('Diagnostic counter write failed');
                }
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            return response()->json(['message' => 'Diagnostic storage unavailable'], 503);
        }

        return response()->json(['ack' => true]);
    }

    private function store(array $result, string $point, CarbonImmutable $checked, CarbonImmutable $received): bool
    {
        $observations = [];
        foreach ($result['resolutions'] ?? [] as $observation) {
            $ip = inet_ntop(inet_pton($observation['resolved_ip']));
            $resolver = $observation['resolver'];
            $observations[$resolver . '/' . $ip] = ['resolver' => $resolver, 'resolved_ip' => $ip];
        }
        ksort($observations, SORT_STRING);

        unset($result['resolutions']);
        $result['entry_id'] = (int) $result['entry_id'];
        $result['target_ip'] = inet_ntop(inet_pton($result['target_ip']));
        $result['checked_at'] = $checked->toDateTimeString();
        $result['probe_point'] = $point;
        $result['loss_pct'] = isset($result['loss_pct']) ? (float) $result['loss_pct'] + 0.0 : null;
        $result['rtt_ms'] = isset($result['rtt_ms']) ? (int) $result['rtt_ms'] : null;
        $result['error_class'] ??= null;
        ksort($result, SORT_STRING);

        // The drop counter is a separate, mutable probe-level metric.
        $result['report_digest'] = hash('sha256', json_encode([
            ...$result,
            'resolutions' => array_values($observations),
        ], JSON_THROW_ON_ERROR));
        $result['received_at'] = $received->toDateTimeString();

        return DB::transaction(function () use ($result, $observations) {
            if (!LogEntry::query()->whereKey($result['entry_id'])->exists()) {
                throw ValidationException::withMessages(['result.entry_id' => 'Unknown diagnostic entry.']);
            }

            if (!LogProbeResult::query()->insertOrIgnore($result)) {
                $original = LogProbeResult::query()->where([
                    'entry_id' => $result['entry_id'],
                    'probe_point' => $result['probe_point'],
                    'checked_at' => $result['checked_at'],
                ])->lockForUpdate()->first();
                if (!$original) {
                    throw new \RuntimeException('Diagnostic sample write failed');
                }
                if ($original->report_digest === $result['report_digest']) {
                    return true;
                }

                // Legacy samples only retain aggregate DNS history, so equality cannot be established.
                LogEntryEvent::query()->insertOrIgnore([
                    'incident_id' => 'INC-ING-' . substr($result['report_digest'], 0, 24),
                    'entry_id' => $result['entry_id'],
                    'opened_at' => $result['received_at'],
                    'closed_at' => $result['received_at'],
                    'observed_at' => $result['checked_at'],
                    'computed_at' => $result['received_at'],
                    'error_class' => 'INGEST_CONFLICT',
                    'status' => 'closed',
                ]);

                return false;
            }

            $seen = $result['checked_at'];
            $quoted = DB::getPdo()->quote($seen);
            foreach ($observations as $observation) {
                $ip = $observation['resolved_ip'];
                LogEntryDnsHistory::query()->upsert([[
                    'entry_id' => $result['entry_id'],
                    'resolved_ip' => $ip,
                    'ip_family' => str_contains($ip, ':') ? 'v6' : 'v4',
                    'resolver' => $observation['resolver'],
                    'first_seen_at' => $seen,
                    'last_seen_at' => $seen,
                    'source' => 'probe',
                ]], ['entry_id', 'resolved_ip', 'resolver'], [
                    'first_seen_at' => DB::raw("CASE WHEN first_seen_at > $quoted THEN $quoted ELSE first_seen_at END"),
                    'last_seen_at' => DB::raw("CASE WHEN last_seen_at < $quoted THEN $quoted ELSE last_seen_at END"),
                ]);
            }

            return true;
        }, 3);
    }
}
