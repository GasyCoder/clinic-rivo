<?php

namespace App\Services\Patient;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Anti-doublon (Roadmap Phase 1 — algorithm not specified by the CDC):
 * exact match on first name + last name (case/whitespace-insensitive) +
 * birth date. Deliberately not fuzzy — reduces false positives at the
 * cost of missing misspelled names, chosen as the simpler, more
 * predictable rule for a receptionist to reason about.
 */
class DuplicatePatientFinder
{
    /** @return Collection<int, Patient> */
    public function find(string $firstName, string $lastName, string $birthDate): Collection
    {
        return Patient::query()
            ->whereRaw('LOWER(first_name) = ?', [Str::lower(trim($firstName))])
            ->whereRaw('LOWER(last_name) = ?', [Str::lower(trim($lastName))])
            ->whereDate('birth_date', $birthDate)
            ->get();
    }
}
