<?php

namespace App\Enums;

/**
 * Commercial price list used to resolve a catalog item's gross tariff.
 *
 * STAFF is deliberately absent: staff benefits are a coverage calculation
 * owned by RH / Finance and must never be represented as a zero-price list.
 */
enum CatalogTariffCategory: string
{
    case Standard = 'STANDARD';
    case Mutual = 'MUTUAL';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Sans mutuelle',
            self::Mutual => 'Mutuelle',
        };
    }
}
