<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveEmploymentContractAction;
use App\Actions\Administration\CreateEmploymentContractAction;
use App\Actions\Administration\RestoreEmploymentContractAction;
use App\Actions\Administration\UpdateEmploymentContractAction;
use App\Enums\HrReferenceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveContractRequest;
use App\Http\Requests\Administration\StoreContractRequest;
use App\Http\Requests\Administration\UpdateContractRequest;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Services\Administration\HrPresenter;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmploymentContractController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', EmploymentContract::class);
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['current', 'future', 'ended', 'archived', 'all'], true)
            ? $request->query('status') : 'current';
        $today = now()->toDateString();

        $contracts = EmploymentContract::query()
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($status === 'current', fn ($query) => $query
                ->whereDate('starts_on', '<=', $today)
                ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today)))
            ->when($status === 'future', fn ($query) => $query->whereDate('starts_on', '>', $today))
            ->when($status === 'ended', fn ($query) => $query->whereDate('ends_on', '<', $today))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('contractType', fn ($type) => $type->where('label', 'like', "%{$search}%"));
            }))
            ->with(['employee.department', 'employee.jobTitle', 'contractType' => fn ($query) => $query->withTrashed()])
            ->latest('starts_on')->paginate(20)->withQueryString()
            ->through(fn ($contract) => $this->presenter->contract($contract));

        return Inertia::render('Administration/Contracts/Index', [
            'contracts' => $contracts,
            'filters' => ['q' => $search, 'status' => $status],
            'summary' => [
                'current' => EmploymentContract::query()->whereDate('starts_on', '<=', $today)
                    ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->count(),
                'future' => EmploymentContract::query()->whereDate('starts_on', '>', $today)->count(),
                'ended' => EmploymentContract::query()->whereDate('ends_on', '<', $today)->count(),
                'archived' => EmploymentContract::onlyTrashed()->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', EmploymentContract::class);

        return Inertia::render('Administration/Contracts/Create', [
            ...$this->formData(),
            'selectedEmployeeUuid' => $request->query('employee'),
        ]);
    }

    public function edit(Request $request, EmploymentContract $contract): Response
    {
        Gate::forUser($request->user())->authorize('update', $contract);
        $contract->load(['employee.department', 'employee.jobTitle', 'contractType']);

        return Inertia::render('Administration/Contracts/Edit', [
            ...$this->formData(),
            'contract' => $this->presenter->contract($contract),
        ]);
    }

    public function store(StoreContractRequest $request, CreateEmploymentContractAction $action): RedirectResponse
    {
        $contract = $action->execute($request->validated(), $request->user());

        return to_route('administration.contracts.index')
            ->with('status', "Contrat {$contract->contractType->label} enregistré.");
    }

    public function update(UpdateContractRequest $request, EmploymentContract $contract, UpdateEmploymentContractAction $action): RedirectResponse
    {
        $action->execute($contract, $request->validated(), $request->user());

        return to_route('administration.contracts.index')->with('status', 'Contrat mis à jour.');
    }

    public function destroy(ArchiveContractRequest $request, EmploymentContract $contract, ArchiveEmploymentContractAction $action): RedirectResponse
    {
        $action->execute($contract, $request->validated('reason'), $request->user());

        return to_route('administration.contracts.index', ['status' => 'archived'])
            ->with('status', 'Contrat archivé.');
    }

    public function restore(Request $request, EmploymentContract $contract, RestoreEmploymentContractAction $action): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('restore', $contract);
        $action->execute($contract, $request->user());

        return to_route('administration.contracts.index')->with('status', 'Contrat restauré.');
    }

    public function print(Request $request, EmploymentContract $contract): Response
    {
        abort_unless($request->user()->can('contracts.print'), 403);
        Gate::forUser($request->user())->authorize('view', $contract);
        $contract->load(['employee.department', 'employee.jobTitle', 'employee.addressEntry', 'contractType']);

        return Inertia::render('Administration/Contracts/Print', [
            'contract' => $this->presenter->contract($contract),
        ]);
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('contracts.export'), 403);
        $contracts = EmploymentContract::withTrashed()
            ->with(['employee', 'contractType' => fn ($query) => $query->withTrashed()])
            ->latest('starts_on')->get();

        return $excel->download('contrats-'.now()->format('Y-m-d'), 'Contrats', [
            'UUID', 'Matricule', 'Employé', 'Type', 'Référence', 'Signature', 'Début', 'Fin essai', 'Fin', 'État', 'Observation',
        ], $contracts->map(fn ($contract) => [
            $contract->uuid, $contract->employee->employee_number,
            trim($contract->employee->last_name.' '.$contract->employee->first_name),
            $contract->contractType?->label, $contract->reference_number,
            $contract->signed_on?->toDateString(), $contract->starts_on?->toDateString(),
            $contract->trial_ends_on?->toDateString(), $contract->ends_on?->toDateString(),
            $contract->trashed() ? 'ARCHIVÉ' : 'ACTIF', $contract->observation,
        ]));
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'employees' => Employee::query()->where('active', true)
                ->with(['department', 'jobTitle'])->orderBy('last_name')->get()
                ->map(fn ($employee) => $this->presenter->employeeOption($employee)),
            'contractTypes' => HrReferenceValue::query()->ofType(HrReferenceType::ContractType)
                ->where('active', true)->orderBy('position')->orderBy('label')->get()
                ->map(fn ($type) => ['uuid' => $type->uuid, 'label' => $type->label]),
        ];
    }
}
