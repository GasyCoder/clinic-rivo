<?php

namespace App\Support\Hr;

use App\Models\Employee;
use App\Models\EmploymentContract;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Un salarié n'a qu'un contrat à la fois : deux contrats non archivés ne se chevauchent
 * pas. C'est une garde d'intégrité, comme pour les présences et les congés (ADR-066) — la
 * même personne en CDI et en CDD le même jour, ou un contrat saisi deux fois, rendrait
 * faux le contrat « en cours » que lisent les stages (ADR-207), les documents (ADR-208)
 * et la paie. Un nouveau contrat se saisit une fois l'ancien terminé (date de fin) ou
 * archivé. La période d'essai, elle, tient dans le contrat.
 */
class ContractPeriodGuard
{
    /** À appeler dans la transaction, salarié verrouillé. */
    public static function ensure(Employee $employee, ?string $startsOn, ?string $endsOn, ?string $trialEndsOn, ?EmploymentContract $ignore = null): void
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = filled($endsOn) ? CarbonImmutable::parse($endsOn)->startOfDay() : null;

        if (filled($trialEndsOn) && $end !== null && CarbonImmutable::parse($trialEndsOn)->startOfDay()->gt($end)) {
            throw ValidationException::withMessages(['trial_ends_on' => 'La période d’essai ne peut pas finir après le contrat.']);
        }

        $overlap = EmploymentContract::query()
            ->with('contractType:id,label')
            ->where('employee_id', $employee->getKey())
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString()))
            ->when($end !== null, fn ($query) => $query->whereDate('starts_on', '<=', $end->toDateString()))
            ->orderBy('starts_on')
            ->first();

        if ($overlap !== null) {
            throw ValidationException::withMessages(['starts_on' => sprintf(
                'Ce salarié a déjà un contrat sur cette période : %s du %s %s. Donnez-lui une date de fin avant le début de celui-ci, ou archivez-le.',
                $overlap->contractType?->label ?? 'contrat',
                $overlap->starts_on->format('d/m/Y'),
                $overlap->ends_on ? 'au '.$overlap->ends_on->format('d/m/Y') : '(sans date de fin)',
            )]);
        }
    }
}
