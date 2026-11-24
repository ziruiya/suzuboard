<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\MachineFulfillment;
use App\Models\Plan;
use App\Services\Machine\MachineFulfillmentService;
use App\Services\Machine\MachineRegionCatalog;
use App\Services\Machine\PortRotationCooldownException;
use App\Services\Machine\PortRotationException;
use App\Services\Machine\PortRotationService;
use Illuminate\Http\Request;

/**
 * Business plans batch 3: the member-center surface shared by both
 * business tiers — region catalog for the dedicated-VPS checkout,
 * business status (drives the 「更换端口」 button), the rotation itself,
 * and the customer's answer to an SLA-overdue notice.
 */
class BusinessController extends Controller
{
    /** Regions + which plans need one (the checkout picker). */
    public function machineRegions(Request $request)
    {
        $plans = Plan::where('machine_region_required', 1)
            ->get(['id', 'name', 'show', 'sell'])
            ->map(fn(Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'on_sale' => (bool) ($p->show && $p->sell),
            ]);
        return $this->success([
            'regions' => MachineRegionCatalog::all(),
            'plans' => $plans,
        ]);
    }

    public function status(Request $request)
    {
        $user = $request->user();
        $rotation = app(PortRotationService::class)->statusForUser($user);

        $machine = null;
        $fulfillment = $user->machine_fulfillment_id
            ? MachineFulfillment::find($user->machine_fulfillment_id)
            : app(MachineFulfillmentService::class)->activeForUser((int) $user->id);
        if ($fulfillment) {
            $machine = [
                'fulfillment_id' => $fulfillment->id,
                'status' => $fulfillment->status,
                'region_name' => $fulfillment->region_name,
                'sla_overdue' => (bool) $fulfillment->sla_overdue,
                'awaiting_choice' => (bool) ($fulfillment->sla_overdue && $fulfillment->isInFlight() && !$fulfillment->user_choice),
                'delivered_at' => $fulfillment->delivered_at?->toDateTimeString(),
                'grace_until' => $fulfillment->grace_until?->toDateTimeString(),
            ];
        }

        return $this->success(array_merge($rotation, ['machine' => $machine]));
    }

    public function rotatePort(Request $request)
    {
        try {
            $rotated = app(PortRotationService::class)->rotateForUser($request->user());
        } catch (PortRotationCooldownException $e) {
            return $this->fail([42900, $e->getMessage()]);
        } catch (PortRotationException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success(['rotated' => $rotated]);
    }

    /** The customer's answer to the overdue notice (WAIT_ORIGINAL | SWITCH_REGION). */
    public function choice(Request $request)
    {
        $request->validate([
            'fulfillment_id' => 'required|integer',
            'choice' => 'required|string|in:WAIT_ORIGINAL,SWITCH_REGION',
            'region' => 'nullable|string|max:8',
        ]);
        $fulfillment = MachineFulfillment::find($request->input('fulfillment_id'));
        if (!$fulfillment) {
            return $this->fail([404, 'Fulfilment not found']);
        }
        try {
            $fulfillment = app(MachineFulfillmentService::class)->recordUserChoice(
                $fulfillment,
                $request->user(),
                (string) $request->input('choice'),
                $request->input('region')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        return $this->success([
            'fulfillment_id' => $fulfillment->id,
            'status' => $fulfillment->status,
            'region_code' => $fulfillment->region_code,
            'sla_overdue' => (bool) $fulfillment->sla_overdue,
        ]);
    }
}
