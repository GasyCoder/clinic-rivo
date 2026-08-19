<?php

namespace App\Enums;

/**
 * Global lifecycle of an episode — not specified by the CDC GitHub repo
 * (§21 lists the `status` column but no values); confirmed with the team.
 * OPEN at reception, CLOSED once the patient's administrative exit is
 * complete (client CDCF §34.1.3's "sorti/payé comptant","sorti/dette
 * validée","sorti/évadé" all collapse to CLOSED here — see
 * EpisodeAdministrativeStatus for the detailed breakdown once Facture/
 * Caisse exists), CANCELLED if the episode itself was invalid (error,
 * duplicate — client CDCF §36 "Annulé"). Cancellation is the CDC-preferred
 * alternative to deletion for critical records (ADR-010) — episodes are
 * never soft-deleted, only cancelled, see Episode::cancel().
 */
enum EpisodeStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
