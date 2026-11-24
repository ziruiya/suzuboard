<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryConfig;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterpriseOrderIntent;
use App\Models\EnterprisePurpose;
use App\Models\User;
use App\Services\Enterprise\EnterpriseAssignmentService;
use App\Services\Enterprise\EnterpriseInventoryHealthService;
use App\Services\Enterprise\EnterpriseKitBuilderService;
use App\Services\Enterprise\EnterpriseReplenishmentService;
use Illuminate\Http\Request;

/**
 * SB-ENT-INV-01 admin surface: enterprise inventory kits, rule stock,
 * blueprints, alerts and the waiting queue. Write operations follow the
 * endpoint module's convention and require confirm=true.
 */
class EnterpriseInventoryController extends Controller
{
    private function requireConfirm(Request $request): bool
    {
        return $request->boolean('confirm');
    }

    public function purposes()
    {
        $purposes = EnterprisePurpose::with('config')->orderBy('sort')->get()->map(function (EnterprisePurpose $p) {
            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'region' => $p->regionCode(),
                'usage_key' => $p->usageKey(),
                'is_active' => $p->is_active,
                'machine_id' => $p->machine_id,
                'protocol' => $p->protocol,
                'entry_provider' => $p->entry_provider,
                'entry_host' => $p->entry_host,
                'landing_port_base' => $p->landing_port_base,
                'entry_port_base' => $p->entry_port_base,
                'dns_alias_suffix' => $p->dns_alias_suffix,
                'blueprint_complete' => $p->blueprintComplete(),
                'target_level' => $p->config?->target_level ?? EnterpriseInventoryConfig::DEFAULT_TARGET_LEVEL,
                'current_level' => $p->config?->current_level ?? 0,
                'ready_rules' => EnterpriseInventoryRule::where('purpose_id', $p->id)
                    ->where('status', EnterpriseInventoryRule::STATUS_READY)
                    ->whereNull('kit_id')
                    ->count(),
            ];
        });
        return $this->success($purposes);
    }

    public function updateBlueprint(Request $request)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $params = $request->validate([
            'purpose_id' => 'required|integer',
            'machine_id' => 'nullable|integer',
            'protocol' => 'nullable|in:vless_reality,vless_tls_ws',
            'entry_provider' => 'nullable|in:csmzzmb,gost,vx',
            'entry_host' => 'nullable|string|max:255',
            'landing_port_base' => 'nullable|integer|min:1|max:65535',
            'entry_port_base' => 'nullable|integer|min:1|max:65535',
            'dns_alias_suffix' => 'nullable|string|max:255',
            'target_level' => 'nullable|integer|min:1|max:50',
        ]);
        $purpose = EnterprisePurpose::find($params['purpose_id']);
        if (!$purpose) {
            return $this->fail([404, 'purpose not found']);
        }
        $purpose->fill(collect($params)->except(['purpose_id', 'target_level'])->all());
        $purpose->save();
        if (isset($params['target_level'])) {
            EnterpriseInventoryConfig::updateOrCreate(
                ['purpose_id' => $purpose->id],
                ['target_level' => $params['target_level']]
            );
        }
        return $this->success(true);
    }

    public function kits(Request $request)
    {
        $kits = EnterpriseInventoryKit::with(['purpose', 'nodes.server', 'group'])
            ->when($request->input('purpose_id'), fn($q) => $q->where('purpose_id', $request->input('purpose_id')))
            ->when($request->input('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->get()
            ->map(fn(EnterpriseInventoryKit $kit) => $this->presentKit($kit));
        return $this->success($kits);
    }

    public function kit(Request $request)
    {
        $kit = EnterpriseInventoryKit::with(['purpose', 'nodes.server', 'group', 'rules'])
            ->find($request->input('id'));
        if (!$kit) {
            return $this->fail([404, 'kit not found']);
        }
        $data = $this->presentKit($kit);
        $data['rules'] = $kit->rules;
        $data['logs'] = EnterpriseInventoryLog::where('kit_id', $kit->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        return $this->success($data);
    }

    private function presentKit(EnterpriseInventoryKit $kit): array
    {
        $nodes = $kit->nodes->map(function ($node) {
            return [
                'id' => $node->id,
                'server_id' => $node->server_id,
                'role' => $node->node_role,
                'name' => $node->server?->name,
                'port' => $node->port,
                'landing_port' => $node->landing_port,
                'dns_alias' => $node->dns_alias,
            ];
        })->values();
        return [
            'id' => $kit->id,
            'purpose_id' => $kit->purpose_id,
            'purpose_code' => $kit->purpose?->code,
            'purpose_name' => $kit->purpose?->name,
            'stock_group_id' => $kit->stock_group_id,
            'group_name' => $kit->group?->name,
            'sequence' => $kit->sequence,
            'status' => $kit->status,
            'nodes' => $nodes,
            'dns_alias' => $kit->nodes->first()?->dns_alias,
            'current_user_id' => $kit->current_user_id,
            'current_order_id' => $kit->current_order_id,
            'last_probe_at' => $kit->last_probe_at?->toDateTimeString(),
            'last_full_probe_at' => $kit->last_full_probe_at?->toDateTimeString(),
            'health_fail_count' => $kit->health_fail_count,
            'created_at' => $kit->created_at?->toDateTimeString(),
        ];
    }

    public function build(Request $request, EnterpriseReplenishmentService $replenishment)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $purpose = EnterprisePurpose::find($request->input('purpose_id'));
        if (!$purpose) {
            return $this->fail([404, 'purpose not found']);
        }
        $kit = $replenishment->replenishIfNeeded($purpose);
        return $this->success($kit ? $this->presentKit($kit->load(['purpose', 'nodes.server', 'group'])) : null);
    }

    public function confirmRules(Request $request, EnterpriseKitBuilderService $builder)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $params = $request->validate([
            'kit_id' => 'required|integer',
            'rule_ids' => 'required|array|size:2',
            'rule_ids.*' => 'integer',
        ]);
        $kit = EnterpriseInventoryKit::find($params['kit_id']);
        if (!$kit) {
            return $this->fail([404, 'kit not found']);
        }
        try {
            $kit = $builder->attachRules($kit, $params['rule_ids']);
        } catch (\Throwable $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($this->presentKit($kit->load(['purpose', 'nodes.server', 'group'])));
    }

    public function registerRule(Request $request, \App\Services\Enterprise\EnterpriseCapacityService $capacity)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $params = $request->validate([
            'purpose_id' => 'required|integer',
            'provider' => 'required|in:csmzzmb,gost,vx',
            'public_entry_port' => 'required|integer|min:1|max:65535',
            'target_ip' => 'required|ip',
            'target_port' => 'required|integer|min:1|max:65535',
            'provider_rule_ref' => 'nullable|string|max:255',
        ]);
        // Capacity guardrail (D06 §6.3): csmzzmb registrations stop at the
        // stop threshold; the warn threshold raises its alert via evaluate().
        if ($params['provider'] === EnterprisePurpose::PROVIDER_CSMZZMB) {
            try {
                $capacity->assertCanRegister();
            } catch (\Throwable $e) {
                return $this->fail([422, $e->getMessage()]);
            }
        }
        $exists = EnterpriseInventoryRule::where('provider', $params['provider'])
            ->where('public_entry_port', $params['public_entry_port'])
            ->exists();
        if ($exists) {
            return $this->fail([422, 'a rule for this provider + entry port already exists']);
        }
        $rule = EnterpriseInventoryRule::create([
            ...$params,
            'status' => EnterpriseInventoryRule::STATUS_READY,
        ]);
        if ($params['provider'] === EnterprisePurpose::PROVIDER_CSMZZMB) {
            $capacity->evaluate();
        }
        return $this->success($rule);
    }

    public function rules(Request $request)
    {
        $rules = EnterpriseInventoryRule::query()
            ->when($request->input('purpose_id'), fn($q) => $q->where('purpose_id', $request->input('purpose_id')))
            ->when($request->input('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderBy('id')
            ->get();
        return $this->success($rules);
    }

    public function verify(Request $request, EnterpriseInventoryHealthService $health)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $kit = EnterpriseInventoryKit::find($request->input('kit_id'));
        if (!$kit) {
            return $this->fail([404, 'kit not found']);
        }
        $mode = $request->input('mode') === 'light' ? 'light' : 'full';
        return $this->success($health->verifyKit($kit, $mode));
    }

    public function rebuild(Request $request, EnterpriseKitBuilderService $builder)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $kit = EnterpriseInventoryKit::find($request->input('kit_id'));
        if (!$kit) {
            return $this->fail([404, 'kit not found']);
        }
        try {
            $kit = $builder->rebuildKit($kit);
        } catch (\Throwable $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($this->presentKit($kit->load(['purpose', 'nodes.server', 'group'])));
    }

    public function revoke(Request $request, EnterpriseAssignmentService $assignment)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $kit = EnterpriseInventoryKit::find($request->input('kit_id'));
        if (!$kit) {
            return $this->fail([404, 'kit not found']);
        }
        try {
            $assignment->revokeAssignment($kit, $request->user()?->id);
        } catch (\Throwable $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success(true);
    }

    public function registerLegacy(Request $request, EnterpriseAssignmentService $assignment)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $params = $request->validate([
            'user_id' => 'required|integer',
            'purpose_id' => 'required|integer',
            'group_id' => 'required|integer',
            'server_ids' => 'required|array|size:2',
            'server_ids.*' => 'integer',
        ]);
        $user = User::find($params['user_id']);
        $purpose = EnterprisePurpose::find($params['purpose_id']);
        if (!$user || !$purpose) {
            return $this->fail([404, 'user or purpose not found']);
        }
        try {
            $kit = $assignment->registerLegacy($user, $purpose, $params['group_id'], $params['server_ids']);
        } catch (\Throwable $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($this->presentKit($kit->load(['purpose', 'nodes.server', 'group'])));
    }

    public function alerts(Request $request)
    {
        $alerts = EnterpriseInventoryAlert::query()
            ->when($request->input('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        return $this->success($alerts);
    }

    public function ackAlert(Request $request)
    {
        if (!$this->requireConfirm($request)) {
            return $this->fail([422, 'write operations require confirm=true']);
        }
        $alert = EnterpriseInventoryAlert::find($request->input('id'));
        if (!$alert) {
            return $this->fail([404, 'alert not found']);
        }
        $alert->status = $request->input('resolve')
            ? EnterpriseInventoryAlert::STATUS_RESOLVED
            : EnterpriseInventoryAlert::STATUS_ACKED;
        if ($request->input('resolve')) {
            $alert->resolved_at = now();
        }
        $alert->save();
        return $this->success(true);
    }

    public function intents(Request $request)
    {
        $intents = EnterpriseOrderIntent::with(['purpose', 'user:id,email'])
            ->when($request->input('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        return $this->success($intents);
    }
}
