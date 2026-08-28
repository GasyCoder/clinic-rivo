<?php

namespace App\Enums;

/**
 * Who carries the financial responsibility for one Episode.
 *
 * This is deliberately independent from Episode::financial_status, which
 * remains the state of the account, and from the legacy Patient::patient_type.
 */
enum EpisodeFinancialMode: string
{
    case Self = 'SELF';
    case Mutual = 'MUTUAL';
    case Staff = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'Patient',
            self::Mutual => 'Mutuelle',
            self::Staff => 'Personnel',
        };
    }
}
