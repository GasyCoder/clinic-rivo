<?php

namespace App\Enums;

/**
 * ADR-194 — les deux plannings de la clinique. Un créneau est du service
 * (le planning du personnel) ou une garde. Jour, nuit ou 24 h se lisent sur
 * ses heures : ce n'est pas une catégorie de plus.
 */
enum PlanningShiftKind: string
{
    case Shift = 'SHIFT';
    case OnCall = 'ON_CALL';

    public function label(): string
    {
        return match ($this) {
            self::Shift => 'Service',
            self::OnCall => 'Garde',
        };
    }

    public function planningLabel(): string
    {
        return match ($this) {
            self::Shift => 'Planning du personnel',
            self::OnCall => 'Planning de garde',
        };
    }
}
