<?php

namespace App\Enums;

/**
 * Where one step of the consultation actually stands.
 *
 * The distinction that matters clinically is between COMPLETED and
 * SKIPPED: a step the doctor declared unnecessary is not a step that was
 * medically carried out, and the record must never blur the two. Opening a
 * step, or saving a draft in it, never produces COMPLETED — that requires
 * the doctor's explicit validation.
 */
enum ConsultationStepStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Skipped = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Non commencée',
            self::InProgress => 'En cours',
            self::Completed => 'Terminée',
            self::Skipped => 'Non requise',
        };
    }

    /** Settled either way: the doctor has taken a decision about this step. */
    public function isResolved(): bool
    {
        return in_array($this, [self::Completed, self::Skipped], true);
    }
}
