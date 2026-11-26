<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\AppRateLimitService;
use App\Services\CaptchaService;
use App\Services\EmailVerifyGuardService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommController extends Controller
{

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        // Compensating per-IP rate limit for this endpoint (hourly window)
        if (!app(AppRateLimitService::class)->attempt('APP_EMAIL_VERIFY_IP_RATE_LIMIT', (string) $request->ip(), 30)) {
            return $this->fail([429, __('Sending frequently, please try again after :minute minute', ['minute' => 60])]);
        }

        // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return $this->fail($captchaError, CaptchaService::errorDataFor($request));
        }

        $inputEmail = $request->input('email');
        $email = User::normalizeEmail($inputEmail);

        // 检查白名单后缀限制
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $isRegisteredEmail = User::byEmail($email)->exists();
            if (!$isRegisteredEmail) {
                if (!Helper::emailSuffixVerify($inputEmail, Helper::getEmailSuffix())) {
                    return $this->fail([400, __('Email suffix is not in whitelist')]);
                }
            }
        }

        $cooldownKey = CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email);
        if (!Cache::add($cooldownKey, time(), 60)) {
            return $this->fail([400, __('Email verification code has been sent, please request again later')]);
        }
        $guard = app(EmailVerifyGuardService::class);
        if (!$guard->reserveSend($email)) {
            Cache::forget($cooldownKey);
            return $this->fail([429, __('Sending frequently, please try again after :minute minute', ['minute' => 60])]);
        }

        $code = rand(100000, 999999);
        $subject = admin_setting('app_name', 'XBoard') . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'code' => $code,
                'url' => admin_setting('app_url')
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        $guard->recordSend();
        return $this->success(true);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return $this->success(true);
    }

}
