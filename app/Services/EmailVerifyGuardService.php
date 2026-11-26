<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Log;

/** Send budgets and anomaly telemetry for verification email. */
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

    public const DEFAULT_ANOMALY_THRESHOLD = 120;
    public const DEFAULT_ANOMALY_WINDOW = 600;

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

    public function reserveSend(string $email): bool
    {
        $domain = substr(strrchr($email, '@'), 1);
        $global = CacheKey::get('APP_EMAIL_VERIFY_GLOBAL_RATE_LIMIT', 'ALL');

        return $this->rateLimit->reserve($global, [
            $global => $this->globalLimit(),
            CacheKey::get('APP_EMAIL_VERIFY_DOMAIN_RATE_LIMIT', $domain) => $this->domainLimit(),
        ], 3600);
    }

    public function recordSend(): void
    {
        $count = $this->rateLimit->hit('APP_EMAIL_VERIFY_ANOMALY_RATE_LIMIT', 'ALL', $this->anomalyWindow());
        if ($count === $this->anomalyThreshold()) {
            Log::warning('Verification email send threshold reached', [
                'window_seconds' => $this->anomalyWindow(),
                'window_count' => $count,
            ]);
        }
    }

    private function positiveSetting(string $key, int $default): int
    {
        $value = admin_setting($key, $default);
        $value = is_numeric($value) ? (int) $value : 0;
        return $value > 0 ? $value : $default;
    }
}
