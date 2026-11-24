<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MachineFulfillment;
use App\Models\MachineFulfillmentLog;
use App\Services\Machine\MachineFulfillmentService;
use App\Services\Machine\MachineNodeProvisioner;
use Illuminate\Http\Request;

/**
 * Business plans batch 3: the admin 「待处理订单」 surface for the
 * dedicated-VPS plan. The admin buys the VPS by hand, backfills its IP
 * and vendor facts here; provisioning, verification, delivery, SLA and
 * reclaim are system-driven (MachineFulfillmentService). Writes require
 * confirm=true and are fully logged on the fulfilment.
 */
class MachineFulfillmentController extends Controller
{
    public function __construct(private MachineFulfillmentService $service)
    {
    }

    /** Static purchasing guidance shown on the page (D07). */
    public const PURCHASE_HINTS = [
        'min_spec' => '最低规格：1 vCPU / 1 GiB 内存 / 10 GiB 磁盘 / 1 个公网 IPv4',
        'cost_cap' => '采购成本上限 ¥100/月；超过上限的地区暂停销售该地区整机（在套餐与地区上架时控制）',
        'flow' => '回填公网 IP 后系统自动预注册机器并生成安装命令；把命令粘贴到 VPS 执行，agent 连入后自动建组、建 3 条专线并验收交付',
    ];

    public function queue(Request $request)
    {
        $items = MachineFulfillment::with(['user:id,email', 'order:id,trade_no', 'plan:id,name'])
            ->whereNotIn('status', [MachineFulfillment::STATUS_CANCELLED, MachineFulfillment::STATUS_RECLAIMED])
            ->orderByRaw('sla_overdue desc, termination_review desc, delivery_deadline_at asc, id asc')
            ->get()
            ->map(fn(MachineFulfillment $f) => $this->row($f));
        return $this->success([
            'items' => $items,
            'purchase_hints' => self::PURCHASE_HINTS,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $f = MachineFulfillment::with(['user:id,email', 'order:id,trade_no', 'plan:id,name', 'machine', 'group'])->find($id);
        if (!$f) {
            return $this->fail([404, 'Fulfilment not found']);
        }
        $data = $this->row($f);
        $data['vendor_instance_id'] = $f->vendor_instance_id;
        $data['vendor_name'] = $f->vendor_name;
        $data['purchase_note'] = $f->purchase_note;
        $data['admin_note'] = $f->admin_note;
        $data['verify_report'] = $f->verify_report ? json_decode($f->verify_report, true) : null;
        $data['reclaim_checklist'] = $f->reclaim_checklist;
        $data['reclaim_steps'] = MachineFulfillment::RECLAIM_STEPS;
        $data['machine'] = $f->machine ? [
            'id' => $f->machine->id,
            'name' => $f->machine->name,
            'is_active' => (bool) $f->machine->is_active,
            'last_seen_at' => $f->machine->last_seen_at,
        ] : null;
        $data['install_command'] = $f->machine
            ? MachineNodeProvisioner::installCommand($f->machine)
            : null;
        $data['logs'] = MachineFulfillmentLog::where('fulfillment_id', $f->id)
            ->orderBy('id')->get(['id', 'actor_type', 'actor_id', 'event', 'detail', 'created_at']);
        $data['purchase_hints'] = self::PURCHASE_HINTS;
        return $this->success($data);
    }

    public function startPurchase(Request $request, int $id)
    {
        return $this->mutate($request, $id, fn(MachineFulfillmentService $s, MachineFulfillment $f) => $s->startPurchase($f, $this->adminId($request)));
    }

    public function markBlocked(Request $request, int $id)
    {
        return $this->mutate($request, $id, fn(MachineFulfillmentService $s, MachineFulfillment $f) => $s->markBlocked($f, $request->input('note'), $this->adminId($request)));
    }

    public function backfillIp(Request $request, int $id)
    {
        return $this->mutate($request, $id, fn(MachineFulfillmentService $s, MachineFulfillment $f) => $s->backfillIp($f, [
            'public_ip' => $request->input('public_ip'),
            'vendor_instance_id' => $request->input('vendor_instance_id'),
            'vendor_name' => $request->input('vendor_name'),
            'purchase_note' => $request->input('purchase_note'),
            'resource_expires_at' => $request->input('resource_expires_at'),
        ], $this->adminId($request)));
    }

    public function retryVerify(Request $request, int $id)
    {
        return $this->mutate($request, $id, fn(MachineFulfillmentService $s, MachineFulfillment $f) => $s->retryVerify($f, $this->adminId($request)));
    }

    public function suspend(Request $request, int $id)
    {
        return $this->mutate($request, $id, fn(MachineFulfillmentService $s, MachineFulfillment $f) => $s->suspend($f, $this->adminId($request)));
    }

    public function resume(Request $request, int $id)
    {
        return $this->mutate($request, $id, fn(MachineFulfillmentService $s, MachineFulfillment $f) => $s->resume($f, $this->adminId($request)));
    }

    public function reclaimStep(Request $request, int $id)
    {
        return $this->mutate($request, $id, fn(MachineFulfillmentService $s, MachineFulfillment $f) => $s->reclaimStep($f, (string) $request->input('step'), $this->adminId($request), $request->input('note')));
    }

    private function row(MachineFulfillment $f): array
    {
        $remaining = null;
        if ($f->isInFlight() && $f->delivery_deadline_at) {
            $remaining = now()->diffInSeconds($f->delivery_deadline_at, false);
        }
        return [
            'id' => $f->id,
            'order_id' => $f->order_id,
            'trade_no' => $f->order?->trade_no,
            'user_id' => $f->user_id,
            'user_email' => $f->user?->email,
            'plan_name' => $f->plan?->name,
            'region_code' => $f->region_code,
            'region_name' => $f->region_name,
            'status' => $f->status,
            'paid_at' => $f->paid_at?->toDateTimeString(),
            'delivery_deadline_at' => $f->delivery_deadline_at?->toDateTimeString(),
            'sla_remaining_seconds' => $remaining,
            'sla_overdue' => (bool) $f->sla_overdue,
            'termination_review' => (bool) $f->termination_review,
            'user_choice' => $f->user_choice,
            'public_ip' => $f->public_ip,
            'machine_id' => $f->machine_id,
            'machine_name' => $f->machine?->name,
            'resource_expires_at' => $f->resource_expires_at?->toDateTimeString(),
            'lease_expiring_soon' => $f->status === MachineFulfillment::STATUS_DELIVERED
                && $f->resource_expires_at && $f->resource_expires_at->lte(now()->addDays(7)),
            'delivered_at' => $f->delivered_at?->toDateTimeString(),
            'grace_until' => $f->grace_until?->toDateTimeString(),
        ];
    }

    private function mutate(Request $request, int $id, callable $fn)
    {
        if (!$request->boolean('confirm')) {
            return $this->fail([422, 'Write operations require confirm=true']);
        }
        $f = MachineFulfillment::find($id);
        if (!$f) {
            return $this->fail([404, 'Fulfilment not found']);
        }
        try {
            $f = $fn($this->service, $f);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success($this->row($f->fresh()));
    }

    private function adminId(Request $request): ?int
    {
        return $request->user()?->id;
    }
}
