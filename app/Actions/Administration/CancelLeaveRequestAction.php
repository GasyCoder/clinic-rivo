<?php

namespace App\Actions\Administration;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CancelLeaveRequestAction
{
    public function execute(LeaveRequest $leave, string $reason, User $actor): LeaveRequest
    {
        Gate::forUser($actor)->authorize('cancel', $leave);
        $leave->cancel($reason);

        return $leave->refresh();
    }
}
