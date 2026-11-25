<?php

namespace App\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class MailLinkService
{
    /**
     * 处理邮件链接登录逻辑
     *
     * @param string $email 用户邮箱
     * @param string|null $redirect 重定向地址
     * @return array 返回处理结果
     */
    public function handleMailLink(string $email, ?string $redirect = null): array
    {
        $email = User::normalizeEmail($email);

        if (!(int) admin_setting('login_with_mail_link_enable')) {
            return [false, [404, null]];
        }

        if (Cache::get(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email))) {
            return [false, [429, __('Sending frequently, please try again later')]];
        }

        $user = User::byEmail($email)->first();
        if (!$user) {
            return [true, true]; // 成功但用户不存在，保护用户隐私
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        // SB-TA-012: tickets carry the account's auth version at
        // issuance; a later password reset / admin password change /
        // ban bumps the version and every outstanding ticket dies.
        Cache::put($key, self::ticketPayload($user, null), 300);
        Cache::put(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email), time(), 60);

        $redirectUrl = '/#/login?verify=' . $code . '&redirect=' . ($redirect ? $redirect : 'dashboard');
        if (admin_setting('app_url')) {
            $link = admin_setting('app_url') . $redirectUrl;
        } else {
            $link = url($redirectUrl);
        }

        $this->sendMailLinkEmail($user, $link);

        return [true, true];
    }

    /**
     * 发送邮件链接登录邮件
     *
     * @param User $user 用户对象
     * @param string $link 登录链接
     * @return void
     */
    private function sendMailLinkEmail(User $user, string $link): void
    {
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'mailLogin',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'link' => $link,
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    /**
     * SB-TA-012: the payload every pre-signed login ticket stores.
     * session_id is the Sanctum token that requested a quick-login URL
     * (null for mail-link tickets, which have no issuing session);
     * auth_version pins the ticket to the account's credential state.
     */
    public static function ticketPayload(User $user, ?int $sessionId): array
    {
        return [
            'user_id' => (int) $user->id,
            'auth_version' => (int) ($user->auth_version ?? 0),
            'session_id' => $sessionId,
        ];
    }

    /**
     * 处理Token登录
     *
     * SB-TA-012: redemption is atomic and one-time — the ticket is
     * consumed under a cache lock, then validated against the CURRENT
     * account state: the auth version must still match (no password
     * reset / admin password change / ban since issuance) and, for
     * quick-login tickets, the issuing Sanctum session must still
     * exist and be unexpired. A revoked session therefore cannot be
     * rebuilt from a ticket signed before the revocation.
     *
     * @param string $token 登录令牌
     * @return int|null 用户ID或null
     */
    public function handleTokenLogin(string $token): ?int
    {
        $key = CacheKey::get('TEMP_TOKEN', $token);

        $lock = Cache::lock('temp_token_consume_' . md5($token), 10);
        try {
            return $lock->block(5, function () use ($key) {
                $payload = Cache::get($key);

                if ($payload === null) {
                    return null;
                }

                // Consume exactly once, whatever the validation outcome:
                // a ticket that fails validation is dead, not retryable.
                Cache::forget($key);

                // SB-TA-012 hard cut (GPT review ruling): tickets
                // issued by the previous code stored a bare user id
                // with no auth version and no session binding, so
                // there is no way to prove one was not issued before
                // a password reset / session revocation. They are NOT
                // honoured — not even inside their 300s lifetime. A
                // user holding one must request a fresh login link;
                // that is the accepted cost of closing the ticket
                // completely.
                if (is_int($payload) || is_string($payload)) {
                    return null;
                }

                if (!is_array($payload) || !isset($payload['user_id'])) {
                    return null;
                }

                $user = User::find((int) $payload['user_id']);

                if (!$user || $user->banned) {
                    return null;
                }

                if ((int) ($user->auth_version ?? 0) !== (int) ($payload['auth_version'] ?? -1)) {
                    return null;
                }

                $sessionId = $payload['session_id'] ?? null;
                if ($sessionId !== null) {
                    $session = \Laravel\Sanctum\PersonalAccessToken::find((int) $sessionId);
                    if (!$session
                        || (int) $session->tokenable_id !== (int) $user->id
                        || ($session->expires_at && $session->expires_at->isPast())) {
                        return null;
                    }
                }

                return (int) $user->id;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return null;
        }
    }
}