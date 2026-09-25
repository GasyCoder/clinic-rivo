<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\CreatePlanningShiftAction;
use App\Actions\Administration\UpdatePlanningShiftAction;
use App\Enums\HrReferenceType;
use App\Enums\PlanningShiftKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StorePlanningRequest;
use App\Http\Requests\Administration\UpdatePlanningRequest;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\PlanningShift;
use App\Services\Administration\HrPresenter;
use App\Services\Spreadsheet\ExcelWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-194 — deux plannings, un seul écran : le service du personnel et les
 * gardes, en calendrier (semaine, mois) ou en liste.
 *
 * Un créneau appartient à la période dès qu'il la chevauche : une garde de
 * nuit commencée dimanche à 19 h figure aussi le lundi matin. Le planning
 * reste factuel (ADR-066) : aucun chevauchement n'est bloqué, aucune
 * absence ni heure supplémentaire n'est calculée.
 */
class PlanningController extends Controller
{
    private const VIEWS = ['week', 'month', 'list'];

    private const KINDS = ['SHIFT', 'ON_CALL', 'ALL'];

    /** Au-delà, la période est trop chargée pour un calendrier : l'écran le dit. */
    private const LIMIT = 2000;

    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', PlanningShift::class);

        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'week';
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : 'SHIFT';
        $anchor = $this->anchor($request);
        [$from, $to] = $this->range($request, $view, $anchor);
        $department = $this->department($request);

        $shifts = $this->query($from, $to, $kind, $department)->limit(self::LIMIT + 1)->get();
        $truncated = $shifts->count() > self::LIMIT;
        $shifts = $shifts->take(self::LIMIT);

        // Les compteurs des deux onglets, pour la même période et le même département.
        $counts = collect(PlanningShiftKind::cases())->mapWithKeys(fn (PlanningShiftKind $case) => [
            $case->value => $this->query($from, $to, $case->value, $department)->count(),
        ]);

