<?php

namespace App\Console\Commands;

use App\Services\Log0\Mappings;
use App\Services\Log0\Rollups;
use Illuminate\Console\Command;

class Log0Maintain extends Command
{
    protected $signature = 'log0:maintain {task : events, daily, candidates, or retention}';
    protected $description = 'Maintain independent LOG-0 diagnostics';

    public function handle(Rollups $rollups, Mappings $mappings): int
    {
        switch ($this->argument('task')) {
            case 'events':
                $rollups->events();
                break;
            case 'daily':
                $rollups->daily();
                break;
            case 'candidates':
                $mappings->candidates();
                break;
            case 'retention':
                $rollups->retention();
                break;
            default:
                return self::INVALID;
        }

        return self::SUCCESS;
    }
}
