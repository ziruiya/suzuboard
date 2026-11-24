<?php

namespace App\Utils;

/**
 * Strict decimal-amount parsing for payment-callback reconciliation
 * (SB-AUD-002/030). Gateway callbacks report amounts as decimal strings
 * in major units ("29.00"); order totals are integer cents. Parsing is
 * string-based so no float ever enters the comparison.
 */
class MoneyAmount
{
    /**
     * Parse a major-unit decimal ("29", "29.5", "29.00") into integer
     * cents. Returns null for anything malformed (sign, exponent, more
     * than 2 fraction digits, non-numeric) so callers can fail closed.
     */
    public static function majorToCents($value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $fraction = str_pad($m[2] ?? '', 2, '0');
        return (int) $m[1] * 100 + (int) $fraction;
    }

    /**
     * Parse an amount that is already denominated in integer cents.
     * Returns null unless the value is a non-negative integer literal.
     */
    public static function cents($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }
}
