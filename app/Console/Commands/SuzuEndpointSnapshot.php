<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\SuzuEndpointContract;
use App\Services\SuzuEndpointContractService;
use Illuminate\Console\Command;

/**
 * Generate endpoint contract snapshots from live nodes, or verify all
 * active contracts against live node state.
 *
 *   php artisan suzu:endpoint-snapshot --all
 *   php artisan suzu:endpoint-snapshot 27
 *   php artisan suzu:endpoint-snapshot --verify
 */
class SuzuEndpointSnapshot extends Command
{
    protected $signature = 'suzu:endpoint-snapshot {server? : v2_server id} {--all} {--verify}';

    protected $description = 'Snapshot endpoint contracts from nodes, or verify contracts against live nodes';

    public function handle(SuzuEndpointContractService $service): int
    {
        if ($this->option('verify')) {
            return $this->verifyAll($service);
        }

        $servers = collect();
        if ($this->option('all')) {
            $servers = Server::orderBy('id')->get();
        } elseif ($this->argument('server')) {
            $server = Server::find((int) $this->argument('server'));
            if (!$server) {
                $this->error('server not found: ' . $this->argument('server'));
                return self::FAILURE;
            }
            $servers = collect([$server]);
        } else {
            $this->error('pass a server id or --all');
            return self::FAILURE;
        }

        foreach ($servers as $server) {
            $result = $service->snapshotFromServer($server);
            $contract = $result['contract'];
            $line = "node {$server->id} ({$server->host}) -> contract #{$contract->id} v{$contract->version} hash " . substr($contract->contract_hash, 0, 12);
            if ($result['alarm']) {
                $this->warn($line . ' ALARM drift: ' . implode(', ', array_keys($result['drift'])));
            } elseif ($result['created']) {
                $this->info($line . ' created');
            } else {
                $this->line($line . ' unchanged');
            }
        }
        return self::SUCCESS;
    }

    private function verifyAll(SuzuEndpointContractService $service): int
    {
        $contracts = SuzuEndpointContract::where('status', SuzuEndpointContract::STATUS_ACTIVE)->get();
        $bad = 0;
        foreach ($contracts as $contract) {
            $result = $service->verifyIntegrity($contract);
            if ($result['ok']) {
                $this->line("contract #{$contract->id} node {$contract->logical_node_id}: OK");
            } else {
                $bad++;
                $this->warn("contract #{$contract->id} node {$contract->logical_node_id}: DRIFT " . implode(', ', array_keys($result['drift'])));
            }
        }
        $this->info("verified {$contracts->count()} active contracts, {$bad} drifted");
        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
