<?php

namespace App\Policies;

use App\Models\PlanningShift;
use App\Models\User;

class PlanningShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('planning.view');
    }

    public function view(User $user, PlanningShift $shift): bool
    {
        return $user->can('planning.view');
    }

    public function create(User $user): bool
    {
        return $user->can('planning.create');
    }

    public function update(User $user, PlanningShift $shift): bool
    {
        return $user->can('planning.update');
    }
}
