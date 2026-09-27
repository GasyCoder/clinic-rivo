<?php

namespace App\Enums;

/**
 * Where a request made by Médecine to a service that has no workspace yet
 * stands.
 *
 * Two values, on purpose. REQUESTED means the doctor asked; CANCELLED means
 * the doctor changed course before anyone acted. There is deliberately no
 * ADMITTED, ACCEPTED or COMPLETED: those would describe what the receiving
 * service does, and neither the CDC nor any decision defines who admits a
 * patient, into which bed, or who closes the stay. Adding them would be
 * inventing clinical process (ADR-084).
 */
enum MedicalRequestStatus: string
{
    case Requested = 'REQUESTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Transmise',
            self::Cancelled => 'Annulée',
        };
    }
}
