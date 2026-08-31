<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\CreatePlanningShiftAction;
use App\Actions\Administration\UpdatePlanningShiftAction;
use App\Enums\HrReferenceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StorePlanningRequest;
use App\Http\Requests\Administration\UpdatePlanningRequest;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\PlanningShift;
use App\Services\Administration\HrPresenter;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanningController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', PlanningShift::class);
        [$from, $to] = $this->period($request);

        $shifts = $this->query($from, $to)
            ->paginate(30)->withQueryString()
            ->through(fn ($shift) => $this->presenter->planning($shift));

        return Inertia::render('Administration/Planning/Index', [
            'shifts' => $shifts,
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'shifts' => PlanningShift::query()->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
                'employees' => PlanningShift::query()->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59'])->distinct('employee_id')->count('employee_id'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', PlanningShift::class);

        return Inertia::render('Administration/Planning/Create', [
            ...$this->formData(),
            'selectedEmployeeUuid' => $request->query('employee'),
        ]);
    }

    public function edit(Request $request, PlanningShift $planning): Response
    {
        Gate::forUser($request->user())->authorize('update', $planning);
        $planning->load(['employee.department', 'employee.jobTitle', 'department']);

        return Inertia::render('Administration/Planning/Edit', [
            ...$this->formData(),
            'shift' => $this->presenter->planning($planning),
        ]);
    }

    public function store(StorePlanningRequest $request, CreatePlanningShiftAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user());

        return to_route('administration.planning.index')->with('status', 'Créneau ajouté au planning.');
    }

    public function update(UpdatePlanningRequest $request, PlanningShift $planning, UpdatePlanningShiftAction $action): RedirectResponse
    {
        $action->execute($planning, $request->validated(), $request->user());

        return to_route('administration.planning.index')->with('status', 'Créneau mis à jour.');
    }

    public function print(Request $request): Response
    {
        abort_unless($request->user()->can('planning.print'), 403);
        [$from, $to] = $this->period($request);

        return Inertia::render('Administration/Planning/Print', [
            'shifts' => $this->query($from, $to)->get()->map(fn ($shift) => $this->presenter->planning($shift)),
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('planning.export'), 403);
        [$from, $to] = $this->period($request);
        $shifts = $this->query($from, $to)->get();

        return $excel->download("planning-{$from}-{$to}", 'Planning', [
            'UUID', 'Matricule', 'Employé', 'Département', 'Objet', 'Début', 'Fin', 'Observation',
        ], $shifts->map(fn ($shift) => [
            $shift->uuid, $shift->employee->employee_number,
            trim($shift->employee->last_name.' '.$shift->employee->first_name),
            $shift->department?->label, $shift->title,
            $shift->starts_at?->format('Y-m-d H:i'), $shift->ends_at?->format('Y-m-d H:i'),
            $shift->observation,
        ]));
    }

    private function query(string $from, string $to)
    {
        return PlanningShift::query()
            ->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with(['employee.department', 'employee.jobTitle', 'department'])
            ->oldest('starts_at');
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        return [
            $request->date('from')?->toDateString() ?? now()->startOfWeek()->toDateString(),
            $request->date('to')?->toDateString() ?? now()->endOfWeek()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'employees' => Employee::query()->where('active', true)->with(['department', 'jobTitle'])
                ->orderBy('last_name')->get()->map(fn ($employee) => $this->presenter->employeeOption($employee)),
            'departments' => HrReferenceValue::query()->ofType(HrReferenceType::Department)
                ->where('active', true)->orderBy('position')->get()
                ->map(fn ($department) => ['uuid' => $department->uuid, 'label' => $department->label]),
        ];
    }
}
