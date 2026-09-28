<?php

namespace App\Policies;

use App\Models\Bank;
use App\Models\User;

/**
 * ADR-213 — le module Banques suit les droits des autres référentiels RH
 * (Départements, Fonctions : `hr_settings.*`), aucune permission nouvelle.
 */
class BankPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr_settings.view');
    }

    public function view(User $user, Bank $bank): bool
    {
        return $user->can('hr_settings.view');
    }

    public function create(User $user): bool
    {
        return $user->can('hr_settings.create');
    }

    public function update(User $user, Bank $bank): bool
    {
        return $user->can('hr_settings.update') && ! $bank->trashed();
    }

    public function delete(User $user, Bank $bank): bool
    {
        return $user->can('hr_settings.archive') && ! $bank->trashed();
    }

    public function restore(User $user, Bank $bank): bool
    {
        return $user->can('hr_settings.restore') && $bank->trashed();
    }

    public function forceDelete(User $user, Bank $bank): bool
    {
        return false;
    }
}
