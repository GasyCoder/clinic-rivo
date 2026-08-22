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
    /**
     * $firstName is nullable — first_name is optional on Patient, and NULL
     * never equals NULL in SQL, so two patients who both omitted it must
     * still compare as matching, hence the '' normalization below.
     *
     * @return Collection<int, Patient>
     */
    public function find(
        ?string $firstName,
        string $lastName,
        ?string $birthDate,
        ?int $declaredAge = null,
    ): Collection {
        return Patient::query()
            ->whereRaw('LOWER(TRIM(COALESCE(first_name, \'\'))) = ?', [Str::lower(trim($firstName ?? ''))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [Str::lower(trim($lastName))])
            ->when(
                $birthDate,
                fn ($query) => $query->whereDate('birth_date', $birthDate),
                fn ($query) => $query->whereNull('birth_date')->where('declared_age', $declaredAge),
            )
            ->get();
    }
}
