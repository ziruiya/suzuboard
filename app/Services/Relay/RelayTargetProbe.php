<?php

namespace App\Services\Relay;

/**
 * Shared TCP reachability probing for relay target validation.
 *
 * One probe standard for every gate that asks "can this target be
 * reached": the public-address change precheck (B03) and the rollback
 * reachability gate (R12 acceptance rides on the same standard, per
 * GPT round 2 item B04 — no separate rollback-only mechanism).
 *
 * A prober is fn(string $host, int $port, float $timeoutSeconds): bool —
 * injectable so tests never touch a real network. The probe is a TCP
 * connect ONLY: it proves a listener accepts a connection, not that a
 * full proxy handshake succeeds. Protocol-level proof comes from the
 * publish + agent ACK + live traffic path and is reported separately;
 * nothing in this class may be cited as handshake evidence.
 */
class RelayTargetProbe
{
    /** The production prober: a real TCP connect via fsockopen. */
    public static function tcp(): \Closure
    {
        return static function (string $host, int $port, float $timeout): bool {
            $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if ($fp === false) {
                return false;
            }
            fclose($fp);
            return true;
        };
    }

    /**
     * Probe EVERY port in the list (no sampling, no any-pass shortcut):
     * an address change retargets all of them, so all of them must
     * answer. Returns ['passed' => int[], 'failed' => int[]] preserving
     * the input order.
     */
    public static function probeAllPorts(callable $prober, string $host, array $ports, float $timeout = 2.0): array
    {
        $passed = [];
        $failed = [];
        foreach ($ports as $port) {
            if ($prober($host, (int) $port, $timeout)) {
                $passed[] = (int) $port;
            } else {
                $failed[] = (int) $port;
            }
        }
        return ['passed' => $passed, 'failed' => $failed];
    }

    /**
     * Probe a list of ['host' => string, 'port' => int, 'label' => string]
     * endpoints; returns the subset that did NOT answer, in input order.
     */
    public static function probeEndpoints(callable $prober, array $endpoints, float $timeout = 2.0): array
    {
        $failed = [];
        foreach ($endpoints as $endpoint) {
            if (!$prober((string) $endpoint['host'], (int) $endpoint['port'], $timeout)) {
                $failed[] = $endpoint;
            }
        }
        return $failed;
    }
}
