<?php

namespace App\Services\Administration;

use App\Enums\LeaveRequestStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use App\Services\Catalog\CatalogActor;

final class HrOverviewService
{
    /** @return array<string, mixed> */
    public function overview(CatalogActor $actor): array
    {
        $today = now()->toDateString();

        return [
            'summary' => [
                'active_employees' => Employee::query()->where('active', true)->count(),
                'inactive_employees' => Employee::query()->where('active', false)->count(),
                'archived_employees' => Employee::onlyTrashed()->count(),
                'current_contracts' => $actor->can('contracts.view')
                    ? EmploymentContract::query()
                        ->whereDate('starts_on', '<=', $today)
                        ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                        ->count()
                    : null,
                'contracts_ending_soon' => $actor->can('contracts.view')
                    ? EmploymentContract::query()
                        ->whereBetween('ends_on', [$today, now()->addDays(30)->toDateString()])
                        ->count()
                    : null,
                'open_attendance' => $actor->can('attendance.view')
                    ? AttendanceRecord::query()->whereNull('ended_at')->count()
                    : null,
                'pending_leave' => $actor->can('leave.view')
                    ? LeaveRequest::query()->where('status', LeaveRequestStatus::Pending->value)->count()
                    : null,
                'upcoming_shifts' => $actor->can('planning.view')
                    ? PlanningShift::query()->whereBetween('starts_at', [now(), now()->addDays(7)])->count()
                    : null,
            ],
            'departments' => Employee::query()
                ->where('active', true)
                ->selectRaw('department_id, count(*) as employees_count')
                ->with(['department' => fn ($query) => $query->withTrashed()])
                ->groupBy('department_id')
                ->orderByDesc('employees_count')
                ->get()
                ->map(fn (Employee $employee) => [
                    'uuid' => $employee->department?->uuid,
                    'label' => $employee->department?->label ?? 'Non affecté',
                    'employees_count' => (int) $employee->employees_count,
                ])->values(),
            'permissions' => [
                'contracts' => $actor->can('contracts.view'),
                'attendance' => $actor->can('attendance.view'),
                'leave' => $actor->can('leave.view'),
                'planning' => $actor->can('planning.view'),
            ],
        ];
    }
}
