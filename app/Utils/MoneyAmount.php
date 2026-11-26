<?php

namespace App\Utils;

/**
 * Strict decimal-amount parsing for payment-callback reconciliation
 * (SB-AUD-002/030). Gateway callbacks report amounts as decimal strings
 * in major units ("29.00"); order totals are integer cents. Parsing is
 * string-based so no float ever enters the comparison.
 *
 * SB-MONEY-017: every conversion validates non-negativity and the
 * representable range BEFORE any multiplication, so an out-of-range
 * input is rejected as invalid (null) instead of silently saturating
 * to PHP_INT_MAX or promoting to a float that no longer fits the
 * integer-cents domain.
 */
class MoneyAmount
{
    /** Largest major-unit value whose cents still fit an int. */
    private static function maxMajor(): int
    {
        return intdiv(PHP_INT_MAX - 99, 100);
    }

    /**
     * Parse a major-unit decimal ("29", "29.5", "29.00") into integer
     * cents. Returns null for anything malformed (sign, exponent, more
     * than 2 fraction digits, non-numeric) or out of range, so callers
     * can fail closed.
     */
    public static function majorToCents($value): ?int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > self::maxMajor()) {
                return null;
            }
            return $value * 100;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $majorDigits = ltrim($m[1], '0');
        if (strlen($majorDigits) > strlen((string) self::maxMajor())) {
            return null;
        }
        $major = (int) $m[1];
        if ($major > self::maxMajor()) {
            return null;
        }
        $fraction = str_pad($m[2] ?? '', 2, '0');
        return $major * 100 + (int) $fraction;
    }

    /**
     * Parse an amount that is already denominated in integer cents.
     * Returns null unless the value is a non-negative integer literal
     * that fits an int.
     */
    public static function cents($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $digits = ltrim(trim($value), '0');
            $max = (string) PHP_INT_MAX;
            if (strlen($digits) > strlen($max)
                || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
                return null;
            }
            return (int) trim($value);
        }
        return null;
    }

    /**
     * SB-MONEY-011: convert a configured plan price (major units, as
     * stored in the plan's prices JSON — int, float or numeric string)
     * into integer cents. The previous `(int) ($price * 100)` cast
     * truncated through binary floating point (19.99 became 1998).
     * Prices are money: they are rounded half-up to exactly 2 fraction
     * digits via decimal string formatting, then parsed strictly.
     * Returns null for negative, non-finite or absurd inputs.
     */
    public static function priceToCents($value): ?int
    {
        if (is_int($value)) {
            return self::majorToCents($value);
        }
        if (is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $float = (float) $value;
            if (!is_finite($float) || $float < 0 || $float > 9.0e15) {
                return null;
            }
            return self::majorToCents(number_format($float, 2, '.', ''));
        }
        return null;
    }

    /**
     * SB-MONEY-011: integer percentage of an integer-cent amount,
     * floored (a discount/commission never rounds up against the
     * house). Replaces the float multiplications that used to feed
     * fractional cents into integer columns, where they were silently
     * truncated at varying layers. Negative inputs yield 0.
     */
    public static function percentOf(int $amountCents, $percent): int
    {
        if ($amountCents <= 0 || !is_numeric($percent)) {
            return 0;
        }
        $percent = $percent + 0;
        if ($percent <= 0) {
            return 0;
        }
        if (is_int($percent) && $amountCents <= intdiv(PHP_INT_MAX, $percent)) {
            return intdiv($amountCents * $percent, 100);
        }
        $result = floor($amountCents * ((float) $percent) / 100);
        if ($result >= (float) PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        return (int) $result;
    }
}
