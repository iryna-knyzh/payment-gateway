<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Money;

use App\Payment\Money\MinorUnits;
use PHPUnit\Framework\TestCase;

final class MinorUnitsTest extends TestCase
{
    /**
     * @dataProvider provideConversions
     */
    public function testToDecimalString(int $minor, string $currency, string $decimal): void
    {
        self::assertSame($decimal, MinorUnits::toDecimalString($minor, $currency));
    }

    /**
     * @dataProvider provideConversions
     */
    public function testFromDecimalString(int $minor, string $currency, string $decimal): void
    {
        self::assertSame($minor, MinorUnits::fromDecimalString($decimal, $currency));
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function provideConversions(): iterable
    {
        yield 'regular' => [1050, 'UAH', '10.50'];
        yield 'less than one unit' => [5, 'USD', '0.05'];
        yield 'zero' => [0, 'EUR', '0.00'];
        yield 'zero-decimal currency' => [500, 'JPY', '500'];
        yield 'three-decimal currency' => [1234, 'KWD', '1.234'];
        yield 'large amount' => [99999999999, 'UAH', '999999999.99'];
    }

    public function testAcceptsShortFraction(): void
    {
        self::assertSame(1050, MinorUnits::fromDecimalString('10.5', 'UAH'));
        self::assertSame(1000, MinorUnits::fromDecimalString('10', 'UAH'));
    }

    public function testFloatWouldHaveLostACent(): void
    {
        // The reason money is never a float: (int) (19.99 * 100) === 1998
        self::assertSame(1998, (int) (19.99 * 100));
        self::assertSame(1999, MinorUnits::fromDecimalString('19.99', 'UAH'));
    }

    /**
     * @dataProvider provideInvalidDecimals
     */
    public function testRejectsInvalidDecimal(string $decimal): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MinorUnits::fromDecimalString($decimal, 'UAH');
    }

    /** @return iterable<array{string}> */
    public static function provideInvalidDecimals(): iterable
    {
        yield ['10.505'];   // more precision than the currency has
        yield ['-1.00'];
        yield ['1,50'];
        yield ['abc'];
        yield [''];
        yield ['1e3'];
    }
}
