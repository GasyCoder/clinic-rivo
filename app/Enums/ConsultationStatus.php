<?php

namespace App\Enums;

/**
 * The consultation's own lifecycle, distinct from the episode's
 * administrative and medical statuses.
 *
 * Until now closure was implicit — a consultation was "finished" when a
 * MedicalDischarge happened to exist. That conflated two different facts:
 * a discharge is one possible medical decision, not the only way an
 * encounter can end (a referral to Chirurgie or Maternité ends the
 * Médecine consultation without any discharge). This status records the
 * encounter's own state and is the single gate for "may this still be
 * edited".
 */
enum ConsultationStatus: string
{
    case Draft = 'DRAFT';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::InProgress => 'En cours',
            self::Completed => 'Clôturée',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * A completed consultation is never silently rewritten (ADR-010): a
     * correction after closure goes through a traced mechanism, not through
     * the ordinary save path.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::InProgress], true);
    }
}
