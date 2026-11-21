<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised for endpoint-recovery operations that must not proceed:
 * optimistic-lock conflicts, missing Cloudflare credentials, failed
 * cutover read-backs, illegal rollbacks.
 */
class SuzuEndpointException extends Exception
{
}
