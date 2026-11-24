<?php

namespace App\Services\Machine;

/**
 * Network edge for dedicated-VPS delivery verification. The production
 * implementation is a plain TCP reachability check against the machine's
 * public IP; tests bind a fake.
 */
interface MachineNodeProbe
{
    public function tcpReachable(string $host, int $port): bool;
}

class TcpMachineNodeProbe implements MachineNodeProbe
{
    public function tcpReachable(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 3.0);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }
}
