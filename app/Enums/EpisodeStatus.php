<?php

namespace App\Enums;

/**
 * Global lifecycle of an episode — not specified by the CDC (§21 lists the
 * `status` column but no values); confirmed with the team. OPEN at
 * reception, CLOSED once the patient's care is complete, CANCELLED if the
 * episode itself was invalid (error, duplicate). Cancellation is the
 * CDC-preferred alternative to deletion for critical records (ADR-010) —
 * episodes are never soft-deleted, only cancelled, see Episode::cancel().
 */
enum EpisodeStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
