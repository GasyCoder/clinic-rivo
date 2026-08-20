<?php

namespace App\Exceptions;

use App\Models\SurgicalRequest;
use RuntimeException;

/**
 * Mirrors InvalidEpisodeTransitionException/InvalidPrescriptionTransition
 * Exception for SurgicalRequest's own status column.
 */
class InvalidSurgicalRequestTransitionException extends RuntimeException
{
    public function __construct(public readonly SurgicalRequest $surgicalRequest, string $attempted, string $from)
    {
        parent::__construct(sprintf(
            'Impossible de passer la demande de chirurgie #%s au statut %s : elle est %s.',
            $surgicalRequest->getKey(),
            $attempted,
            $from,
        ));
    }
}
