<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->can('employees.view');
    }

    public function create(User $user): bool
    {
        return $user->can('employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.update') && ! $employee->trashed();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->can('employees.delete') && ! $employee->trashed();
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $user->can('employees.restore') && $employee->trashed();
    }

    public function forceDelete(User $user, Employee $employee): bool
    {
        // The official Employee catalog defines no force-delete permission.
        // Historical HR/clinical/financial references remain protected by
        // Employee::isForceDeleteProtected() as a second model-level guard.
        return false;
    }
}
