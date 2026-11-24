<?php

namespace Tests\Unit\Services;

use App\Services\SubscribeUpdateIntervalService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscribeUpdateIntervalServiceTest extends TestCase
{
    public static function normalizeCases(): array
    {
        return [
            'int in range' => [6, 6],
            'lower bound' => [1, 1],
            'upper bound' => [72, 72],
            'numeric string' => ['12', 12],
            'padded numeric string' => [' 8 ', 8],
            'whole float' => [6.0, 6],
            'zero' => [0, null],
            'above max' => [73, null],
            'negative' => [-5, null],
            'negative string' => ['-5', null],
            'non-numeric string' => ['abc', null],
            'decimal string' => ['6.5', null],
            'decimal float' => [6.5, null],
            'null' => [null, null],
            'bool' => [true, null],
            'array' => [[6], null],
            'empty string' => ['', null],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function test_normalize(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, SubscribeUpdateIntervalService::normalize($input));
    }

    public function test_spec_constants(): void
    {
        $this->assertSame('subscribe_update_interval_hours', SubscribeUpdateIntervalService::SETTING_KEY);
        $this->assertSame(6, SubscribeUpdateIntervalService::DEFAULT_HOURS);
        $this->assertSame(1, SubscribeUpdateIntervalService::MIN_HOURS);
        $this->assertSame(72, SubscribeUpdateIntervalService::MAX_HOURS);
    }
}
