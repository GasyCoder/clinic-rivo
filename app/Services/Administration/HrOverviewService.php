<?php

namespace App\Services\Administration;

use App\Enums\LeaveRequestStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use App\Services\Catalog\CatalogActor;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-066 — the HR figures, computed in one place for the three screens that
 * show them: the site overview, the HR space and the central portal (through
 * the site API). The same key always means the same count.
 */
final class HrOverviewService
{
    public function __construct(
        private readonly InternshipDirectory $internships,
        private readonly LeaveToday $leaveToday,
    ) {}

    /** Figures that belong to a screen permission; hidden from accounts without it. */
    private const GUARDED = [
        'current_contracts' => 'contracts.view',
        'contracts_ending_soon' => 'contracts.view',
        'today_attendance' => 'attendance.view',
        'open_attendance' => 'attendance.view',
        'pending_leave' => 'leave.view',
        'upcoming_shifts' => 'planning.view',
        'on_leave_today' => 'leave.view',
    ];

    /** @return array<string, int> */
    public function summary(): array
    {
        $today = now()->toDateString();

        return [
            // ADR-198 — les stagiaires ont leurs propres écrans : ils ne comptent pas parmi les employés.
            'active_employees' => $this->staff()->where('active', true)->count(),
            'inactive_employees' => $this->staff()->where('active', false)->count(),
            'archived_employees' => $this->internships->withoutInterns(Employee::onlyTrashed())->count(),
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
            'on_leave_today' => $this->leaveToday->count(),
        ];
    }

    /**
     * What the central portal receives: the same figures, masked by the remote
     * actor's permissions, plus the headcount by department.
     *
     * @return array<string, mixed>
     */
    public function overview(CatalogActor $actor): array
    {
        $summary = $this->summary();

        foreach (self::GUARDED as $key => $permission) {
            if ($actor->cannot($permission)) {
                $summary[$key] = null;
            }
        }

        return [
            'summary' => $summary,
            'departments' => $this->departments(),
            'permissions' => [
                'contracts' => $actor->can('contracts.view'),
                'attendance' => $actor->can('attendance.view'),
                'leave' => $actor->can('leave.view'),
                'planning' => $actor->can('planning.view'),
            ],
        ];
    }

    /**
     * L'effectif actif par département, du plus grand au plus petit : lu par
     * l'accueil RH du site et, par son API, par le portail. « Non affecté »
     * regroupe les dossiers sans département.
     *
     * @return list<array{uuid: ?string, label: string, employees_count: int}>
     */
    public function departments(): array
    {
        return $this->staff()
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
            ])->values()->all();
    }

    /** Les dossiers du personnel, stagiaires exclus (ADR-198). */
    private function staff(): Builder
    {
        return $this->internships->withoutInterns(Employee::query());
    }
}
