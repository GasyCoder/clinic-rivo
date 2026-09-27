<?php

namespace App\Enums;

enum SurgicalTreatmentPhase: string
{
    case Preliminary = 'PRELIMINARY';
    case Postoperative = 'POSTOPERATIVE';

    public function label(): string
    {
        return match ($this) {
            self::Preliminary => 'Traitement préliminaire',
            self::Postoperative => 'Traitement postopératoire',
        };
    }
}
