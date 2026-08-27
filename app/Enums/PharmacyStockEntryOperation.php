<?php

namespace App\Enums;

/**
 * ADR-042: an explicit stock opening may only create a new lot, while an
 * entry adds physical quantity and may target either a new or existing lot.
 */
enum PharmacyStockEntryOperation: string
{
    case InitialStock = 'STOCK_INITIAL';
    case Entry = 'ENTREE';

    public function label(): string
    {
        return match ($this) {
            self::InitialStock => 'Stock initial',
            self::Entry => 'Entrée',
        };
    }
}
