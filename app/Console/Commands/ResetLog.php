<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Console\Command;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        StatUser::where('record_at', '<', strtotime('-2 month', time()))->delete();
        StatServer::where('record_at', '<', strtotime('-2 month', time()))->delete();
        AdminAuditLog::where('created_at', '<', strtotime('-3 month', time()))->delete();
        // SB-AUD-022: prune traffic batch dedup markers after 48 hours;
        // realistic replays (agent retry, queue redelivery) land within
        // minutes, so the window only needs to cover those.
        //
        // SB-TA-B01-R1: the same table also holds the durable inbox
        // (SB-TA-B01, processor = 'inbox'). An inbox row that is not
        // yet 'processed' still carries the ONLY copy of a received
        // batch's payload — the agent has already discarded its copy
        // on the strength of the panel's HTTP 200 — so it must never
        // be pruned by age: if the accounting queue is down for longer
        // than 48 hours, deleting it would lose the batch permanently
        // and suzu:traffic-batch-reconcile would have nothing left to
        // re-drive. Only processed inbox rows follow the 48-hour
        // retention, exactly like claim/legacy marker rows.
        //
        // Delete predicate: created_at aged out AND NOT (an unprocessed
        // inbox row), where "unprocessed inbox row" is decided by
        // processor alone plus a status check: processor = 'inbox' AND
        // (status IS NULL OR status <> 'processed'). Claim rows are
        // never processor = 'inbox', so their NULL status (pre-000010
        // rows) can never be mistaken for an unprocessed inbox row.
        //
        // SB-TA-B01-R1-C1: the protection is per batch FAMILY, not per
        // row. Every row of one chunk shares the same batch_hash (the
        // key encodes node + agent batch id + chunk sequence): the
        // inbox row plus one claim row per accounting processor
        // (traffic_fetch / stat_user / stat_server). The claims are
        // the idempotency mechanism reconcile relies on — if a claim
        // aged out while its inbox was still unprocessed, reconcile
        // would re-drive the batch and the processor whose claim was
        // deleted would book it a SECOND time (the same holds when all
        // three claims exist but the process crashed before
        // noteBatchProcessed() flipped the inbox). So an aged row is
        // deleted only when its whole family is finished:
        //
        // Delete predicate (family form): created_at aged out AND the
        // row's batch_hash has NO unprocessed inbox row (processor =
        // 'inbox' AND (status IS NULL OR status <> 'processed')).
        // Families whose inbox is processed, and claim/legacy families
        // with no inbox row at all, follow the 48-hour retention as
        // before.
        //
        // Implementation (SB-P2 INBOX-PLUCK): the exclusion is a
        // subquery, not a pluck. The previous shape loaded every
        // unprocessed inbox batch_hash into PHP memory and bound the
        // whole set as NOT IN (?, ?, ...) — the stranded set is tiny
        // by construction, but "tiny" is an operational assumption,
        // not a bound: a long accounting outage could strand enough
        // batches to make the in-memory set and the placeholder list
        // the prune's own failure mode. The subquery form keeps the
        // set inside the database at any size. The inner select is
        // wrapped in a derived table because MySQL refuses a DELETE
        // whose subquery reads the target table directly (error
        // 1093); the derived-table form materializes the key set
        // first and is accepted by MySQL, PostgreSQL and SQLite.
        //
        // Fallback: when the status column does not exist (migration
        // 000010 not applied) there is no inbox and therefore no
        // unprocessed family, so the original unconditional age prune
        // below applies unchanged.
        if (\Illuminate\Support\Facades\Schema::hasTable('suzu_traffic_batches')) {
            $prune = \Illuminate\Support\Facades\DB::table('suzu_traffic_batches')
                ->where('created_at', '<', time() - 172800);
            if (\Illuminate\Support\Facades\Schema::hasColumn('suzu_traffic_batches', 'status')) {
                $prune->whereNotIn('batch_hash', function ($query) {
                    $query->select('batch_hash')->fromSub(function ($sub) {
                        $sub->from('suzu_traffic_batches')
                            ->select('batch_hash')
                            ->where('processor', \App\Services\TrafficBatchGuard::PROCESSOR_INBOX)
                            ->where(function ($inner) {
                                $inner->whereNull('status')
                                    ->orWhere('status', '<>', \App\Services\TrafficBatchGuard::STATUS_PROCESSED);
                            });
                    }, 'protected_families');
                });
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('suzu_traffic_batches', 'receipt_hash')) {
                // Retain the full batch receipt while any of its chunks
                // still needs recovery or manual inspection.
                $prune->whereNotIn('batch_hash', function ($query) {
                    $query->select('receipt_hash')->fromSub(function ($sub) {
                        $sub->from('suzu_traffic_batches')
                            ->select('receipt_hash')
                            ->whereNotNull('receipt_hash')
                            ->where('processor', \App\Services\TrafficBatchGuard::PROCESSOR_INBOX)
                            ->where('status', '<>', \App\Services\TrafficBatchGuard::STATUS_PROCESSED);
                    }, 'protected_receipts');
                });
            }
            $prune->delete();
        }
    }
}
