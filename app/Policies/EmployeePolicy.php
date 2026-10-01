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
        // ADR-236 — seulement un dossier déjà archivé ; qu'il n'ait servi nulle part, c'est
        // Employee::isForceDeleteProtected() qui le vérifie, au moment de le détruire.
        return $user->can('employees.force_delete') && $employee->trashed();
    }
}
