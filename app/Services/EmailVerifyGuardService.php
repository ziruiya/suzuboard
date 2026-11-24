<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\Log;

/**
 * Layered abuse guard for the sendEmailVerify endpoint (AUD-001).
 *
 * Sits on top of the existing per-IP limit (which is unchanged) and adds:
 *  - a per-email-domain hourly limit,
 *  - a site-wide hourly send budget,
 *  - an anomaly tripwire: when send attempts inside a short window
 *    cross a configurable threshold, the App captcha mode is flipped
 *    to 'turnstile' through the admin_setting write path (so the
 *    settings cache is flushed), an audit row is written for the
 *    patrol to see, and the mode is NOT flipped back automatically.
 *
 * Default thresholds are sized from production traffic observed
 * 2026-11-25 (7 days: 9 verification mails total, peak hour 4,
 * peak day 5): each default is at least an order of magnitude above
 * the observed peak, so normal use never comes close, while any
 * scripted flood trips them immediately. All values are runtime
 * settings and can be tuned without a deploy.
 */
class EmailVerifyGuardService
{
    public const SETTING_DOMAIN_LIMIT = 'app_email_verify_domain_limit';
    public const SETTING_GLOBAL_LIMIT = 'app_email_verify_global_limit';
    public const SETTING_ANOMALY_THRESHOLD = 'app_email_verify_anomaly_threshold';
    public const SETTING_ANOMALY_WINDOW = 'app_email_verify_anomaly_window_seconds';

    /** Per email domain, per hour. Observed peak: ~4/hour across ALL domains. */
    public const DEFAULT_DOMAIN_LIMIT = 30;

    /** Whole site, per hour. Observed peak: 4/hour. */
    public const DEFAULT_GLOBAL_LIMIT = 200;

    /** Attempts inside the anomaly window that trip the flip. */
    public const DEFAULT_ANOMALY_THRESHOLD = 120;

    /** Anomaly window in seconds (10 minutes). */
    public const DEFAULT_ANOMALY_WINDOW = 600;

    public const AUDIT_ACTION_AUTO_FLIP = 'security.app_captcha_auto_flip';

    public function __construct(private AppRateLimitService $rateLimit)
    {
    }

    public function domainLimit(): int
    {
        return $this->positiveSetting(self::SETTING_DOMAIN_LIMIT, self::DEFAULT_DOMAIN_LIMIT);
    }

    public function globalLimit(): int
    {
        return $this->positiveSetting(self::SETTING_GLOBAL_LIMIT, self::DEFAULT_GLOBAL_LIMIT);
    }

    public function anomalyThreshold(): int
    {
        return $this->positiveSetting(self::SETTING_ANOMALY_THRESHOLD, self::DEFAULT_ANOMALY_THRESHOLD);
    }

    public function anomalyWindow(): int
    {
        return $this->positiveSetting(self::SETTING_ANOMALY_WINDOW, self::DEFAULT_ANOMALY_WINDOW);
    }

    /** Per-email-domain hourly limit (domain = part after the last '@'). */
    public function domainAllowed(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            // Malformed input is rejected downstream; do not bucket it.
            return true;
        }
        $domain = strtolower(trim(substr($email, $at + 1)));
        if ($domain === '') {
            return true;
        }

        return $this->rateLimit->attempt(
            'APP_EMAIL_VERIFY_DOMAIN_RATE_LIMIT',
            $domain,
            $this->domainLimit(),
            3600
        );
    }

    /** Site-wide hourly send budget. */
    public function globalAllowed(): bool
    {
        return $this->rateLimit->attempt(
            'APP_EMAIL_VERIFY_GLOBAL_RATE_LIMIT',
            'ALL',
            $this->globalLimit(),
            3600
        );
    }

    /**
     * Count one send attempt in the anomaly window and, when the
     * threshold is crossed, flip app_captcha_mode to 'turnstile'.
     * Returns true only when this call performed the flip.
     */
    public function recordAttemptAndDetectAnomaly(string $ip): bool
    {
        $count = $this->rateLimit->hit(
            'APP_EMAIL_VERIFY_ANOMALY_RATE_LIMIT',
            'ALL',
            $this->anomalyWindow()
        );
        if ($count < $this->anomalyThreshold()) {
            return false;
        }

        $mode = CaptchaService::appCaptchaMode();
        if ($mode === 'turnstile') {
            return false;
        }

        // Application write path: flushes the settings cache (raw SQL would not).
        admin_setting(['app_captcha_mode' => 'turnstile']);

        try {
            AdminAuditLog::insert([
                'admin_id' => 0,
                'action' => self::AUDIT_ACTION_AUTO_FLIP,
                'method' => 'SYSTEM',
                'uri' => '/api/v1/passport/comm/sendEmailVerify',
                'request_data' => json_encode([
                    'key' => 'app_captcha_mode',
                    'old_value' => $mode,
                    'new_value' => 'turnstile',
                    'window_seconds' => $this->anomalyWindow(),
                    'window_count' => $count,
                    'threshold' => $this->anomalyThreshold(),
                    'trigger_ip' => $ip,
                    'result' => 'applied',
                ], JSON_UNESCAPED_UNICODE),
                'ip' => $ip,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AUD-001 auto-flip audit write failed: ' . $e->getMessage());
        }

        Log::warning('AUD-001 anomaly tripwire: app_captcha_mode flipped to turnstile', [
            'old_mode' => $mode,
            'window_seconds' => $this->anomalyWindow(),
            'window_count' => $count,
            'threshold' => $this->anomalyThreshold(),
            'trigger_ip' => $ip,
        ]);

        return true;
    }

    private function positiveSetting(string $key, int $default): int
    {
        $value = admin_setting($key, $default);
        $value = is_numeric($value) ? (int) $value : 0;
        return $value > 0 ? $value : $default;
    }
}
