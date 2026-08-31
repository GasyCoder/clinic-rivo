<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ArchiveEmployeeAction
{
    public function execute(Employee $employee, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $employee);

        DB::transaction(function () use ($employee, $reason): void {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $employee->delete_reason = str($reason)->squish()->toString();
            $employee->delete();
        });
    }
}
