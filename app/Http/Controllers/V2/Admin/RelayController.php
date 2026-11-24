<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\RelayAuditLog;
use App\Models\RelayConfigOutbox;
use App\Models\RelayRule;
use App\Models\RelayRuleStat;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayMachineService;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayValidationException;
use Illuminate\Http\Request;

/**
 * SB-RELAY-01 admin surface: relay machines, rules, config versions and
 * audit. Admin-only (V2 admin route group); writes require confirm=true,
 * the endpoint-module convention. Nothing here enables a machine's relay
 * unless an admin explicitly toggles it.
 */
class RelayController extends Controller
{
    private function requireConfirm(Request $request): bool
    {
        return $request->boolean('confirm');
    }

    public function machines()
    {
        $todayStart = (int) (floor(now()->timestamp / 86400) * 86400);
        $monthStart = (int) strtotime(gmdate('Y-m-01 00:00:00', now()->timestamp) . ' UTC');
        $machines = ServerMachine::orderBy('id')->get()->map(function (ServerMachine $m) use ($todayStart, $monthStart) {
            $ruleIds = RelayRule::where('machine_id', $m->id)->pluck('id');
            $traffic = function (string $type, int $at) use ($ruleIds) {
                $row = RelayRuleStat::whereIn('rule_id', $ruleIds)
                    ->where('record_type', $type)->where('record_at', $at)
                    ->selectRaw('COALESCE(SUM(u),0) as u, COALESCE(SUM(d),0) as d')->first();
                return ['u' => (int) ($row->u ?? 0), 'd' => (int) ($row->d ?? 0)];
            };
            return [
                'id' => $m->id,
                'name' => $m->name,
                'is_active' => $m->is_active,
                'last_seen_at' => $m->last_seen_at,
                'node_count' => Server::where('machine_id', $m->id)->count(),
                'relay_enabled' => $m->relay_enabled,
                'relay_status' => $m->relay_status,
                'relay_tier' => $m->relay_tier,
                'limits' => [
                    'rules' => $m->relayRulesLimit(),
                    'tcp' => $m->relayTcpLimit(),
                    'udp' => $m->relayUdpLimit(),
                ],
                'config_version' => (int) $m->relay_config_version,
                'applied_version' => (int) $m->relay_applied_version,
                'applied_hash' => $m->relay_applied_hash,
                'last_error' => $m->relay_last_error,
                'rule_count' => $ruleIds->count(),
                'traffic_today' => $traffic('d', $todayStart),
                'traffic_month' => $traffic('m', $monthStart),
            ];
        });
        return $this->success($machines);
    }

    public function toggleMachine(Request $request, RelayMachineService $machines)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        if (!$machine) {
            return $this->fail([404, 'machine not found']);
        }
        $action = (string) $request->input('action');
        try {
            if ($action === 'enable') {
                $machines->enable($machine, 'admin', $request->user()?->id);
            } elseif ($action === 'disable') {
                $machines->beginDrain($machine, 'admin', $request->user()?->id);
            } else {
                return $this->fail([422, 'action must be enable or disable']);
            }
        } catch (RelayValidationException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($machine->fresh());
    }

    public function setTier(Request $request, RelayMachineService $machines)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        if (!$machine) {
            return $this->fail([404, 'machine not found']);
        }
        try {
            $machines->setTier(
                $machine,
                (string) $request->input('tier', $machine->relay_tier),
                $request->input('relay_rules_limit') !== null ? (int) $request->input('relay_rules_limit') : null,
                $request->input('relay_tcp_limit') !== null ? (int) $request->input('relay_tcp_limit') : null,
                $request->input('relay_udp_limit') !== null ? (int) $request->input('relay_udp_limit') : null,
                'admin',
                $request->user()?->id
            );
        } catch (RelayValidationException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($machine->fresh());
    }

    /** Nodes that may serve as NODE targets (agent-deliverable only). */
    public function nodes()
    {
        $nodes = Server::orderBy('id')->get()
            ->filter(fn(Server $n) => \App\Services\ServerService::isAgentDeliverable($n))
            ->map(fn(Server $n) => [
                'id' => $n->id,
                'name' => $n->name,
                'type' => $n->type,
                'host' => $n->host,
                'server_port' => (int) $n->server_port,
                'machine_id' => $n->machine_id,
            ])->values();
        return $this->success($nodes);
    }

    public function rules(Request $request)
    {
        $query = RelayRule::with('targetNode')->orderBy('machine_id')->orderBy('id');
        if ($request->filled('machine_id')) {
            $query->where('machine_id', (int) $request->input('machine_id'));
        }
        $rules = $query->get()->map(fn(RelayRule $r) => $this->presentRule($r));
        return $this->success($rules);
    }

