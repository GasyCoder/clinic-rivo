<?php

namespace App\Enums;

enum PharmacyStockAdjustmentType: string
{
    case Expiration = 'EXPIRATION';
    case Breakage = 'BREAKAGE';
    case Inventory = 'INVENTORY';

    public function label(): string
    {
        return match ($this) {
            self::Expiration => 'Péremption',
            self::Breakage => 'Casse / perte',
            self::Inventory => 'Inventaire physique',
        };
    }
}
