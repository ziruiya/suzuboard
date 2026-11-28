<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogEntry;
use App\Models\LogEntryEvent;
use App\Services\Log0\Mappings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Log0Controller extends Controller
{
    public function createEntry(Request $request)
    {
        return response()->json(LogEntry::query()->create($this->entryData($request)), 201);
    }

    public function updateEntry(Request $request, LogEntry $entry)
    {
        $entry->update($this->entryData($request, $entry));

        return response()->json($entry->refresh());
    }

    public function appendMapping(Request $request, LogEntry $entry, Mappings $mappings)
    {
        $data = $request->validate([
            'entry_ip' => 'required|ip',
            'ingress_port' => 'nullable|integer|between:1,65535',
            'relay_rule_id' => 'nullable|string|max:64|regex:/\A[a-zA-Z0-9_-]+\z/',
            'backend_node_id' => 'required|integer|between:1,2147483647',
            'backend_port' => 'required|integer|between:1,65535',
            'valid_from' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'provenance' => 'required|in:contract,provider_screenshot,provider_api,manual_check',
            'mapping_confidence' => 'required|in:VERIFIED_DIRECT,VERIFIED_RELAY,MAPPED_UNIQUE,AMBIGUOUS',
        ]);
        $data['entry_ip'] = inet_ntop(inet_pton($data['entry_ip']));
        $validFrom = CarbonImmutable::parse($data['valid_from'], 'UTC');
        if ($validFrom->isFuture()) {
            throw ValidationException::withMessages(['valid_from' => 'Use an effective time at or before now.']);
        }
        $data['valid_from'] = $validFrom->toDateTimeString();
        $data['created_by'] = 'admin:' . Auth::guard('sanctum')->id();

        return response()->json($mappings->append($entry->getKey(), $data), 201);
    }

    public function resolveNode(Request $request, Mappings $mappings)
    {
        $data = $request->validate([
            'backend_node_id' => 'required|integer|between:1,2147483647',
            'at' => 'required|date_format:Y-m-d\TH:i:s\Z',
        ]);

        return response()->json($mappings->resolveNode($data['backend_node_id'], CarbonImmutable::parse($data['at'])));
    }

    public function updateEvent(Request $request, string $incident)
    {
        $data = $request->validate([
            'provider_notice' => ['sometimes', 'nullable', 'string', 'max:4000', $this->diagnosticSummary(...)],
            'attribution_status' => ['sometimes', 'required', Rule::in(LogEntryEvent::ATTRIBUTION)],
            'hypothesis' => ['sometimes', 'nullable', 'string', 'max:4000', $this->diagnosticSummary(...)],
            'confidence' => 'sometimes|nullable|in:low,med,high',
            'status' => 'sometimes|required|in:open,monitoring,closed',
        ]);

        $event = DB::transaction(function () use ($incident, $data) {
            $event = LogEntryEvent::query()->lockForUpdate()->findOrFail($incident);
            if (($data['status'] ?? null) === 'open' && $event->historical_backfill) {
                throw ValidationException::withMessages(['status' => 'Historical backfills cannot be opened as live events.']);
            }
            if (isset($data['status'])) {
                $data['closed_at'] = $data['status'] === 'closed'
                    ? ($event->closed_at ?? now('UTC')->format('Y-m-d H:i:s')) : null;
            }
            $event->forceFill($data)->save();

            return $event;
        }, 3);

        return response()->json($event);
    }

    private function diagnosticSummary(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!is_string($value)) {
            return;
        }
        if (preg_match('~://|\b(?:[a-z0-9-]+\.)+[a-z]{2,63}\b~i', $value)) {
            $fail('Use a summary without links or hostnames.');
            return;
        }
        preg_match_all('/[\da-f:.]+/i', $value, $tokens);
        foreach ($tokens[0] as $token) {
            if (filter_var(rtrim($token, '.'), FILTER_VALIDATE_IP) !== false ||
                (str_contains($token, '.') && filter_var(explode(':', $token)[0], FILTER_VALIDATE_IP) !== false)) {
                $fail('Use a summary without IP addresses.');
                return;
            }
        }
    }

    private function entryData(Request $request, ?LogEntry $entry = null): array
    {
        $key = Rule::unique('log_entries', 'entry_key');
        if ($entry) {
            $key->ignore($entry->getKey(), 'entry_id');
        }
        $presence = $entry ? 'sometimes' : 'required';

        return $request->validate([
            'entry_key' => [$presence, 'required', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9_-]+\z/', $key],
            'provider' => [$presence, 'required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'kind' => [$presence, 'required', 'in:third_party_forward,self_relay,vx_forward,direct'],
            'shared_flag' => 'sometimes|required|in:shared,dedicated,unknown',
            'hostname' => ['nullable', 'string', 'max:255', 'regex:/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?\z/i'],
            'note' => ['nullable', 'string', 'max:255', $this->diagnosticSummary(...)],
        ]);
    }
}