    private function presentRule(RelayRule $r): array
    {
        $todayStart = (int) (floor(now()->timestamp / 86400) * 86400);
        $monthStart = (int) strtotime(gmdate('Y-m-01 00:00:00', now()->timestamp) . ' UTC');
        $stat = function (string $type, int $at) use ($r) {
            $row = RelayRuleStat::where('rule_id', $r->id)->where('record_type', $type)->where('record_at', $at)->first();
            return ['u' => (int) ($row->u ?? 0), 'd' => (int) ($row->d ?? 0)];
        };
        return [
            'id' => $r->id,
            'rule_code' => $r->rule_code,
            'machine_id' => $r->machine_id,
            'name' => $r->name,
            'transport' => $r->transport,
            'listen_ip' => $r->listen_ip,
            'listen_port' => (int) $r->listen_port,
            'target_type' => $r->target_type,
            'target_node_id' => $r->target_node_id,
            'target_node_name' => $r->targetNode?->name,
            'target_host' => $r->target_host,
            'target_port' => (int) $r->target_port,
            'snapshot_host' => $r->target_snapshot_host,
            'snapshot_port' => $r->target_snapshot_port,
            'enabled' => $r->enabled,
            'status' => $r->status,
            'health_status' => $r->health_status,
            'config_version' => (int) $r->config_version,
            'allowlist_cidrs' => $r->allowlist_cidrs ?: [],
            'allowlist_approved' => $r->allowlist_approved,
            'max_connections' => (int) $r->max_connections,
            'udp_max_sessions' => (int) $r->udp_max_sessions,
            'last_error' => $r->last_error,
            'traffic_today' => $stat('d', $todayStart),
            'traffic_month' => $stat('m', $monthStart),
            'created_at' => $r->created_at?->toDateTimeString(),
            'updated_at' => $r->updated_at?->toDateTimeString(),
        ];
    }

    public function createRule(Request $request, RelayRuleService $rules)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        if (!$machine) {
            return $this->fail([404, 'machine not found']);
        }
        try {
            $rule = $rules->create($machine, $request->all(), 'admin', $request->user()?->id);
        } catch (RelayValidationException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($this->presentRule($rule));
    }

    public function updateRule(Request $request, RelayRuleService $rules)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $rule = RelayRule::find((int) $request->input('id'));
        if (!$rule) {
            return $this->fail([404, 'rule not found']);
        }
        try {
            $rule = $rules->update($rule, $request->all(), 'admin', $request->user()?->id);
        } catch (RelayValidationException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($this->presentRule($rule));
    }

    public function toggleRule(Request $request, RelayRuleService $rules)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $rule = RelayRule::find((int) $request->input('id'));
        if (!$rule) {
            return $this->fail([404, 'rule not found']);
        }
        try {
            $rule = $rules->setEnabled($rule, $request->boolean('enabled'), 'admin', $request->user()?->id);
        } catch (RelayValidationException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($this->presentRule($rule));
    }

    public function dropRule(Request $request, RelayRuleService $rules)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $rule = RelayRule::find((int) $request->input('id'));
        if (!$rule) {
            return $this->fail([404, 'rule not found']);
        }
        $rules->delete($rule, 'admin', $request->user()?->id);
        return $this->success(true);
    }

    public function outbox(Request $request)
    {
        $query = RelayConfigOutbox::orderByDesc('version');
        if ($request->filled('machine_id')) {
            $query->where('machine_id', (int) $request->input('machine_id'));
        }
        $rows = $query->limit(50)->get()->map(fn(RelayConfigOutbox $o) => [
            'id' => $o->id,
            'machine_id' => $o->machine_id,
            'version' => (int) $o->version,
            'config_hash' => $o->config_hash,
            'status' => $o->status,
            'created_by' => $o->created_by,
            'created_at' => $o->created_at?->toDateTimeString(),
        ]);
        return $this->success($rows);
    }

    public function rollback(Request $request, RelayConfigService $config)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        if (!$machine) {
            return $this->fail([404, 'machine not found']);
        }
        try {
            $outbox = $config->rollback($machine, (int) $request->input('to_version'), 'admin', $request->user()?->id);
        } catch (RelayValidationException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success(['version' => (int) $outbox->version, 'config_hash' => $outbox->config_hash]);
    }

    public function republish(Request $request, RelayConfigService $config)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        if (!$machine) {
            return $this->fail([404, 'machine not found']);
        }
        $outbox = $config->publish($machine, 'admin', $request->user()?->id);
        return $this->success(['version' => (int) $outbox->version, 'config_hash' => $outbox->config_hash]);
    }

    public function audit(Request $request)
    {
        $query = RelayAuditLog::orderByDesc('id');
        if ($request->filled('machine_id')) {
            $query->where('machine_id', (int) $request->input('machine_id'));
        }
        if ($request->filled('rule_id')) {
            $query->where('rule_id', (int) $request->input('rule_id'));
        }
        $rows = $query->limit(200)->get()->map(fn(RelayAuditLog $l) => [
            'id' => $l->id,
            'machine_id' => $l->machine_id,
            'rule_id' => $l->rule_id,
            'actor_type' => $l->actor_type,
            'actor_id' => $l->actor_id,
            'action' => $l->action,
            'detail' => $l->detail,
            'created_at' => $l->created_at?->toDateTimeString(),
        ]);
        return $this->success($rows);
    }
}
