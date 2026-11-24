<?php

namespace App\Services\Machine;

class PortRotationCooldownException extends PortRotationException
{
    public function __construct(public int $remainingSeconds)
    {
        parent::__construct('Port rotation is on cooldown; try again in ' . (int) ceil($remainingSeconds / 60) . ' minutes');
    }
}
