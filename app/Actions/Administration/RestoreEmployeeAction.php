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

        return DB::transaction(function () use ($employee): Employee {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());
            $employee->restore();

            return $employee->refresh();
        });
    }
}
