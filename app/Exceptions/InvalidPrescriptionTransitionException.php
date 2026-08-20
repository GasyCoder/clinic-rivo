<?php

namespace App\Exceptions;

use App\Models\Prescription;
use RuntimeException;

/**
 * Mirrors InvalidEpisodeTransitionException's shape for Prescription's own
 * status column — raised when cancel() is attempted on a prescription
 * that's already CANCELLED.
 */
class InvalidPrescriptionTransitionException extends RuntimeException
{
    public function __construct(public readonly Prescription $prescription, string $attempted, string $from)
    {
        parent::__construct(sprintf(
            'Impossible de passer la prescription #%s au statut %s : elle est déjà %s.',
            $prescription->getKey(),
            $attempted,
            $from,
        ));
    }
}
