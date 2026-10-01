<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreEmployeeAction
{
    public function execute(Employee $employee, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('restore', $employee);

        return DB::transaction(fn (): Employee => $this->perform($employee));
    }

    /** Sans contrôle de droit : la corbeille (ADR-236) a vérifié les siens. */
    public function perform(Employee $employee): Employee
    {
        $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());
        $employee->restore();

        return $employee->refresh();
    }
}
