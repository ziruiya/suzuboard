<?php

namespace Tests\Unit\Utils;

use App\Utils\MoneyAmount;
use Tests\TestCase;

class MoneyAmountTest extends TestCase
{
    public function test_major_units_parse_to_integer_cents(): void
    {
        $this->assertSame(2900, MoneyAmount::majorToCents('29.00'));
        $this->assertSame(2900, MoneyAmount::majorToCents('29'));
        $this->assertSame(2950, MoneyAmount::majorToCents('29.5'));
        $this->assertSame(2955, MoneyAmount::majorToCents('29.55'));
        $this->assertSame(1, MoneyAmount::majorToCents('0.01'));
        $this->assertSame(0, MoneyAmount::majorToCents('0.00'));
        $this->assertSame(10000, MoneyAmount::majorToCents(100));
        $this->assertSame(123456789, MoneyAmount::majorToCents('1234567.89'));
    }

    public function test_malformed_major_amounts_are_rejected(): void
    {
        $this->assertNull(MoneyAmount::majorToCents(null));
        $this->assertNull(MoneyAmount::majorToCents(''));
        $this->assertNull(MoneyAmount::majorToCents('abc'));
        $this->assertNull(MoneyAmount::majorToCents('-29.00'));
        $this->assertNull(MoneyAmount::majorToCents('29.005'));
        $this->assertNull(MoneyAmount::majorToCents('2.9e1'));
        $this->assertNull(MoneyAmount::majorToCents(29.0));
        $this->assertNull(MoneyAmount::majorToCents(['29.00']));
    }

    public function test_cents_parse_only_accepts_integer_literals(): void
    {
        $this->assertSame(2900, MoneyAmount::cents('2900'));
        $this->assertSame(2900, MoneyAmount::cents(2900));
        $this->assertSame(0, MoneyAmount::cents('0'));
        $this->assertNull(MoneyAmount::cents('29.00'));
        $this->assertNull(MoneyAmount::cents('-100'));
        $this->assertNull(MoneyAmount::cents(-100));
        $this->assertNull(MoneyAmount::cents(null));
        $this->assertNull(MoneyAmount::cents(''));
    }
}
