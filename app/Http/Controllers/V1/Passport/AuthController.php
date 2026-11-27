<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Services\AppRateLimitService;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected MailLinkService $mailLinkService;
    protected RegisterService $registerService;
    protected LoginService $loginService;

    public function __construct(
        MailLinkService $mailLinkService,
        RegisterService $registerService,
        LoginService $loginService
    ) {
        $this->mailLinkService = $mailLinkService;
        $this->registerService = $registerService;
        $this->loginService = $loginService;
    }

    /**
     * 通过邮件链接登录
     */
    public function loginWithMailLink(Request $request)
    {
        if (is_string($email = $request->input('email'))) {
            $request->merge(['email' => User::normalizeEmail($email)]);
        }

        $params = $request->validate([
            'email' => 'required|email:strict|max:64',
            'redirect' => 'nullable'
        ]);

        [$success, $result] = $this->mailLinkService->handleMailLink(
            $params['email'],
            $request->input('redirect')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success($result);
    }

    /**
     * 用户注册
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            // Surface the local-captcha machine error code (AUD-001)
            // when registration failed on the captcha step; null otherwise.
            return $this->fail($result, CaptchaService::errorDataFor($request));
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 用户登录
     */
    public function login(AuthLogin $request)
    {
        if ((int) admin_setting('password_limit_enable', true)
            && !$this->reserveCredentialRequest($request, 'LOGIN')) {
            return $this->fail([429, __('Too many requests')]);
        }

        $email = $request->input('email');
        $password = $request->input('password');

        [$success, $result] = $this->loginService->login($email, $password);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 通过token登录
     */
    public function token2Login(Request $request)
    {
        // 处理直接通过token重定向
        if ($token = $request->input('token')) {
            $redirect = '/#/login?verify=' . $token . '&redirect=' . ($request->input('redirect', 'dashboard'));

            return redirect()->to(
                admin_setting('app_url')
                ? admin_setting('app_url') . $redirect
                : url($redirect)
            );
        }

        // 处理通过验证码登录
        if ($verify = $request->input('verify')) {
            $userId = $this->mailLinkService->handleTokenLogin($verify);

            if (!$userId) {
                return response()->json([
                    'message' => __('Token error')
                ], 400);
            }

            $user = \App\Models\User::find($userId);

            if (!$user || $user->banned) {
                return response()->json([
                    'message' => __('User not found')
                ], 400);
            }

            $authService = new AuthService($user);

            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        }

        return response()->json([
            'message' => __('Invalid request')
        ], 400);
    }

    /**
     * 获取快速登录URL
     */
    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');

        if (!is_string($authorization) || $authorization === '') {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED
            ], 401);
        }

        $session = AuthService::findAccessTokenByBearerToken($authorization);
        $user = $session?->tokenable;

        if (!$user instanceof User) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED_EXPIRED
            ], 401);
        }

        $url = $this->loginService->generateQuickLoginUrl(
            $user,
            $request->input('redirect'),
            (int) $session->id
        );
        return $this->success($url);
    }

    /**
     * 忘记密码处理
     */
    public function forget(AuthForget $request)
    {
        if (!$this->reserveCredentialRequest($request, 'RESET')) {
            return $this->fail([429, __('Too many requests')]);
        }

        [$success, $result] = $this->loginService->resetPassword(
            $request->input('email'),
            $request->input('email_code'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success(true);
    }

    private function reserveCredentialRequest(Request $request, string $action): bool
    {
        $global = CacheKey::get($action . '_GLOBAL_RATE_LIMIT', 'ALL');
        return app(AppRateLimitService::class)->reserve($global, [
            $global => 600,
            CacheKey::get($action . '_IP_RATE_LIMIT', (string) $request->ip()) => 30,
        ], 60);
    }
}
