<?php

namespace App\Enums;

/**
 * ADR-227 — un avantage saisi attend la paie de son mois, puis est payé avec elle.
 * Un avantage payé ne se modifie ni ne se supprime plus : jamais payé deux fois.
 */
enum AdvantageEntryStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Paid => 'Payé',
        };
    }
}
