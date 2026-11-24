<?php

namespace App\Services\Relay;

/**
 * SB-RELAY-01: a relay write failed validation (port pool, claims, loop,
 * target eligibility, allowlist…). Controllers map it to HTTP 422.
 */
class RelayValidationException extends \RuntimeException
{
}
