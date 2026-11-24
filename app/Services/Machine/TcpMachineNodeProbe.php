<?php

namespace App\Services\Machine;

/** Production MachineNodeProbe: plain TCP reachability, 3s timeout. */
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
