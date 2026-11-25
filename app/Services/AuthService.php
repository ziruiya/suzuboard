<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * SB-TA-013/014: revoke every session of the account and bump its
     * authentication version, atomically. Any password change (user
     * reset or admin edit), and any ban, must go through this so old
     * tokens and pre-signed login tickets (SB-TA-012) die together —
     * never one without the other, and never in a separate step that
     * leaves a window between "status written" and "sessions revoked".
     */
    public function revokeAllSessionsAndBumpAuthVersion(): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            $this->user->tokens()->delete();
            $this->user->increment('auth_version');
        });
    }

    public function generateAuthData(): array
    {
        // SB-TA-014: re-read the account state at issuance time. A ban
        // that landed after the caller's own check (e.g. after the
        // password check in login) must not be followed by a fresh
        // one-year token; the Admin middleware also rejects banned
        // users, this closes the issuance side of the window.
        $fresh = User::find($this->user->id);
        if (!$fresh || $fresh->banned) {
            throw new \App\Exceptions\ApiException(__('Your account has been suspended'), 403);
        }

        // Create a new Sanctum token with device info
        $token = $this->user->createToken(
            Str::random(20), // token name (device identifier)
            ['*'], // abilities
            now()->addYear() // expiration
        );

        // Format token: remove ID prefix and add Bearer
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        return [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
        ];
    }

    public function getSessions(): array
    {
        return $this->user->tokens()->get()->toArray();
    }

    public function removeSession(string $sessionId): bool
    {
        $this->user->tokens()->where('id', $sessionId)->delete();
        return true;
    }

    public function removeAllSessions(): bool
    {
        $this->user->tokens()->delete();
        return true;
    }

    /** SB-TA-012: resolve the Sanctum token row behind a bearer token. */
    public static function findAccessTokenByBearerToken(string $bearerToken): ?PersonalAccessToken
    {
        $token = str_replace('Bearer ', '', $bearerToken);

        $accessToken = PersonalAccessToken::findToken($token);

        if ($accessToken && $accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        return $accessToken;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $token = str_replace('Bearer ', '', $bearerToken);

        $accessToken = PersonalAccessToken::findToken($token);

        // 已过期的 token 不得再用于换取快捷登录（防止过期 token 被无限续命）
        if ($accessToken && $accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        $tokenable = $accessToken?->tokenable;
        
        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(string $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }
}
