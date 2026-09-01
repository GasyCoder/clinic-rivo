<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\CreateAttendanceRecordAction;
use App\Actions\Administration\UpdateAttendanceRecordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StoreAttendanceRequest;
use App\Http\Requests\Administration\UpdateAttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Administration\HrPresenter;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', AttendanceRecord::class);
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString();
        $employeeUuid = $this->employeeUuid($request);

        $query = $this->recordsQuery($from, $to, $employeeUuid);
        $records = (clone $query)
            ->with(['employee.department', 'employee.jobTitle'])
            ->latest('started_at')->paginate(30)->withQueryString()
            ->through(fn ($record) => $this->presenter->attendance($record));

        return Inertia::render('Administration/Attendance/Index', [
            'records' => $records,
            'employees' => $this->employees(),
            'filters' => ['from' => $from, 'to' => $to, 'employee' => $employeeUuid],
            'summary' => [
                'sessions' => (clone $query)->count(),
                'employees' => (clone $query)->distinct('employee_id')->count('employee_id'),
                'open' => (clone $query)->whereNull('ended_at')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', AttendanceRecord::class);

        return Inertia::render('Administration/Attendance/Create', [
            'employees' => $this->employees(),
            'selectedEmployeeUuid' => $request->query('employee'),
            'defaultStartedAt' => now()->format('Y-m-d\TH:i'),
        ]);
    }

    public function edit(Request $request, AttendanceRecord $attendance): Response
    {
        Gate::forUser($request->user())->authorize('update', $attendance);
        $attendance->load(['employee.department', 'employee.jobTitle']);

        return Inertia::render('Administration/Attendance/Edit', [
            'record' => $this->presenter->attendance($attendance),
            'employees' => $this->employees(),
        ]);
    }

    public function store(StoreAttendanceRequest $request, CreateAttendanceRecordAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user());

        return to_route('administration.attendance.index')->with('status', 'Présence enregistrée.');
    }

    public function update(UpdateAttendanceRequest $request, AttendanceRecord $attendance, UpdateAttendanceRecordAction $action): RedirectResponse
    {
        $action->execute($attendance, $request->validated(), $request->user());

        return to_route('administration.attendance.index')->with('status', 'Présence corrigée.');
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('attendance.export'), 403);
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString();
        $records = $this->recordsQuery($from, $to, $this->employeeUuid($request))
            ->with('employee')->oldest('started_at')->get();

        return $excel->download("presences-{$from}-{$to}", 'Présences', [
            'UUID', 'Matricule', 'Employé', 'Date', 'Entrée', 'Sortie', 'Durée minutes', 'Observation',
        ], $records->map(fn ($record) => [
            $record->uuid, $record->employee->employee_number,
            trim($record->employee->last_name.' '.$record->employee->first_name),
            $record->work_date?->toDateString(), $record->started_at?->format('Y-m-d H:i'),
            $record->ended_at?->format('Y-m-d H:i'),
            $record->ended_at ? (int) $record->started_at->diffInMinutes($record->ended_at) : null,
            $record->observation,
        ]));
    }

    public function print(Request $request): Response
    {
        abort_unless($request->user()->can('attendance.print'), 403);
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString();

        return Inertia::render('Administration/Attendance/Print', [
            'records' => $this->recordsQuery($from, $to, $this->employeeUuid($request))
                ->with(['employee.department', 'employee.jobTitle'])->oldest('started_at')->get()
                ->map(fn ($record) => $this->presenter->attendance($record)),
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }

    private function employees()
    {
        return Employee::query()->where('active', true)->with(['department', 'jobTitle'])
            ->orderBy('last_name')->get()->map(fn ($employee) => $this->presenter->employeeOption($employee));
    }

    private function recordsQuery(string $from, string $to, ?string $employeeUuid = null): Builder
    {
        return AttendanceRecord::query()
            ->whereBetween('work_date', [$from, $to])
            ->when($employeeUuid, fn (Builder $query) => $query->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('uuid', $employeeUuid),
            ));
    }

    private function employeeUuid(Request $request): ?string
    {
        $employeeUuid = $request->query('employee');

        return is_string($employeeUuid) && $employeeUuid !== '' ? $employeeUuid : null;
    }
}
