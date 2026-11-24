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
     * local = App clients must solve the self-hosted image captcha
     *         (LocalCaptchaService, AUD-001); web clients are unaffected
     *         and keep the existing captcha_type flow in every mode.
     */
    public const APP_CAPTCHA_MODES = ['turnstile', 'exempt', 'local'];

    public const APP_CAPTCHA_MODE_DEFAULT = 'turnstile';

    /**
     * Machine-readable local-captcha error codes. On a local failure the
     * code is attached to the request and surfaced to the client as
     * data.captcha_error_code (see errorDataFor()).
     * APP_CAPTCHA_REQUIRED = the request carried no captcha fields at
     * all (an old App version): the client maps it to an update prompt.
     */
    public const ERR_APP_CAPTCHA_REQUIRED = 'APP_CAPTCHA_REQUIRED';
    public const ERR_CAPTCHA_INVALID = 'CAPTCHA_INVALID';
    public const ERR_CAPTCHA_EXPIRED = 'CAPTCHA_EXPIRED';

    /** Request attribute that carries the machine error code. */
    public const REQUEST_ERROR_CODE_ATTRIBUTE = 'suzu_captcha_error_code';

    /**
     * Response data carrying the machine error code of a failed local
     * verification, or null when the failure has no machine code.
     *
     * @return array{captcha_error_code: string}|null
     */
    public static function errorDataFor(Request $request): ?array
    {
        $code = $request->attributes->get(self::REQUEST_ERROR_CODE_ATTRIBUTE);
        return is_string($code) && $code !== ''
            ? ['captcha_error_code' => $code]
            : null;
    }

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

        // Local channel (AUD-001): in local mode, App clients prove
        // humanity with the self-hosted image captcha instead of
        // Turnstile. This branch is deliberately ahead of the
        // captcha_enable gate: selecting local mode is itself the
        // decision to enforce a captcha on App clients. Requests
        // without the App header never enter this branch and keep
        // the web flow below in every mode.
        if (self::appCaptchaMode() === 'local' && $request->header('X-Suzu-App') === '1') {
            return $this->verifyLocal($request);
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
     * 验证本地图形验证码（local 模式，App 客户端）
     *
     * @param Request $request 请求对象
     * @return array [是否通过, 错误消息]
     */
    private function verifyLocal(Request $request): array
    {
        $captchaId = $request->input('captcha_id');
        $captchaAnswer = $request->input('captcha_answer');

        // No fields at all = an App version that predates local mode.
        // Give it a distinct machine code so the client can prompt for
        // an update instead of failing silently.
        if (!is_string($captchaId) || $captchaId === '' || !is_string($captchaAnswer) || $captchaAnswer === '') {
            return $this->localFailure($request, self::ERR_APP_CAPTCHA_REQUIRED, __('Captcha required, please update the app to the latest version'));
        }

        $result = app(LocalCaptchaService::class)->verify($captchaId, $captchaAnswer);

        return match ($result) {
            LocalCaptchaService::RESULT_OK => [true, null],
            LocalCaptchaService::RESULT_INVALID => $this->localFailure($request, self::ERR_CAPTCHA_INVALID, __('Invalid code is incorrect')),
            default => $this->localFailure($request, self::ERR_CAPTCHA_EXPIRED, __('Captcha has expired, please refresh and try again')),
        };
    }

    /**
     * Build a local-mode failure and record its machine error code on
     * the request for the controller to surface as data.
     *
     * @return array [false, [状态码, 错误消息]]
     */
    private function localFailure(Request $request, string $code, string $message): array
    {
        $request->attributes->set(self::REQUEST_ERROR_CODE_ATTRIBUTE, $code);
        return [false, [400, $message]];
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