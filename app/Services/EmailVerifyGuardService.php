<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Send budgets and anomaly protection for verification email. */
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

    public function reserveSend(string $email): bool
    {
        $domain = substr(strrchr($email, '@'), 1);
        $global = CacheKey::get('APP_EMAIL_VERIFY_GLOBAL_RATE_LIMIT', 'ALL');

        return $this->rateLimit->reserve($global, [
            $global => $this->globalLimit(),
            CacheKey::get('APP_EMAIL_VERIFY_DOMAIN_RATE_LIMIT', $domain) => $this->domainLimit(),
        ], 3600);
    }

    public function recordSend(string $ip): void
    {
        $this->recordAttemptAndDetectAnomaly($ip);
    }

    public function recordAttemptAndDetectAnomaly(string $ip): void
    {
        $window = $this->anomalyWindow();
        $threshold = $this->anomalyThreshold();
        $count = $this->rateLimit->hit('APP_EMAIL_VERIFY_ANOMALY_RATE_LIMIT', 'ALL', $window);
        if ($count < $threshold) {
            return;
        }

        try {
            Cache::lock(self::AUDIT_ACTION_AUTO_FLIP, 10)
                ->block(1, fn () => $this->flipToTurnstile($ip, $window, $count, $threshold));
        } catch (LockTimeoutException) {
            Log::warning('AUD-001 auto-flip lock timed out');
        }
    }

    private function flipToTurnstile(string $ip, int $window, int $count, int $threshold): void
    {
        // Reload settings after acquiring the lock to see any preceding flip.
        app()->forgetInstance(Setting::class);
        $mode = CaptchaService::appCaptchaMode();
        if ($mode === 'turnstile') {
            return;
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
                    'window_seconds' => $window,
                    'window_count' => $count,
                    'threshold' => $threshold,
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
            'window_seconds' => $window,
            'window_count' => $count,
            'threshold' => $threshold,
            'trigger_ip' => $ip,
        ]);
    }

    private function positiveSetting(string $key, int $default): int
    {
        $value = admin_setting($key, $default);
        $value = is_numeric($value) ? (int) $value : 0;
        return $value > 0 ? $value : $default;
    }
}
