<?php

namespace App\Enums;

/**
 * CDC GitHub §15 lists `prescriptions.cancel`, not `prescriptions.delete` —
 * unlike Consultation (which does get delete/restore), a prescription is
 * cancelled, never soft-deleted, matching ADR-010's rule for critical
 * medical data. Same status-based pattern as EpisodeStatus/Episode::cancel().
 */
enum PrescriptionStatus: string
{
    case Active = 'ACTIVE';
    case Cancelled = 'CANCELLED';
}
