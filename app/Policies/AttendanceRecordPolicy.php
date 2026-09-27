<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

class AttendanceRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.view');
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        return $user->can('attendance.view');
    }

    public function create(User $user): bool
    {
        return $user->can('attendance.create');
    }

    public function update(User $user, AttendanceRecord $record): bool
    {
        return $user->can('attendance.update');
    }
}
