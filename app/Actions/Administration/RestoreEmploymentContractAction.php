<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\User;
use App\Support\Hr\ContractPeriodGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreEmploymentContractAction
{
    public function execute(EmploymentContract $contract, User $actor): EmploymentContract
    {
        Gate::forUser($actor)->authorize('restore', $contract);

        return DB::transaction(function () use ($contract): EmploymentContract {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($contract->employee_id);
            // Restaurer ne doit pas faire revenir un contrat par-dessus celui qui l'a remplacé.
            ContractPeriodGuard::ensure(
                $employee,
                $contract->starts_on?->toDateString(),
                $contract->ends_on?->toDateString(),
                $contract->trial_ends_on?->toDateString(),
                $contract,
            );
            $contract->restore();

            return $contract->refresh();
        });
    }
}
