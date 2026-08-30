<?php

namespace App\Enums;

enum PharmacyStockAlertType: string
{
    case OutOfStock = 'OUT_OF_STOCK';
    case LowStock = 'LOW_STOCK';

    public function label(): string
    {
        return match ($this) {
            self::OutOfStock => 'Rupture de stock',
            self::LowStock => 'Seuil de réapprovisionnement atteint',
        };
    }
}
