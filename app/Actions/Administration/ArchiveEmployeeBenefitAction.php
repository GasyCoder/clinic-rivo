<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * ADR-221 — retirer un avantage déclaré : il s'archive avec son motif, jamais
 * supprimé (ADR-009). Pour qu'il s'arrête à une date, on renseigne sa fin.
 */
class ArchiveEmployeeBenefitAction
{
    public function execute(Employee $employee, EmployeeBenefit $benefit, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $employee);

        if (! $actor->can('employees.payroll.update')) {
            throw new AuthorizationException('Retirer un avantage demande le droit « employees.payroll.update ».');
        }

        abort_unless($benefit->employee_id === $employee->getKey() && ! $benefit->trashed(), 404);

        $benefit->delete_reason = $reason;
        $benefit->delete();
    }
}
