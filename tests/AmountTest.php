<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use InvalidArgumentException;
use Karnoweb\Accounting\Support\Amount;

class AmountTest extends TestCase
{
    public function test_decimal_addition_is_exact(): void
    {
        $sum = Amount::of('0.10')->add('0.20');

        $this->assertSame('0.30', $sum->toStorage());
        $this->assertTrue($sum->equals('0.30'));
        $this->assertTrue($sum->equals(Amount::of('0.3')));
        $this->assertFalse(0.1 + 0.2 === 0.3);
    }

    public function test_decimal_subtraction_is_exact(): void
    {
        $difference = Amount::of('0.30')->subtract('0.10');

        $this->assertSame('0.20', $difference->toStorage());
        $this->assertTrue($difference->equals('0.20'));
    }

    public function test_sum_of_classic_float_trap_values(): void
    {
        $total = Amount::sum(['0.10', '0.20', '0.30', '0.01']);

        $this->assertSame('0.61', $total->toStorage());
        $this->assertTrue($total->equals('0.61'));
    }

    public function test_zero_and_comparison_are_deterministic(): void
    {
        $this->assertTrue(Amount::of('0.00')->isZero());
        $this->assertTrue(Amount::of('0.10')->subtract('0.10')->isZero());
        $this->assertFalse(Amount::of('0.01')->isZero());
        $this->assertTrue(Amount::of('0.01')->isPositive());
        $this->assertTrue(Amount::of('-0.01')->isNegative());
        $this->assertSame(0, Amount::of('1.00')->compare('1.00'));
        $this->assertSame(1, Amount::of('1.00')->compare('0.99'));
        $this->assertSame(-1, Amount::of('0.99')->compare('1.00'));
    }

    public function test_negate_and_signed(): void
    {
        $this->assertSame('-100.00', Amount::of('100')->negate()->toStorage());
        $this->assertSame('100.00', Amount::of('100')->signed(1)->toStorage());
        $this->assertSame('-100.00', Amount::of('100')->signed(-1)->toStorage());
        $this->assertSame('12.34', Amount::of('-12.34')->abs()->toStorage());
    }

    public function test_large_supported_amount(): void
    {
        $amount = Amount::of('999999999999.99');

        $this->assertSame('999999999999.99', $amount->toStorage());
        $this->assertSame('1999999999999.98', $amount->add($amount)->toStorage());
    }

    public function test_tiny_supported_decimal(): void
    {
        $amount = Amount::of('0.01');

        $this->assertSame('0.01', $amount->toStorage());
        $this->assertFalse($amount->isZero());
        $this->assertTrue($amount->subtract('0.01')->isZero());
    }

    public function test_inbound_float_is_normalized_not_calculated(): void
    {
        $fromFloatSum = Amount::of(0.1 + 0.2);

        $this->assertSame('0.30', $fromFloatSum->toStorage());
        $this->assertTrue($fromFloatSum->equals(Amount::of('0.10')->add('0.20')));
    }

    public function test_half_away_from_zero_rounding_matches_php_round(): void
    {
        $this->assertSame('1.24', Amount::of('1.235')->toStorage());
        $this->assertSame('1.23', Amount::of('1.234')->toStorage());
        $this->assertSame('-1.24', Amount::of('-1.235')->toStorage());
        $this->assertSame('0.01', Amount::of('0.005')->toStorage());
    }

    public function test_rejects_invalid_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Amount::of('not-a-number');
    }

    public function test_rejects_scientific_notation_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Amount::of('1e-2');
    }
}
