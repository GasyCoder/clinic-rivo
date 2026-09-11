<?php

namespace App\Actions\Administration;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Administration\LeaveBalanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApproveLeaveRequestAction
{
    public function __construct(private readonly LeaveBalanceCalculator $calculator) {}

    public function execute(LeaveRequest $leave, ?string $reason, User $actor): LeaveRequest
    {
        Gate::forUser($actor)->authorize('approve', $leave);

        return DB::transaction(function () use ($leave, $reason): LeaveRequest {
            $leave = LeaveRequest::query()->whereKey($leave)->lockForUpdate()->firstOrFail();
            $leave->employee()->lockForUpdate()->firstOrFail();
            $leave->load('leaveType');

            if ($leave->leaveType && $leave->consumes_balance_snapshot) {
                $preview = $this->calculator->preview(
                    $leave->employee,
                    $leave->leaveType,
                    CarbonImmutable::instance($leave->starts_on)->startOfDay(),
                    CarbonImmutable::instance($leave->returns_on)->startOfDay(),
                    $leave,
                    [
                        'consumes_annual_balance' => $leave->consumes_balance_snapshot,
                        'annual_quota_days' => $leave->annual_quota_snapshot,
                        'day_count_method' => $leave->day_count_method_snapshot,
                        'requires_approval' => $leave->requires_approval_snapshot,
                    ],
                );
                $this->calculator->assertSufficientBalance($preview);
                $leave->remaining_days_snapshot = $preview['balance_after_request'];
                $leave->projected_remaining_days_snapshot = $preview['projected_balance'];
                $leave->annual_quota_snapshot = $preview['annual_quota_days'];
                $leave->save();
            }

            $leave->decide(LeaveRequestStatus::Approved, $reason);

            return $leave->refresh();
        });
    }
}
