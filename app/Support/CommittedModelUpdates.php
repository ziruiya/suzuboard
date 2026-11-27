<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionCommitted;
use WeakMap;

class CommittedModelUpdates
{
    private WeakMap $pending;
    private int $sequence = 0;

    public function __construct()
    {
        $this->pending = new WeakMap();
    }

    public function record(Model $model, array $original, array $changed, callable $notify): void
    {
        $connection = $model->getConnection();
        $id = $model->getKey();
        $key = $model::class . ':' . $id;
        $sequence = ++$this->sequence;
        $readFinal = fn () => $model->newQuery()->useWritePdo()->find($id);
        $registering = true;

        $connection->afterCommit(function () use (
            $connection, $key, $sequence, $original, $changed, $notify, $readFinal, &$registering
        ) {
            if ($registering) {
                if ($final = $readFinal()) {
                    $notify($final, $original, $changed);
                }
                return;
            }

            // Only committed savepoints contribute. Laravel can run an inner
            // savepoint's callbacks before earlier callbacks on the outer transaction.
            $updates = $this->pending[$connection] ?? [];
            $previous = $updates[$key] ?? null;
            $updates[$key] = [
                'sequence' => min($sequence, $previous['sequence'] ?? $sequence),
                'original' => $previous && $previous['sequence'] < $sequence ? $previous['original'] : $original,
                'changed' => $changed + ($previous['changed'] ?? []),
                'notify' => $notify,
                'readFinal' => $readFinal,
            ];
            $this->pending[$connection] = $updates;
        });

        $registering = false;
    }

    public function flush(TransactionCommitted $event): void
    {
        $updates = $this->pending[$event->connection] ?? [];
        unset($this->pending[$event->connection]);

        foreach ($updates as $update) {
            if ($final = ($update['readFinal'])()) {
                ($update['notify'])($final, $update['original'], $update['changed']);
            }
        }
    }
}
