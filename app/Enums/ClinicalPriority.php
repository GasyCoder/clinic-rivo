<?php

namespace App\Enums;

/**
 * How fast the receiving service is asked to act.
 *
 * Deliberately three values and no more: the surgical referral already
 * carried LOW/NORMAL/URGENT as a bare string stuffed into its notes, so
 * this enum types what was already being sent rather than inventing a new
 * scale. It is a request from the doctor, never a promise from the
 * destination — who actually takes the patient first stays the receiving
 * service's decision.
 */
enum ClinicalPriority: string
{
    case Low = 'LOW';
    case Normal = 'NORMAL';
    case Urgent = 'URGENT';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Basse',
            self::Normal => 'Normale',
            self::Urgent => 'Urgente',
        };
    }

    public function isUrgent(): bool
    {
        return $this === self::Urgent;
    }
}
