<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AppRateLimitService;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class LoginService
{
    /**
     * 处理用户登录
     *
     * @param string $email 用户邮箱
     * @param string $password 用户密码
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function login(string $email, string $password): array
    {
        $email = User::normalizeEmail($email);

        $limitMinutes = (int) admin_setting('password_limit_expire', 60);
        if ((int) admin_setting('password_limit_enable', true)
            && !app(AppRateLimitService::class)->attempt(
                'PASSWORD_ERROR_LIMIT',
                $email,
                (int) admin_setting('password_limit_count', 5),
                60 * max(1, $limitMinutes)
            )) {
            return [false, [429, __('There are too many password errors, please try again after :minute minutes.', [
                'minute' => $limitMinutes,
            ])]];
        }

        // 查找用户
        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('Incorrect email or password')]];
        }

        // 验证密码
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            return [false, [400, __('Incorrect email or password')]];
        }

        // 检查账户状态
        if ($user->banned) {
            return [false, [400, __('Your account has been suspended')]];
        }

        // 更新最后登录时间
        $user->last_login_at = time();
        $user->save();

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }

    /**
     * 处理密码重置
     *
     * @param string $email 用户邮箱
     * @param string $emailCode 邮箱验证码
     * @param string $password 新密码
     * @return array [成功状态, 结果或错误信息]
     */
    public function resetPassword(string $email, string $emailCode, string $password): array
    {
        $email = User::normalizeEmail($email);

        // 检查重置请求限制
        if (!app(AppRateLimitService::class)->attempt('FORGET_REQUEST_LIMIT', $email, 3, 300)) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }

        // 验证邮箱验证码
        $cachedEmailCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        if ($cachedEmailCode === null || !hash_equals((string) $cachedEmailCode, $emailCode)) {
            return [false, [400, __('Incorrect email verification code')]];
        }

        // 查找用户
        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('This email is not registered in the system')]];
        }

        // SB-TA-012/013: the password change, the session revocation
        // and the auth-version bump commit in ONE transaction — a
        // crash between them must not leave new credentials alongside
        // live old sessions or still-valid pre-signed tickets.
        $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $password) {
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->password_algo = NULL;
            $user->password_salt = NULL;

            if (!$user->save()) {
                return false;
            }

            $user->tokens()->delete();
            $user->increment('auth_version');

            return true;
        });

        if (!$saved) {
            return [false, [500, __('Reset failed')]];
        }

        HookManager::call('user.password.reset.after', $user);

        // 清除邮箱验证码
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));

        return [true, true];
    }


    /**
     * 生成临时登录令牌和快速登录URL
     *
     * @param User $user 用户对象
     * @param string $redirect 重定向路径
     * @return string|null 快速登录URL
     */
    public function generateQuickLoginUrl(User $user, ?string $redirect = null, ?int $sessionId = null): ?string
    {
        if (!$user || !$user->exists) {
            return null;
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);

        // SB-TA-012: bind the ticket to the issuing session and the
        // account's current auth version (see MailLinkService).
        Cache::put($key, MailLinkService::ticketPayload($user, $sessionId), 60);

        $redirect = $redirect ?: 'dashboard';
        $loginRedirect = '/#/login?verify=' . $code . '&redirect=' . rawurlencode($redirect);

        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $loginRedirect;
        } else {
            $url = url($loginRedirect);
        }

        return $url;
    }
}