        return Inertia::render('Administration/Planning/Index', [
            'shifts' => $shifts->map(fn (PlanningShift $shift) => $this->presenter->planning($shift))->values(),
            'filters' => [
                'view' => $view,
                'kind' => $kind,
                'date' => $anchor->toDateString(),
                'department' => $department?->uuid,
            ],
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'today' => now()->toDateString(),
            ],
            'counts' => [...$counts->all(), 'ALL' => $counts->sum()],
            'summary' => [
                'shifts' => $shifts->count(),
                'employees' => $shifts->pluck('employee_id')->unique()->count(),
            ],
            'truncated' => $truncated,
            'departments' => HrReferenceValue::query()->ofType(HrReferenceType::Department)
                ->where('active', true)->orderBy('position')->orderBy('label')->get()
                ->map(fn (HrReferenceValue $value) => ['uuid' => $value->uuid, 'label' => $value->label]),
            'kinds' => collect(PlanningShiftKind::cases())->map(fn (PlanningShiftKind $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'planning' => $case->planningLabel(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', PlanningShift::class);
        $date = $request->date('date');

        return Inertia::render('Administration/Planning/Create', [
            ...$this->formData(),
            'selectedEmployeeUuid' => $request->query('employee'),
            // ADR-194 — ouvert depuis une case du calendrier : le jour et le type
            // sont repris ; les heures restent à choisir (aucune n'est inventée).
            'preset' => [
                'date' => $date?->toDateString(),
                'kind' => PlanningShiftKind::tryFrom((string) $request->query('kind'))?->value ?? PlanningShiftKind::Shift->value,
            ],
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
        $shift = $action->execute($request->validated(), $request->user());

        return to_route('administration.planning.index', $this->returnQuery($shift))
            ->with('status', $shift->kind === PlanningShiftKind::OnCall ? 'Garde ajoutée au planning.' : 'Créneau ajouté au planning.');
    }

    public function update(UpdatePlanningRequest $request, PlanningShift $planning, UpdatePlanningShiftAction $action): RedirectResponse
    {
        $shift = $action->execute($planning, $request->validated(), $request->user());

        return to_route('administration.planning.index', $this->returnQuery($shift))
            ->with('status', $shift->kind === PlanningShiftKind::OnCall ? 'Garde mise à jour.' : 'Créneau mis à jour.');
    }

    public function print(Request $request): Response
    {
        abort_unless($request->user()->can('planning.print'), 403);
        [$from, $to] = $this->range($request, 'list', $this->anchor($request));
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : 'ALL';

        return Inertia::render('Administration/Planning/Print', [
            'shifts' => $this->query($from, $to, $kind, $this->department($request))->limit(self::LIMIT)->get()
                ->map(fn ($shift) => $this->presenter->planning($shift)),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kind' => $kind === 'ALL' ? null : PlanningShiftKind::from($kind)->planningLabel(),
        ]);
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('planning.export'), 403);
        [$from, $to] = $this->range($request, 'list', $this->anchor($request));
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : 'ALL';
        $shifts = $this->query($from, $to, $kind, $this->department($request))->get();

        return $excel->download("planning-{$from->toDateString()}-{$to->toDateString()}", 'Planning', [
            'UUID', 'Type', 'Matricule', 'Employé', 'Département', 'Objet', 'Début', 'Fin', 'Observation',
        ], $shifts->map(fn ($shift) => [
            $shift->uuid, ($shift->kind ?? PlanningShiftKind::Shift)->label(), $shift->employee->employee_number,
            trim($shift->employee->last_name.' '.$shift->employee->first_name),
            $shift->department?->label, $shift->title,
            $shift->starts_at?->format('Y-m-d H:i'), $shift->ends_at?->format('Y-m-d H:i'),
            $shift->observation,
        ]));
    }

    /** @return Builder<PlanningShift> */
    private function query(CarbonImmutable $from, CarbonImmutable $to, string $kind, ?HrReferenceValue $department): Builder
    {
        return PlanningShift::query()
            // Chevauche la période, jour de fin compris.
            ->where('starts_at', '<', $to->addDay()->startOfDay())
            ->where('ends_at', '>', $from->startOfDay())
            ->when($kind !== 'ALL', fn (Builder $query) => $query->where('kind', $kind))
            ->when($department, fn (Builder $query) => $query->where('department_id', $department->getKey()))
            ->with(['employee.department', 'employee.jobTitle', 'department'])
            ->oldest('starts_at');
    }

    private function anchor(Request $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->date('date')?->toDateString() ?? now()->toDateString());
    }

    /**
     * La période affichée. Semaine : du lundi au dimanche. Mois : la grille
     * complète, du lundi de la première semaine au dimanche de la dernière.
     * Liste : `from`/`to` s'ils sont donnés (92 jours au plus), sinon la semaine.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request, string $view, CarbonImmutable $anchor): array
    {
        if ($view === 'month') {
            return [
                $anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY),
                $anchor->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
            ];
        }

        if ($view === 'list' && $request->date('from') && $request->date('to')) {
            $from = CarbonImmutable::parse($request->date('from')->toDateString());
            $to = CarbonImmutable::parse($request->date('to')->toDateString());

            if ($to->lessThan($from)) {
                [$from, $to] = [$to, $from];
            }

            return [$from, $to->greaterThan($from->addDays(92)) ? $from->addDays(92) : $to];
        }

        return [
            $anchor->startOfWeek(CarbonImmutable::MONDAY),
            $anchor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
        ];
    }

    private function department(Request $request): ?HrReferenceValue
    {
        $uuid = (string) $request->query('department', '');

        return $uuid !== ''
            ? HrReferenceValue::withTrashed()->ofType(HrReferenceType::Department)->where('uuid', $uuid)->first()
            : null;
    }

    /** @return array<string, string> */
    private function returnQuery(PlanningShift $shift): array
    {
        return [
            'kind' => ($shift->kind ?? PlanningShiftKind::Shift)->value,
            'date' => $shift->starts_at->toDateString(),
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
            'kinds' => collect(PlanningShiftKind::cases())->map(fn (PlanningShiftKind $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'planning' => $case->planningLabel(),
            ]),
        ];
    }
}
