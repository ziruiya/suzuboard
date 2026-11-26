<?php

namespace Tests\Unit\Services;

use App\Services\LocalCaptchaService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LocalCaptchaServiceTest extends TestCase
{
    use RefreshDatabase;

    private LocalCaptchaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->service = app(LocalCaptchaService::class);
    }

    private function seedChallenge(string $id, string $code): void
    {
        Cache::put(
            CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', $id),
            hash('sha256', strtoupper($code)),
            LocalCaptchaService::TTL_SECONDS
        );
    }

    public function test_issue_returns_id_data_uri_and_ttl_and_stores_only_a_hash(): void
    {
        $challenge = $this->service->issue();

        $this->assertNotEmpty($challenge['id']);
        $this->assertSame(LocalCaptchaService::TTL_SECONDS, $challenge['expires_in']);
        $this->assertStringStartsWith('data:image/png;base64,', $challenge['image']);

        $png = base64_decode(substr($challenge['image'], strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        $stored = Cache::get(CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', $challenge['id']));
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored);
    }

    public function test_verify_accepts_the_correct_answer_case_insensitively(): void
    {
        $this->seedChallenge('challenge-ok', 'AB3K');

        $this->assertSame(LocalCaptchaService::RESULT_OK, $this->service->verify('challenge-ok', 'ab3k'));
    }

    public function test_verify_rejects_a_wrong_answer(): void
    {
        $this->seedChallenge('challenge-wrong', 'AB3K');

        $this->assertSame(LocalCaptchaService::RESULT_INVALID, $this->service->verify('challenge-wrong', 'ZZZZ'));
    }

    public function test_challenge_is_single_use_even_after_a_wrong_guess(): void
    {
        $this->seedChallenge('challenge-once', 'AB3K');

        $this->assertSame(LocalCaptchaService::RESULT_INVALID, $this->service->verify('challenge-once', 'ZZZZ'));
        // The wrong guess consumed the challenge: even the right answer now reports expired.
        $this->assertSame(LocalCaptchaService::RESULT_EXPIRED, $this->service->verify('challenge-once', 'AB3K'));
    }

    public function test_challenge_cannot_be_replayed_after_success(): void
    {
        $this->seedChallenge('challenge-replay', 'AB3K');

        $this->assertSame(LocalCaptchaService::RESULT_OK, $this->service->verify('challenge-replay', 'AB3K'));
        $this->assertSame(LocalCaptchaService::RESULT_EXPIRED, $this->service->verify('challenge-replay', 'AB3K'));
    }

    public function test_captcha_requires_confirmed_cache_deletion(): void
    {
        $this->seedChallenge('store-failure', 'AB3K');
        $store = new class extends \Illuminate\Cache\ArrayStore {
            public function forget($key): bool
            {
                return false;
            }
        };
        $store->put(CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', 'store-failure'), Cache::get(CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', 'store-failure')), 60);
        Cache::swap(new \Illuminate\Cache\Repository($store));

        $this->assertSame(LocalCaptchaService::RESULT_EXPIRED, $this->service->verify('store-failure', 'AB3K'));
    }

    public function test_unknown_challenge_reports_expired(): void
    {
        $this->assertSame(LocalCaptchaService::RESULT_EXPIRED, $this->service->verify('no-such-id', 'AB3K'));
    }

    public function test_render_png_produces_a_valid_png_of_the_configured_size(): void
    {
        $png = $this->service->renderPng('Z9QX');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        // IHDR width/height are big-endian uint32 at byte offsets 16 and 20.
        $this->assertSame(140, unpack('N', substr($png, 16, 4))[1]);
        $this->assertSame(52, unpack('N', substr($png, 20, 4))[1]);
    }
}
