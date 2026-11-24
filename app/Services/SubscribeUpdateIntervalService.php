<?php

namespace App\Services;

/**
 * Resolves the effective subscription auto-update interval (hours) that is
 * advertised to Clash-family clients via the `profile-update-interval`
 * response header (SB-SUB-INTERVAL v1.0).
 *
 * Source of truth is the global admin setting
 * `subscribe_update_interval_hours` (default 6, valid range 1–72).
 *
 * Failure semantics required by the spec:
 *  - a valid stored value wins and is remembered as the last valid value;
 *  - when the stored value is missing/invalid, or the settings read itself
 *    fails, the last valid value is used;
 *  - when no valid value has ever been seen, fall back to the default (6).
 */
class SubscribeUpdateIntervalService
{
    public const SETTING_KEY = 'subscribe_update_interval_hours';
    public const DEFAULT_HOURS = 6;
    public const MIN_HOURS = 1;
    public const MAX_HOURS = 72;

    private const LAST_VALID_CACHE_KEY = 'subscribe_update_interval:last_valid_hours';

    /** Last valid value seen by this process. */
    private static ?int $lastValid = null;

    /**
     * Normalize a raw stored value to a valid interval, or null when the
     * value is not an integer inside the allowed range.
     */
    public static function normalize(mixed $value): ?int
    {
        if (is_int($value)) {
            $hours = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            $hours = (int) trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $hours = (int) $value;
        } else {
            return null;
        }

        return ($hours >= self::MIN_HOURS && $hours <= self::MAX_HOURS) ? $hours : null;
    }

    /**
     * The interval to advertise right now.
     */
    public function hours(): int
    {
        try {
            $raw = admin_setting(self::SETTING_KEY);
        } catch (\Throwable) {
            $raw = null;
        }

        $valid = self::normalize($raw);
        if ($valid !== null) {
            self::$lastValid = $valid;
            try {
                \Illuminate\Support\Facades\Cache::store('redis')
                    ->forever(self::LAST_VALID_CACHE_KEY, $valid);
            } catch (\Throwable) {
                // Cache backend unavailable: the in-process value still
                // serves this request, and the default covers cold starts.
            }
            return $valid;
        }

        if (self::$lastValid !== null) {
            return self::$lastValid;
        }

        try {
            $cached = self::normalize(
                \Illuminate\Support\Facades\Cache::store('redis')->get(self::LAST_VALID_CACHE_KEY)
            );
            if ($cached !== null) {
                self::$lastValid = $cached;
                return $cached;
            }
        } catch (\Throwable) {
            // fall through to the default
        }

        return self::DEFAULT_HOURS;
    }

    /**
     * Forget the in-process last-valid value (tests / long-running workers
     * that must observe a cold start).
     */
    public static function resetLastValid(): void
    {
        self::$lastValid = null;
    }
}
