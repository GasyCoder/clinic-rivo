<?php

namespace App\Enums;

/**
 * ADR-212 — une attribution de bonus : validée par les RH, puis marquée
 * versée (le versement se fait hors RIVO : aucune paie n'y est calculée,
 * ADR-066/206). Une attribution validée peut être annulée avec un motif ; une
 * attribution versée ne revient plus en arrière.
 */
enum BonusAwardStatus: string
{
    case Validated = 'VALIDATED';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Validated => 'Validé',
            self::Paid => 'Versé',
            self::Cancelled => 'Annulé',
        };
    }
}
