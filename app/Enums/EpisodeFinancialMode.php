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
            // « Standard » et non « Patient » : c'est le barème appliqué qui
            // nomme ce mode sur tous les écrans, et « Patient » se confondait
            // avec la personne elle-même. Voir resources/js/utilities/
            // financialMode.js, qui reprend exactement ces libellés.
            self::Self => 'Standard',
            self::Mutual => 'Mutuelle',
            self::Staff => 'Personnel',
            self::Partner => 'Partenaire',
        };
    }
}
