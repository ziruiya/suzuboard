<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\SuzuEndpointException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\SuzuEndpointBinding;
use App\Models\SuzuEndpointChange;
use App\Models\SuzuEndpointContract;
use App\Services\SuzuDnsCutoverService;
use App\Services\SuzuEndpointContractService;
use App\Services\SuzuOriginPreflightService;
use Illuminate\Http\Request;

/**
 * SB-Endpoint-01 admin API: endpoint contracts, bindings, preflight,
 * DNS cutover and rollback.
 *
 * Read endpoints are plain GETs. Every write (snapshot / preflight /
 * cutover / rollback) requires an explicit `confirm: true` in the
 * request body and is recorded in suzu_endpoint_changes with the acting
 * admin's id.
 */
class SuzuEndpointController extends Controller
{
    public function __construct(
        private SuzuEndpointContractService $contractService,
        private SuzuOriginPreflightService $preflightService,
        private SuzuDnsCutoverService $dnsService,
    ) {
    }

    /** Contract list with their bindings. */
    public function contracts(Request $request)
    {
        $contracts = SuzuEndpointContract::with('binding')
            ->orderBy('logical_node_id')
            ->orderByDesc('version')
            ->get()
            ->map(fn (SuzuEndpointContract $c) => $this->presentContract($c));

        return $this->success($contracts);
    }

    /** One contract + binding + recent audit trail. */
    public function contract(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer']);
        $contract = SuzuEndpointContract::with('binding')->find($params['id']);
        if (!$contract) {
            return $this->fail([404, 'contract not found']);
        }
        $data = $this->presentContract($contract);
        $data['integrity'] = $this->contractService->verifyIntegrity($contract);
        $data['changes'] = $contract->changes()->orderByDesc('id')->limit(50)->get();

        return $this->success($data);
    }

    /** Generate (or re-issue) the contract snapshot for a node. */
    public function snapshot(Request $request)
    {
        $params = $request->validate([
            'server_id' => 'required|integer',
            'confirm' => 'required|boolean',
        ]);
        if (!$params['confirm']) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $server = Server::find($params['server_id']);
        if (!$server) {
            return $this->fail([404, 'server not found']);
        }
        $result = $this->contractService->snapshotFromServer($server, $this->actorId($request));

        return $this->success([
            'contract' => $this->presentContract($result['contract']->load('binding')),
            'created' => $result['created'],
            'alarm' => $result['alarm'],
            'drift' => $result['drift'],
        ]);
    }

    /** Binding detail for one contract. */
    public function binding(Request $request)
    {
        $params = $request->validate(['contract_id' => 'required|integer']);
        $binding = SuzuEndpointBinding::where('contract_id', $params['contract_id'])->first();
        if (!$binding) {
            return $this->fail([404, 'binding not found']);
        }
        return $this->success($binding);
    }

    /** Run an origin preflight for a contract against a candidate IP. */
    public function preflight(Request $request)
    {
        $params = $request->validate([
            'contract_id' => 'required|integer',
            'target_ip' => 'required|ip',
            'confirm' => 'required|boolean',
        ]);
        if (!$params['confirm']) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $contract = SuzuEndpointContract::find($params['contract_id']);
        if (!$contract) {
            return $this->fail([404, 'contract not found']);
        }
        $result = $this->preflightService->run($contract, $params['target_ip'], $this->actorId($request));

        return $this->success($result);
    }

    /** DNS cutover: point the binding's record at a new IP. */
    public function cutover(Request $request)
    {
        $params = $request->validate([
            'contract_id' => 'required|integer',
            'new_ip' => 'required|ip',
            'expected_revision' => 'required|integer|min:1',
            'confirm' => 'required|boolean',
        ]);
        if (!$params['confirm']) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $binding = SuzuEndpointBinding::where('contract_id', $params['contract_id'])->first();
        if (!$binding) {
            return $this->fail([404, 'binding not found']);
        }
        $binding->load('contract');
        $precheck = $binding->last_preflight;
        if (!is_array($precheck) || ($precheck['overall'] ?? null) !== SuzuEndpointBinding::HEALTH_PASSED
            || ($precheck['target_ip'] ?? null) !== $params['new_ip']) {
            return $this->fail([422, 'a passed preflight for this exact target ip is required before cutover']);
        }
        try {
            $result = $this->dnsService->cutover(
                $binding,
                $params['new_ip'],
                $params['expected_revision'],
                $this->actorId($request),
                $precheck
            );
        } catch (SuzuEndpointException $e) {
            return $this->fail([422, $e->getMessage()]);
        }

        return $this->success([
            'binding' => $result['binding'],
            'change_id' => $result['change']?->id,
            'idempotent' => $result['idempotent'],
        ]);
    }

    /** Roll back a completed DNS cutover. */
    public function rollback(Request $request)
    {
        $params = $request->validate([
            'change_id' => 'required|integer',
            'expected_revision' => 'required|integer|min:1',
            'confirm' => 'required|boolean',
        ]);
        if (!$params['confirm']) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $change = SuzuEndpointChange::find($params['change_id']);
        if (!$change) {
            return $this->fail([404, 'change not found']);
        }
        try {
            $result = $this->dnsService->rollback($change, $params['expected_revision'], $this->actorId($request));
        } catch (SuzuEndpointException $e) {
            return $this->fail([422, $e->getMessage()]);
        }

        return $this->success([
            'binding' => $result['binding'],
            'change_id' => $result['change']->id,
        ]);
    }

    /** Audit trail, optionally filtered by contract. */
    public function changes(Request $request)
    {
        $params = $request->validate(['contract_id' => 'nullable|integer']);
        $query = SuzuEndpointChange::orderByDesc('id');
        if (!empty($params['contract_id'])) {
            $query->where('contract_id', $params['contract_id']);
        }
        return $this->success($query->limit(200)->get());
    }

    private function presentContract(SuzuEndpointContract $contract): array
    {
        $data = $contract->toArray();
        // Defence in depth: contracts never carry key material, but make
        // sure nothing secret-shaped ever reaches the frontend.
        unset($data['private_key'], $data['password']);
        return $data;
    }

    private function actorId(Request $request): ?int
    {
        return $request->user()?->id;
    }
}
