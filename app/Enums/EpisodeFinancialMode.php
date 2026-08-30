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
    // A commercial/institutional partner (ISPSG, TsaraShop…), distinct from
    // Mutuelle: coverage is meant to be scoped to specific prestations (e.g.
    // chambre, lit), never a percentage of the whole tariff grid. That
    // per-item scoping isn't designed yet (no Hospitalisation catalogue), so
    // a Partner Episode bills at 0% coverage — same as Self — until it is:
    // the patient can still pay directly, now or later.
    case Partner = 'PARTNER';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'Patient',
            self::Mutual => 'Mutuelle',
            self::Staff => 'Personnel',
            self::Partner => 'Partenaire',
        };
    }
}
