<?php

namespace App\Actions\Administration;

use App\Enums\EmployeeBenefitFrequency;
use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\HrReferenceValue;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ADR-221 — déclarer ou corriger un avantage (ou une prime) d'un employé.
 *
 *   droit       `employees.payroll.update`, revérifié ici (ADR-206) — jamais
 *               seulement par la requête ou l'écran
 *   qui         un nouvel avantage exige une fonction qui y ouvre droit
 *               (module Fonctions, arbitrage du propriétaire : « Médecin »
 *               cochée d'office). Corriger ou clore un avantage déjà déclaré
 *               reste possible si la fonction a changé depuis : l'historique
 *               ne se fige pas.
 *   ponctuel    une prime versée une fois n'a pas de fin
 *
 * Aucun total ni net n'est calculé (ADR-066).
 */
class SaveEmployeeBenefitAction
{
    /** @param array<string, mixed> $data */
    public function execute(Employee $employee, array $data, User $actor, ?EmployeeBenefit $benefit = null): EmployeeBenefit
    {
        Gate::forUser($actor)->authorize('update', $employee);

        if (! $actor->can('employees.payroll.update')) {
            throw new AuthorizationException('Déclarer un avantage demande le droit « employees.payroll.update ».');
        }

        return DB::transaction(function () use ($employee, $data, $actor, $benefit): EmployeeBenefit {
            $employee = Employee::query()->lockForUpdate()->with('jobTitle')->findOrFail($employee->getKey());

            if (! $benefit) {
                $this->ensureEligible($employee);
            } elseif ($benefit->employee_id !== $employee->getKey() || $benefit->trashed()) {
                abort(404);
            }

            if (array_key_exists('benefit_type_uuid', $data)) {
                $data['benefit_type_id'] = $this->type((string) $data['benefit_type_uuid'], $benefit)->getKey();
                unset($data['benefit_type_uuid']);
            }

            $frequency = EmployeeBenefitFrequency::tryFrom((string) ($data['frequency'] ?? $benefit?->frequency?->value ?? ''));
            if ($frequency === EmployeeBenefitFrequency::OneTime) {
                $data['ends_on'] = null;
            }

            $authored = $actor->exists ? $actor->getKey() : null;

            if ($benefit) {
                $benefit->fill([...$data, 'updated_by' => $authored])->save();

                return $benefit->refresh();
            }

            return EmployeeBenefit::query()->create([
                ...$data,
                'employee_id' => $employee->getKey(),
                'created_by' => $authored,
                ...RemoteActorAttribution::fields('created', $actor),
            ]);
        });
    }

    private function ensureEligible(Employee $employee): void
    {
        if ($employee->trashed() || ! $employee->active) {
            throw ValidationException::withMessages(['benefit_type_uuid' => 'Un dossier inactif ou archivé ne reçoit pas de nouvel avantage.']);
        }

        if (! $employee->grantsBenefits()) {
            throw ValidationException::withMessages(['benefit_type_uuid' => 'Les avantages ne sont pas ouverts pour cette personne : cochez « Avantages » à l’étape Rémunération.']);
        }
    }

    /** Un type actif ; celui qu'un avantage porte déjà reste accepté s'il a été archivé depuis. */
    private function type(string $uuid, ?EmployeeBenefit $benefit): HrReferenceValue
    {
        $type = HrReferenceValue::withTrashed()->ofType(HrReferenceType::BenefitType)->where('uuid', $uuid)->first();
        $keeps = $type && $benefit && $benefit->benefit_type_id === $type->getKey();

        if (! $type || ((! $type->active || $type->trashed()) && ! $keeps)) {
            throw ValidationException::withMessages(['benefit_type_uuid' => 'Ce type d’avantage n’est plus proposé.']);
        }

        return $type;
    }
}
