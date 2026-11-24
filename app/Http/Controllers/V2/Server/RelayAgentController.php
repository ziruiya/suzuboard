<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayTrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SB-RELAY-01 agent channel (relay.config.v1). Same machine-token auth as
 * the other server/machine endpoints: machine_id + token in the request,
 * machine must be active. Three verbs:
 *  - config:  pull the latest published full config document;
 *  - ack:     report a version applied (or failed) — the persistent ACK;
 *  - traffic: report immutable per-rule traffic batches (idempotent).
 */
class RelayAgentController extends Controller
{
    public function config(Request $request, RelayConfigService $config): JsonResponse
    {
        $machine = $this->authenticateMachine($request);
        $latest = $config->latestForAgent($machine);
        if ($latest === null) {
            return response()->json([
                'protocol' => RelayConfigService::PROTOCOL,
                'machine_id' => (int) $machine->id,
                'version' => 0,
                'hash' => null,
                'config' => null,
            ]);
        }
        return response()->json([
            'protocol' => RelayConfigService::PROTOCOL,
            'machine_id' => (int) $machine->id,
            'version' => $latest['version'],
            'hash' => $latest['hash'],
            'config' => $latest['config'],
        ]);
    }

    public function ack(Request $request, RelayConfigService $config): JsonResponse
    {
        $request->validate([
            'version' => 'required|integer|min:1',
            'hash' => 'nullable|string',
            'applied' => 'required|boolean',
            'error' => 'nullable|string',
        ]);
        $machine = $this->authenticateMachine($request);
        $ok = $config->handleAck(
            $machine,
            (int) $request->input('version'),
            $request->input('hash'),
            $request->boolean('applied'),
            $request->input('error')
        );
        if (!$ok) {
            return response()->json(['status' => 'fail', 'message' => 'unknown version or hash mismatch'], 422);
        }
        return response()->json(['data' => true]);
    }

    public function traffic(Request $request, RelayTrafficService $traffic): JsonResponse
    {
        $request->validate([
            'batches' => 'required|array|min:1|max:500',
            'batches.*.rule_code' => 'required|string',
            'batches.*.boot_id' => 'required|string',
            'batches.*.seq' => 'required|integer|min:0',
            'batches.*.period_start' => 'required|integer|min:1',
            'batches.*.period_end' => 'required|integer|min:1',
            'batches.*.bytes_in' => 'required|integer|min:0',
            'batches.*.bytes_out' => 'required|integer|min:0',
            'batches.*.connections' => 'nullable|integer|min:0',
        ]);
        $machine = $this->authenticateMachine($request);
        $result = $traffic->ingest($machine, (array) $request->input('batches'));
        return response()->json(['data' => $result]);
    }

    private function authenticateMachine(Request $request): ServerMachine
    {
        $request->validate([
            'machine_id' => 'required|integer',
            'token' => 'required|string',
        ]);

        $machine = ServerMachine::where('id', $request->input('machine_id'))
            ->where('token', $request->input('token'))
            ->first();

        if (!$machine || !$machine->is_active) {
            abort(403, 'Machine not found or disabled');
        }

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();

        return $machine;
    }
}
