<?php

namespace App\Enums;

/**
 * Where an orientation stands between "the doctor decided" and "the
 * receiving service has the request".
 *
 * SELECTED and SUBMITTED are genuinely different: a doctor who has chosen
 * Chirurgie but has not yet filled the request has decided nothing the
 * block can act on, and the consultation must not close on that. Changing
 * one's mind cancels rather than deletes (ADR-010) — the first intention
 * and its request stay readable.
 */
enum ConsultationOrientationStatus: string
{
    case Selected = 'SELECTED';
    case Submitted = 'SUBMITTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Selected => 'À configurer',
            self::Submitted => 'Transmise',
            self::Cancelled => 'Annulée',
        };
    }

    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }
}
