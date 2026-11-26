<?php

namespace Tests\Unit\Utils;

use App\Utils\MoneyAmount;
use PHPUnit\Framework\TestCase;

/**
 * SB-MONEY-017 (parser bounds) + SB-MONEY-011 (integer-cent helpers).
 * The pre-fix parser multiplied unchecked integers: a negative int
 * was accepted, and an out-of-range value saturated or promoted to
 * float instead of being rejected as invalid.
 */
class MoneyAmountBoundsTest extends TestCase
{
    public function test_major_to_cents_rejects_negative_and_overflow(): void
    {
        $this->assertNull(MoneyAmount::majorToCents(-5));
        $this->assertNull(MoneyAmount::majorToCents(PHP_INT_MAX));
        $this->assertNull(MoneyAmount::majorToCents('99999999999999999999'));
        $this->assertNull(MoneyAmount::majorToCents('99999999999999999999.00'));
        $this->assertNull(MoneyAmount::majorToCents('-1.00'));
    }

    public function test_major_to_cents_normal_values_unchanged(): void
    {
        $this->assertSame(2900, MoneyAmount::majorToCents(29));
        $this->assertSame(2900, MoneyAmount::majorToCents('29.00'));
        $this->assertSame(2950, MoneyAmount::majorToCents('29.5'));
        $this->assertSame(0, MoneyAmount::majorToCents(0));
        $this->assertSame(1, MoneyAmount::majorToCents('0.01'));
    }

    public function test_cents_rejects_out_of_range_strings(): void
    {
        $this->assertNull(MoneyAmount::cents('99999999999999999999'));
        $this->assertNull(MoneyAmount::cents(-1));
        $this->assertSame(PHP_INT_MAX, MoneyAmount::cents((string) PHP_INT_MAX));
        $this->assertSame(100, MoneyAmount::cents('100'));
        $this->assertSame(0, MoneyAmount::cents(0));
    }

    public function test_price_to_cents_is_decimal_exact(): void
    {
        // The motivating defect: (int)(19.99 * 100) === 1998.
        $this->assertSame(1999, MoneyAmount::priceToCents(19.99));
        $this->assertSame(1999, MoneyAmount::priceToCents('19.99'));
        $this->assertSame(2900, MoneyAmount::priceToCents(29));
        $this->assertSame(10, MoneyAmount::priceToCents(0.1));
        $this->assertNull(MoneyAmount::priceToCents(-0.01));
        $this->assertNull(MoneyAmount::priceToCents('abc'));
        $this->assertNull(MoneyAmount::priceToCents(null));
    }

    public function test_percent_of_floors_in_integer_cents(): void
    {
        $this->assertSame(999, MoneyAmount::percentOf(1999, 50));
        $this->assertSame(199, MoneyAmount::percentOf(1999, 10));
        $this->assertSame(33, MoneyAmount::percentOf(100, 33));
        $this->assertSame(125, MoneyAmount::percentOf(1000, 12.5));
        $this->assertSame(0, MoneyAmount::percentOf(1999, 0));
        $this->assertSame(0, MoneyAmount::percentOf(-100, 50));
        $this->assertSame(0, MoneyAmount::percentOf(100, -5));
    }
}
