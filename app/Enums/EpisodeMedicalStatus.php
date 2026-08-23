<?php

namespace App\Enums;

/**
 * CDC §32 / ADR-035 — medical lifecycle is independent from the global,
 * administrative and financial lifecycles of an episode.
 */
enum EpisodeMedicalStatus: string
{
    case InCare = 'IN_CARE';
    case Hospitalized = 'HOSPITALIZED';
    case MedicallyDischarged = 'MEDICALLY_DISCHARGED';
    case Transferred = 'TRANSFERRED';
    case Deceased = 'DECEASED';

    public function label(): string
    {
        return match ($this) {
            self::InCare => 'En cours de soins',
            self::Hospitalized => 'Hospitalisé',
            self::MedicallyDischarged => 'Médicalement sorti',
            self::Transferred => 'Transféré / référé',
            self::Deceased => 'Décédé',
        };
    }
}
