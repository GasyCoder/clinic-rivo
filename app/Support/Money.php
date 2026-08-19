<?php

namespace App\Support;

final class Money
{
    public static function toMinor(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function fromMinor(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    public static function multiply(int|float|string $quantity, int|float|string $unitPrice): int
    {
        $quantityHundredths = (int) round((float) $quantity * 100);

        return (int) round(($quantityHundredths * self::toMinor($unitPrice)) / 100);
    }
}
