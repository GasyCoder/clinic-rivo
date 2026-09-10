<?php

namespace App\Enums;

/**
 * How a tender is grouped for the cashier. Purely organisational: what a
 * payment actually does to the till stays governed by
 * `payment_methods.affects_cash_balance`, never deduced from this category.
 *
 * MOBILE_MONEY exists because in Madagascar it is never a single tender —
 * MVola, Orange Money and Airtel Money are reconciled separately, each with
 * its own account, so each is its own method under one shared category.
 */
enum PaymentMethodCategory: string
{
    case Cash = 'CASH';
    case MobileMoney = 'MOBILE_MONEY';
    case Bank = 'BANK';
    case Coverage = 'COVERAGE';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::MobileMoney => 'Mobile money',
            self::Bank => 'Banque',
            self::Coverage => 'Prise en charge',
            self::Other => 'Autre',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'coins',
            self::MobileMoney => 'mobile',
            self::Bank => 'building',
            self::Coverage => 'shield-check',
            self::Other => 'card-view',
        };
    }

    /** Display order for the cashier: the most frequent tenders first. */
    public function position(): int
    {
        return match ($this) {
            self::Cash => 1,
            self::MobileMoney => 2,
            self::Bank => 3,
            self::Coverage => 4,
            self::Other => 5,
        };
    }
}
