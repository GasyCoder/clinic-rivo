<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('leave.view');
    }

    public function view(User $user, LeaveRequest $leave): bool
    {
        return $user->can('leave.view');
    }

    public function create(User $user): bool
    {
        return $user->can('leave.create');
    }

    public function approve(User $user, LeaveRequest $leave): bool
    {
        return $user->can('leave.approve');
    }

    public function reject(User $user, LeaveRequest $leave): bool
    {
        return $user->can('leave.reject');
    }

    public function cancel(User $user, LeaveRequest $leave): bool
    {
        return $user->can('leave.cancel');
    }
}
