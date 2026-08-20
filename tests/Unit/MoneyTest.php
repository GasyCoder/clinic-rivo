<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function exactAmounts(): array
    {
        return [
            ['0', 0, '0.00'],
            ['0.01', 1, '0.01'],
            ['10.5', 1050, '10.50'],
            ['00123.40', 12340, '123.40'],
            ['-5.25', -525, '-5.25'],
        ];
    }

    #[DataProvider('exactAmounts')]
    public function test_decimal_values_are_converted_without_floating_point(
        string $input,
        int $minor,
        string $normalized,
    ): void {
        $this->assertSame($minor, Money::toMinor($input));
        $this->assertSame($normalized, Money::normalize($input));
    }

    public function test_decimal_multiplication_is_exact_and_rounded_to_minor_units(): void
    {
        $this->assertSame(400100, Money::multiply('2', '1500.50') + Money::multiply('1', '1000'));
        $this->assertSame(33, Money::multiply('0.33', '1.00'));
        $this->assertSame(1, Money::multiply('0.01', '0.50'));
    }

    public function test_more_than_two_decimal_places_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinor('10.001');
    }
}
