<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\AppRateLimitService;
use App\Services\CaptchaService;
use App\Services\LocalCaptchaService;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;

class CommController extends Controller
{
    public function config()
    {
        $appCaptchaMode = CaptchaService::appCaptchaMode();
        $data = [
            'tos_url' => admin_setting('tos_url'),
            'is_email_verify' => (int) admin_setting('email_verify', 0) ? 1 : 0,
            'is_invite_force' => (int) admin_setting('invite_force', 0) ? 1 : 0,
            'email_whitelist_suffix' => (int) admin_setting('email_whitelist_enable', 0)
                ? Helper::getEmailSuffix()
                : 0,
            'is_captcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
            'captcha_type' => admin_setting('captcha_type', 'recaptcha'),
            'recaptcha_site_key' => admin_setting('recaptcha_site_key'),
            'recaptcha_v3_site_key' => admin_setting('recaptcha_v3_site_key'),
            'recaptcha_v3_score_threshold' => admin_setting('recaptcha_v3_score_threshold', 0.5),
            'turnstile_site_key' => admin_setting('turnstile_site_key'),
            // App captcha mode: app_captcha_mode is the source of truth,
            // app_captcha_exempt is a derived view (1 only in exempt mode)
            'app_captcha_mode' => $appCaptchaMode,
            'app_captcha_exempt' => $appCaptchaMode === 'exempt' ? 1 : 0,
            'app_description' => admin_setting('app_description'),
            'app_url' => admin_setting('app_url'),
            'logo' => admin_setting('logo'),
            // 保持向后兼容
            'is_recaptcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
        ];

        $data = HookManager::filter('guest_comm_config', $data);

        return $this->success($data);
    }

    /**
     * Issue a self-hosted image captcha challenge (AUD-001 local mode).
     * The answer is stored server-side only; the response carries the
     * challenge id and the PNG as a base64 data URI.
     */
    public function captcha(Request $request, AppRateLimitService $rateLimit)
    {
        if (CaptchaService::appCaptchaMode() !== 'local') {
            return $this->fail([404, __('Local captcha is unavailable')]);
        }

        $global = CacheKey::get('LOCAL_CAPTCHA_GLOBAL_RATE_LIMIT', 'ALL');
        if (!$rateLimit->reserve($global, [
            $global => 300,
            CacheKey::get('LOCAL_CAPTCHA_IP_RATE_LIMIT', (string) $request->ip()) => 15,
        ], 60)) {
            return $this->fail([429, __('Too many requests')]);
        }

        $challenge = app(LocalCaptchaService::class)->issue();

        return $this->success([
            'captcha_id' => $challenge['id'],
            'captcha_image' => $challenge['image'],
            'expires_in' => $challenge['expires_in'],
        ]);
    }
}
