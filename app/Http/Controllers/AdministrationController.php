<?php

namespace App\Http\Controllers;

use App\Enums\LeaveRequestStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdministrationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $today = now()->toDateString();

        return Inertia::render('Administration/Index', [
            'summary' => [
                'active_employees' => Employee::query()->where('active', true)->count(),
                'current_contracts' => EmploymentContract::query()
                    ->whereDate('starts_on', '<=', $today)
                    ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                    ->count(),
                'contracts_ending_soon' => EmploymentContract::query()
                    ->whereBetween('ends_on', [$today, now()->addDays(30)->toDateString()])
                    ->count(),
                'today_attendance' => AttendanceRecord::query()->whereDate('work_date', $today)->distinct('employee_id')->count('employee_id'),
                'open_attendance' => AttendanceRecord::query()->whereNull('ended_at')->count(),
                'pending_leave' => LeaveRequest::query()->where('status', LeaveRequestStatus::Pending->value)->count(),
                'upcoming_shifts' => PlanningShift::query()->whereBetween('starts_at', [now(), now()->addDays(7)])->count(),
            ],
            'siteName' => config('rivo.site.name'),
        ]);
    }
}
