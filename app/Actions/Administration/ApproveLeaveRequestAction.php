<?php

namespace App\Actions\Administration;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ApproveLeaveRequestAction
{
    public function execute(LeaveRequest $leave, ?string $reason, User $actor): LeaveRequest
    {
        Gate::forUser($actor)->authorize('approve', $leave);
        $leave->decide(LeaveRequestStatus::Approved, $reason);

        return $leave->refresh();
    }
}
