<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\WebSocket\NodeWorker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    /**
     * server handshake api
     */
    public function handshake(Request $request): JsonResponse
    {
        $websocket = ['enabled' => false];

        if ((bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY)) {
            $customUrl = trim((string) admin_setting('server_ws_url', ''));

            if ($customUrl !== '') {
                $wsUrl = rtrim($customUrl, '/');
            } else {
                $wsScheme = $request->isSecure() ? 'wss' : 'ws';
                $wsUrl = "{$wsScheme}://{$request->getHttpHost()}/ws";
            }

            $websocket = [
                'enabled' => true,
                'ws_url' => $wsUrl,
            ];
        }

        return response()->json([
            'websocket' => $websocket
        ]);
    }

    /**
     * node report api - merge traffic + alive + status + metrics
     */
    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');

        ServerService::touchNode($node);

        $traffic = $request->input('traffic');
        if (is_array($traffic) && !empty($traffic)) {
            // SB-TA-001/002/004: agents on the batch-id protocol attach
            // one envelope per report; legacy agents omit it and the
            // panel falls back to the AUD-022 content-hash dedup.
            $batchMeta = null;
            $envelope = $request->input('traffic_batch');
            if (is_array($envelope) && isset($envelope['batch_id']) && is_string($envelope['batch_id']) && $envelope['batch_id'] !== '') {
                $batchMeta = [
                    'batch_id' => $envelope['batch_id'],
                    'collected_from' => isset($envelope['collected_from']) ? (int) $envelope['collected_from'] : null,
                    'collected_to' => isset($envelope['collected_to']) ? (int) $envelope['collected_to'] : null,
                ];
            }
            ServerService::processTraffic($node, $traffic, $batchMeta);
        }

        $alive = $request->input('alive');
        if (is_array($alive) && !empty($alive)) {
            ServerService::processAlive($node->id, $alive);
        }

        $online = $request->input('online');
        if (is_array($online) && !empty($online)) {
            ServerService::processOnline($node, $online);
        }

        $status = $request->input('status');
        if (is_array($status) && !empty($status)) {
            ServerService::processStatus($node, $status);
        }

        $metrics = $request->input('metrics');
        if (is_array($metrics) && !empty($metrics)) {
            ServerService::updateMetrics($node, $metrics);
        }

        return response()->json(['data' => true]);
    }
}
