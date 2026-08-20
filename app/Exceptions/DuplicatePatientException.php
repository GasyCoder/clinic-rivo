<?php

namespace App\Exceptions;

use App\Models\Patient;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Not a hard block — twins with the same name and birth date genuinely
 * exist. Raised so the caller (eventually: the Réception UI) can show the
 * match and ask the receptionist to confirm before creating a real
 * duplicate, per the anti-doublon rule the Roadmap requires and the CDC
 * itself does not define an algorithm for.
 */
class DuplicatePatientException extends RuntimeException
{
    /** @param  Collection<int, Patient>  $matches */
    public function __construct(public readonly Collection $matches)
    {
        parent::__construct(sprintf(
            '%d patient(s) existant(s) correspondent déjà à ce nom et cette date de naissance.',
            $matches->count(),
        ));
    }
}
