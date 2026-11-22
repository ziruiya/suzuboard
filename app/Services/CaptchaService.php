<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ReCaptcha\ReCaptcha;

class CaptchaService
{
    /**
     * App captcha modes (runtime setting app_captcha_mode).
     * turnstile = current behaviour (enforced on every client);
     * exempt = App clients carrying the X-Suzu-App header skip verification;
     * local = reserved, not implemented in this phase.
     */
    public const APP_CAPTCHA_MODES = ['turnstile', 'exempt', 'local'];

    public const APP_CAPTCHA_MODE_DEFAULT = 'turnstile';

    /**
     * Current App captcha mode. Single source of truth is the runtime
     * setting app_captcha_mode; a missing or invalid value falls back
     * to 'turnstile'.
     */
    public static function appCaptchaMode(): string
    {
        $mode = admin_setting('app_captcha_mode', self::APP_CAPTCHA_MODE_DEFAULT);
        return is_string($mode) && in_array($mode, self::APP_CAPTCHA_MODES, true)
            ? $mode
            : self::APP_CAPTCHA_MODE_DEFAULT;
    }

    /**
     * App exemption applies only when the mode is 'exempt' and the
     * request carries the header X-Suzu-App: 1.
     */
    public static function isAppCaptchaExempt(Request $request): bool
    {
        return self::appCaptchaMode() === 'exempt'
            && $request->header('X-Suzu-App') === '1';
    }
    /**
     * 验证人机验证码
     *
     * @param Request $request 请求对象
     * @return array [是否通过, 错误消息]
     */
    public function verify(Request $request): array
    {
        // App exemption channel: skip verification only in exempt mode
        // with the App header; every other case keeps the existing
        // enforced verification unchanged.
        if (self::isAppCaptchaExempt($request)) {
            return [true, null];
        }

        if (!(int) admin_setting('captcha_enable', 0)) {
            return [true, null];
        }

        $captchaType = admin_setting('captcha_type', 'recaptcha');

        return match ($captchaType) {
            'turnstile' => $this->verifyTurnstile($request),
            'recaptcha-v3' => $this->verifyRecaptchaV3($request),
            'recaptcha' => $this->verifyRecaptcha($request),
            default => [false, [400, __('Invalid captcha type')]]
        };
    }

    /**
     * 验证 Cloudflare Turnstile
     *
     * @param Request $request
     * @return array
     */
    private function verifyTurnstile(Request $request): array
    {
        $turnstileToken = $request->input('turnstile_token');
        if (!$turnstileToken) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $response = Http::post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => admin_setting('turnstile_secret_key'),
            'response' => $turnstileToken,
            'remoteip' => $request->ip()
        ]);

        $result = $response->json();
        if (!$result['success']) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }

    /**
     * 验证 Google reCAPTCHA v3
     *
     * @param Request $request
     * @return array
     */
    private function verifyRecaptchaV3(Request $request): array
    {
        $recaptchaV3Token = $request->input('recaptcha_v3_token');
        if (!$recaptchaV3Token) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $recaptcha = new ReCaptcha(admin_setting('recaptcha_v3_secret_key'));
        $recaptchaResp = $recaptcha->verify($recaptchaV3Token, $request->ip());

        if (!$recaptchaResp->isSuccess()) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        // 检查分数阈值（如果有的话）
        $score = $recaptchaResp->getScore();
        $threshold = admin_setting('recaptcha_v3_score_threshold', 0.5);
        if ($score < $threshold) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }

    /**
     * 验证 Google reCAPTCHA v2
     *
     * @param Request $request
     * @return array
     */
    private function verifyRecaptcha(Request $request): array
    {
        $recaptchaData = $request->input('recaptcha_data');
        if (!$recaptchaData) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $recaptcha = new ReCaptcha(admin_setting('recaptcha_key'));
        $recaptchaResp = $recaptcha->verify($recaptchaData);

        if (!$recaptchaResp->isSuccess()) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }
} 