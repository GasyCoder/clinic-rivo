<?php

namespace App\Policies;

use App\Models\HrReferenceValue;
use App\Models\User;

class HrReferenceValuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr_settings.view');
    }

    public function view(User $user, HrReferenceValue $reference): bool
    {
        return $user->can('hr_settings.view');
    }

    public function create(User $user): bool
    {
        return $user->can('hr_settings.create');
    }

    public function update(User $user, HrReferenceValue $reference): bool
    {
        return $user->can('hr_settings.update') && ! $reference->trashed();
    }

    public function delete(User $user, HrReferenceValue $reference): bool
    {
        return $user->can('hr_settings.archive') && ! $reference->trashed();
    }

    public function restore(User $user, HrReferenceValue $reference): bool
    {
        return $user->can('hr_settings.restore') && $reference->trashed();
    }

    public function forceDelete(User $user, HrReferenceValue $reference): bool
    {
        return false;
    }
}
