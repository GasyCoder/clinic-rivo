<?php

namespace App\Enums;

enum CatalogItemType: string
{
    case Service = 'SERVICE';
    case Medicine = 'MEDICINE';
    case Consumable = 'CONSUMABLE';
    case Equipment = 'EQUIPMENT';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Prestation',
            self::Medicine => 'Médicament',
            self::Consumable => 'Consommable',
            self::Equipment => 'Équipement',
        };
    }

    public function mustBeBillable(): bool
    {
        return $this === self::Service;
    }

    public function mustBeStockable(): bool
    {
        return in_array($this, [self::Medicine, self::Consumable], true);
    }

    public function canBeStockable(): bool
    {
        return $this->mustBeStockable();
    }
}
