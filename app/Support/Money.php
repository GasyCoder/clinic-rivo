<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

final class Money
{
    /**
     * Convert an exact decimal representation to minor units without ever
     * passing through IEEE-754 floating point arithmetic.
     */
    public static function toMinor(int|string $amount): int
    {
        [$negative, $whole, $fraction] = self::parts($amount);
        $maxWhole = (string) intdiv(PHP_INT_MAX - 99, 100);

        if (strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            throw new OverflowException('Money amount exceeds the supported integer range.');
        }

        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative && $minor !== 0 ? -$minor : $minor;
    }

    public static function fromMinor(int $amount): string
    {
        $negative = $amount < 0;
        $absolute = abs($amount);
        $formatted = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-'.$formatted : $formatted;
    }

    public static function normalize(int|string $amount): string
    {
        return self::fromMinor(self::toMinor($amount));
    }

    public static function multiply(int|string $quantity, int|string $unitPrice): int
    {
        $quantityHundredths = self::toMinor($quantity);
        $unitPriceMinor = self::toMinor($unitPrice);

        if ($quantityHundredths !== 0
            && abs($unitPriceMinor) > intdiv(PHP_INT_MAX, abs($quantityHundredths))) {
            throw new OverflowException('Money multiplication exceeds the supported integer range.');
        }

        $product = $quantityHundredths * $unitPriceMinor;
        $rounded = intdiv(abs($product) + 50, 100);

        return $product < 0 ? -$rounded : $rounded;
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    private static function parts(int|string $amount): array
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Money values must be exact decimals with at most two digits after the point.');
        }

        $whole = ltrim($matches[2], '0');

        return [
            $matches[1] === '-',
            $whole === '' ? '0' : $whole,
            $matches[3] ?? '',
        ];
    }
}
