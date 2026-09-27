<?php

namespace App\Policies;

use App\Models\EmploymentContract;
use App\Models\User;

class EmploymentContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contracts.view');
    }

    public function view(User $user, EmploymentContract $contract): bool
    {
        return $user->can('contracts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.create');
    }

    public function update(User $user, EmploymentContract $contract): bool
    {
        return $user->can('contracts.update') && ! $contract->trashed();
    }

    public function delete(User $user, EmploymentContract $contract): bool
    {
        return $user->can('contracts.archive') && ! $contract->trashed();
    }

    public function restore(User $user, EmploymentContract $contract): bool
    {
        return $user->can('contracts.restore') && $contract->trashed();
    }

    public function forceDelete(User $user, EmploymentContract $contract): bool
    {
        return false;
    }
}
