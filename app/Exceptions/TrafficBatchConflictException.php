<?php

namespace App\Exceptions;

use Exception;

/**
 * SB-TA-B01: a report re-used an already-received batch id with
 * DIFFERENT content. Raised at report time (synchronously, before any
 * job is dispatched) so the V2 report endpoint can refuse the batch
 * with an HTTP error instead of acknowledging it with 200 and only
 * discovering the conflict later inside an asynchronous job.
 */
class TrafficBatchConflictException extends Exception
{
    public function __construct(
        public readonly int $serverId,
        public readonly string $batchId,
    ) {
        parent::__construct("traffic batch conflict: server {$serverId} batch {$batchId} was already received with different content");
    }
}
