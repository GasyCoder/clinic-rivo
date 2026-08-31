<?php

namespace App\Http\Controllers\Administration;

use App\Enums\HrReferenceType;
use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('hr_reports.view'), 403);
        [$from, $to] = $this->period($request);

        return Inertia::render('Administration/Reports/Index', [
            'report' => $this->report($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function print(Request $request): Response
    {
        abort_unless($request->user()->can('hr_reports.print'), 403);
        [$from, $to] = $this->period($request);

        return Inertia::render('Administration/Reports/Print', [
            'report' => $this->report($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('hr_reports.export'), 403);
        [$from, $to] = $this->period($request);
        $report = $this->report($from, $to);
        $rows = collect([
            ['Période début', $from], ['Période fin', $to],
            ['Employés actifs', $report['summary']['active_employees']],
            ['Contrats en cours', $report['summary']['current_contracts']],
            ['Sessions de présence', $report['summary']['attendance_sessions']],
            ['Minutes de présence terminées', $report['summary']['attendance_minutes']],
            ['Congés acceptés', $report['summary']['approved_leave']],
            ['Congés en attente', $report['summary']['pending_leave']],
            ['Créneaux planifiés', $report['summary']['planning_shifts']],
        ]);
        foreach ($report['by_department'] as $department) {
            $rows->push(['Effectif — '.$department['label'], $department['count']]);
        }

        return $excel->download("rapport-rh-{$from}-{$to}", 'Rapport RH', ['Indicateur', 'Valeur'], $rows);
    }

    /** @return array<string, mixed> */
    private function report(string $from, string $to): array
    {
        $today = now()->toDateString();
        $attendance = AttendanceRecord::query()->whereBetween('work_date', [$from, $to])->get(['started_at', 'ended_at']);

        return [
            'summary' => [
                'active_employees' => Employee::query()->where('active', true)->count(),
                'current_contracts' => EmploymentContract::query()->whereDate('starts_on', '<=', $today)
                    ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->count(),
                'attendance_sessions' => $attendance->count(),
                'attendance_minutes' => $attendance->sum(fn ($record) => $record->ended_at
                    ? max(0, (int) $record->started_at->diffInMinutes($record->ended_at)) : 0),
                'approved_leave' => LeaveRequest::query()->where('status', LeaveRequestStatus::Approved->value)
                    ->whereBetween('starts_on', [$from, $to])->count(),
                'pending_leave' => LeaveRequest::query()->where('status', LeaveRequestStatus::Pending->value)->count(),
                'planning_shifts' => PlanningShift::query()->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
            ],
            'by_department' => HrReferenceValue::query()->ofType(HrReferenceType::Department)
                ->withCount(['departmentEmployees' => fn ($query) => $query->where('active', true)])
                ->orderBy('position')->get()->map(fn ($department) => [
                    'uuid' => $department->uuid, 'label' => $department->label,
                    'count' => $department->department_employees_count,
                ]),
            'leave_statuses' => collect(LeaveRequestStatus::cases())->map(fn ($status) => [
                'status' => $status->value, 'label' => $status->label(),
                'count' => LeaveRequest::query()->where('status', $status->value)
                    ->whereBetween('starts_on', [$from, $to])->count(),
            ]),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        return [
            $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            $request->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString(),
        ];
    }
}
