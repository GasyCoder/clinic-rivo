<?php

namespace App\Enums;

/**
 * Patient's progress through the site's reception/orientation circuit
 * (CDC §12) — not specified by the CDC as an enum (§21 lists the
 * `administrative_status` column but no values); confirmed with the team.
 * Kept deliberately separate from EpisodeStatus (global) and from
 * medical_status/financial_status (owned by future modules), per §21's
 * "le statut médical, financier et administratif doit rester séparé".
 */
enum EpisodeAdministrativeStatus: string
{
    case PendingOrientation = 'PENDING_ORIENTATION';
    case Oriented = 'ORIENTED';
    case InService = 'IN_SERVICE';
    case Discharged = 'DISCHARGED';
}
