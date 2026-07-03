<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Support;

use ImaginaPay\Support\Money;
use ImaginaPay\Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function testCreatesFromMinorUnits(): void
    {
        $money = Money::of(4990000, 'COP');

        $this->assertSame(4990000, $money->amount);
        $this->assertSame('COP', $money->currency);
    }

    public function testNormalizesCurrencyToUppercase(): void
    {
        $this->assertSame('USD', Money::of(100, 'usd')->currency);
    }

    public function testRejectsInvalidCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(100, 'PESOS');
    }

    public function testRejectsNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(-1, 'COP');
    }

    public function testAddsSameCurrency(): void
    {
        $result = Money::of(1000, 'COP')->add(Money::of(500, 'COP'));

        $this->assertSame(1500, $result->amount);
    }

    public function testAddRejectsDifferentCurrencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(1000, 'COP')->add(Money::of(500, 'USD'));
    }

    public function testSubtracts(): void
    {
        $result = Money::of(1000, 'USD')->subtract(Money::of(400, 'USD'));

        $this->assertSame(600, $result->amount);
    }

    public function testSubtractRejectsNegativeResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(400, 'USD')->subtract(Money::of(1000, 'USD'));
    }

    public function testMultiplies(): void
    {
        $result = Money::of(4990000, 'COP')->multiply(12);

        $this->assertSame(59880000, $result->amount);
    }

    public function testMultiplyRejectsNegativeFactor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(100, 'COP')->multiply(-2);
    }

    public function testImmutability(): void
    {
        $original = Money::of(1000, 'COP');
        $original->add(Money::of(500, 'COP'));

        $this->assertSame(1000, $original->amount);
    }

    public function testFormatsCopWithoutDecimals(): void
    {
        $this->assertSame('$ 49.900 COP', Money::of(4990000, 'COP')->format());
    }

    public function testFormatsCopWithCentsWhenPresent(): void
    {
        $this->assertSame('$ 49.900,50 COP', Money::of(4990050, 'COP')->format());
    }

    public function testFormatsUsdWithDecimals(): void
    {
        $this->assertSame('$ 12,99 USD', Money::of(1299, 'USD')->format());
    }

    public function testEquals(): void
    {
        $this->assertTrue(Money::of(100, 'COP')->equals(Money::of(100, 'COP')));
        $this->assertFalse(Money::of(100, 'COP')->equals(Money::of(100, 'USD')));
        $this->assertFalse(Money::of(100, 'COP')->equals(Money::of(200, 'COP')));
    }

    public function testZero(): void
    {
        $this->assertTrue(Money::zero('COP')->isZero());
    }

    public function testGreaterThan(): void
    {
        $this->assertTrue(Money::of(200, 'COP')->greaterThan(Money::of(100, 'COP')));
        $this->assertFalse(Money::of(100, 'COP')->greaterThan(Money::of(200, 'COP')));
    }

    public function testJsonSerialization(): void
    {
        $this->assertSame(
            ['amount' => 1299, 'currency' => 'USD', 'formatted' => '$ 12,99 USD'],
            Money::of(1299, 'USD')->jsonSerialize(),
        );
    }
}
