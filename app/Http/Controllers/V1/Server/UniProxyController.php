<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UniProxyController extends Controller
{
    public function __construct(
        private readonly DeviceStateService $deviceStateService
    ) {
    }

    private function getNodeInfo(Request $request)
    {
        return $request->attributes->get('node_info');
    }

    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        $node = $this->getNodeInfo($request);

        ServerService::touchNode($node);

        $response = $this->snapshot($request, 'sync.users');

        $eTag = sha1(json_encode($response));
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    public function push(Request $request)
    {
        $res = json_decode(request()->getContent(), true);
        if (!is_array($res)) {
            return $this->fail([422, 'Invalid data format']);
        }

        $node = $this->getNodeInfo($request);

        ServerService::processTraffic($node, $res);

        return $this->success(true);
    }

    public function config(Request $request)
    {
        $snapshot = $this->snapshot($request, 'sync.config');
        $response = $snapshot['config'] + ['state_revision' => $snapshot['state_revision']];

        $eTag = sha1(json_encode($response));
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }
        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    private function snapshot(Request $request, string $event): array
    {
        $machine = $request->attributes->get('machine_info');
        $snapshot = NodeSyncService::snapshot(
            $this->getNodeInfo($request)->id,
            $event,
            fn (Server $node) => $machine
                ? $node->machine_id === $machine->id && ServerService::isMachineDeliverable($node)
                : ServerService::isAgentDeliverable($node),
        );
        abort_if($snapshot === null, 404);

        return $snapshot;
    }

    public function alivelist(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $deviceLimitUsers = ServerService::getAvailableUsers($node)
            ->where('device_limit', '>', 0);

        $alive = $this->deviceStateService->getAliveList(collect($deviceLimitUsers));

        return response()->json(['alive' => (object) $alive]);
    }

    public function alive(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return response()->json(['error' => 'Invalid online data'], 400);
        }

        $complete = ($data['alive_complete'] ?? false) === true;
        $alive = $data['alive'] ?? array_diff_key($data, ['alive_complete' => true]);
        if (!is_array($alive)) {
            return response()->json(['error' => 'Invalid online data'], 400);
        }

        ServerService::processAlive($node->id, $alive, $complete);

        return response()->json(['data' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);

        $data = $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'required|integer|min:0',
            'swap.used' => 'required|integer|min:0',
            'disk.total' => 'required|integer|min:0',
            'disk.used' => 'required|integer|min:0',
        ]);

        ServerService::processStatus($node, $data);

        return response()->json(['data' => true, 'code' => 0, 'message' => 'success']);
    }
}
