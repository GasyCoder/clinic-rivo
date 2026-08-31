<?php

namespace App\Actions\Administration;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RejectLeaveRequestAction
{
    public function execute(LeaveRequest $leave, string $reason, User $actor): LeaveRequest
    {
        Gate::forUser($actor)->authorize('reject', $leave);
        $leave->decide(LeaveRequestStatus::Rejected, $reason);

        return $leave->refresh();
    }
}
