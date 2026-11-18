<?php

namespace Tests\Unit\Billing;

use App\Billing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_parses_decimal_dollars_into_nanodollars(string $dollars, int $nanos): void
    {
        $this->assertSame($nanos, Money::parse($dollars));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function amounts(): array
    {
        return [
            'a Pixelforge cost' => ['0.046125000', 46_125_000],
            'a rate with two decimals' => ['12.50', 12_500_000_000],
            'a whole number' => ['5', 5_000_000_000],
            'zero' => ['0.000000000', 0],
            'one nanodollar' => ['0.000000001', 1],
            'zeros past the ninth decimal' => ['0.1234567890', 123_456_789],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_refuses_anything_it_cannot_represent_exactly(string $dollars): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($dollars);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'finer than a nanodollar' => ['0.0000000001'],
            'scientific notation' => ['1e-9'],
            'negative' => ['-1.00'],
            'empty' => [''],
            'not a number' => ['free'],
            'trailing dot' => ['1.'],
        ];
    }

    public function test_it_formats_nanodollars_with_nine_decimals_like_pixelforge(): void
    {
        $this->assertSame('0.046125000', Money::format(46_125_000));
        $this->assertSame('0.000000000', Money::format(0));
        $this->assertSame('1234.500000000', Money::format(1_234_500_000_000));
        $this->assertSame('-0.381887500', Money::format(-381_887_500));
    }

    public function test_formatting_and_parsing_round_trip(): void
    {
        foreach ([0, 1, 999_999_999, 1_000_000_000, 169_080_000, 373_875_000] as $nanos) {
            $this->assertSame($nanos, Money::parse(Money::format($nanos)));
        }
    }
}
