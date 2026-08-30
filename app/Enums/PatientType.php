<?php

namespace App\Enums;

/**
 * Legacy administrative registration category.
 *
 * Kept for historical records and the transitional Reception screen only.
 * It must never determine the financial context or tariff of a new Episode;
 * EpisodeFinancialMode is the source of truth for those decisions.
 */
enum PatientType: string
{
    case Standard = 'STANDARD';
    case Mutual = 'MUTUAL';
    case Staff = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Patient standard',
            self::Mutual => 'Patient mutualiste',
            self::Staff => 'Personnel de la clinique',
        };
    }
}
