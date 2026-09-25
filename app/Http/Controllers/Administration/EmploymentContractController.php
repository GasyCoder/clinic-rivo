<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveEmploymentContractAction;
use App\Actions\Administration\CreateEmploymentContractAction;
use App\Actions\Administration\RestoreEmploymentContractAction;
use App\Actions\Administration\UpdateEmploymentContractAction;
use App\Enums\DocumentDataContext;
use App\Enums\HrReferenceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveContractRequest;
use App\Http\Requests\Administration\StoreContractRequest;
use App\Http\Requests\Administration\UpdateContractRequest;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\HrReferenceValue;
use App\Services\Administration\HrPresenter;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Database\Eloquent\Builder;
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
        $status = in_array($request->query('status'), ['current', 'ending', 'future', 'ended', 'archived', 'all'], true)
            ? $request->query('status') : 'current';
        $today = now()->toDateString();

        $contracts = EmploymentContract::query()
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($status === 'current', fn ($query) => $query
                ->whereDate('starts_on', '<=', $today)
                ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today)))
            // La définition du chiffre « contrats qui finissent sous 30 jours »
            // de l'accueil RH (HrOverviewService) : la carte ouvre cette liste.
            ->when($status === 'ending', fn ($query) => $this->endingSoon($query))
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
            ->with(['employee.department', 'employee.jobTitle', 'contractType' => fn ($query) => $query->withTrashed(), 'internshipField', 'internshipSupervisor'])
            ->latest('starts_on')->paginate(20)->withQueryString()
            ->through(fn ($contract) => $this->presenter->contract($contract));

        return Inertia::render('Administration/Contracts/Index', [
            'contracts' => $contracts,
            'filters' => ['q' => $search, 'status' => $status],
            'summary' => [
                'current' => EmploymentContract::query()->whereDate('starts_on', '<=', $today)
                    ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->count(),
                'ending' => $this->endingSoon(EmploymentContract::query())->count(),
                'future' => EmploymentContract::query()->whereDate('starts_on', '>', $today)->count(),
                'ended' => EmploymentContract::query()->whereDate('ends_on', '<', $today)->count(),
                'archived' => EmploymentContract::onlyTrashed()->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', EmploymentContract::class);

        $formData = $this->formData();

        return Inertia::render('Administration/Contracts/Create', [
            ...$formData,
            'selectedEmployeeUuid' => $request->query('employee'),
            // ADR-194 — « Enregistrer un stage » ouvre le formulaire sur le
            // premier type marqué contrat de stage.
            'selectedContractTypeUuid' => $request->query('type') === 'stage'
                ? collect($formData['contractTypes'])->firstWhere('internship', true)['uuid'] ?? null
                : null,
        ]);
    }

    public function edit(Request $request, EmploymentContract $contract): Response
    {
        Gate::forUser($request->user())->authorize('update', $contract);
        $contract->load(['employee.department', 'employee.jobTitle', 'contractType', 'internshipField', 'internshipSupervisor']);

        return Inertia::render('Administration/Contracts/Edit', [
            ...$this->formData($contract),
            'contract' => $this->presenter->contract($contract),
        ]);
    }

    public function store(StoreContractRequest $request, CreateEmploymentContractAction $action): RedirectResponse
    {
        $contract = $action->execute($request->validated(), $request->user());

        // ADR-184 — un stage ramène à la liste des stages, où il apparaît.
        if ($contract->isInternship()) {
            return to_route('administration.internships.index', ['status' => 'all'])
                ->with('status', "Stage de {$contract->employee->last_name} enregistré ({$contract->internshipField?->label}).");
        }

        return to_route('administration.contracts.index')
            ->with('status', "Contrat {$contract->contractType->label} enregistré.");
    }

    public function update(UpdateContractRequest $request, EmploymentContract $contract, UpdateEmploymentContractAction $action): RedirectResponse
    {
        $contract = $action->execute($contract, $request->validated(), $request->user());

        if ($contract->isInternship()) {
            return to_route('administration.internships.index', ['status' => 'all'])->with('status', 'Stage mis à jour.');
        }

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
        $contract->load(['employee.department', 'employee.jobTitle', 'employee.addressEntry', 'contractType', 'internshipField', 'internshipSupervisor']);

        $user = $request->user();

        return Inertia::render('Administration/Contracts/Print', [
            'contract' => $this->presenter->contract($contract),
            // ADR-070/087: the printable contract is the Super Admin canevas,
            // filled for this contract. An archived contract only prints its sheet.
            'templates' => $user->can('generated_documents.create') && ! $contract->trashed()
                ? DocumentTemplate::query()->where('active', true)
                    ->where('data_context', DocumentDataContext::EmployeeAndContract->value)
                    ->orderBy('name')->get()
                    ->map(fn (DocumentTemplate $template) => [
                        'uuid' => $template->uuid,
                        'name' => $template->name,
                        'document_type' => $template->document_type,
                    ])->all()
                : [],
            'documents' => $user->can('generated_documents.view')
                ? GeneratedDocument::query()->where('employment_contract_id', $contract->getKey())
                    ->latest('created_at')->get()
                    ->map(fn (GeneratedDocument $document) => [
                        'uuid' => $document->uuid,
                        'template_name' => $document->template_name_snapshot,
                        'created_at' => $document->created_at?->toIso8601String(),
                    ])->all()
                : [],
        ]);
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('contracts.export'), 403);
        $contracts = EmploymentContract::withTrashed()
            ->with(['employee', 'contractType' => fn ($query) => $query->withTrashed(), 'internshipField', 'internshipSupervisor'])
            ->latest('starts_on')->get();

        return $excel->download('contrats-'.now()->format('Y-m-d'), 'Contrats', [
            'UUID', 'Matricule', 'Employé', 'Type', 'Référence', 'Signature', 'Début', 'Fin essai', 'Fin', 'État', 'Observation',
            'Filière de stage', 'École', 'Niveau', 'Encadrant',
        ], $contracts->map(fn ($contract) => [
            $contract->uuid, $contract->employee->employee_number,
            trim($contract->employee->last_name.' '.$contract->employee->first_name),
            $contract->contractType?->label, $contract->reference_number,
            $contract->signed_on?->toDateString(), $contract->starts_on?->toDateString(),
            $contract->trial_ends_on?->toDateString(), $contract->ends_on?->toDateString(),
            $contract->trashed() ? 'ARCHIVÉ' : 'ACTIF', $contract->observation,
            $contract->internshipField?->label, $contract->internship_school, $contract->internship_level,
            $contract->internshipSupervisor
                ? trim($contract->internshipSupervisor->last_name.' '.$contract->internshipSupervisor->first_name)
                : null,
        ]));
    }

    /** @return array<string, mixed> */
    private function formData(?EmploymentContract $contract = null): array
    {
        return [
            'employees' => Employee::query()->where('active', true)
                ->with(['department', 'jobTitle'])->orderBy('last_name')->get()
                ->map(fn ($employee) => $this->presenter->employeeOption($employee)),
            // ADR-194 — `internship` : le type ouvre la section « Stage » du formulaire.
            'contractTypes' => HrReferenceValue::query()->ofType(HrReferenceType::ContractType)
                ->where('active', true)->orderBy('position')->orderBy('label')->get()
                ->map(fn (HrReferenceValue $type) => [
                    'uuid' => $type->uuid,
                    'label' => $type->label,
                    'internship' => $type->isInternshipContractType(),
                ]),
            // La filière déjà enregistrée reste proposée même archivée.
            'internshipFields' => HrReferenceValue::withTrashed()->ofType(HrReferenceType::InternshipField)
                ->where(function ($query) use ($contract): void {
                    $query->where(fn ($active) => $active->where('active', true)->whereNull('deleted_at'));
                    if ($contract?->internship_field_id) {
                        $query->orWhere('id', $contract->internship_field_id);
                    }
                })
                ->orderBy('position')->orderBy('label')->get()
                ->map(fn (HrReferenceValue $field) => [
                    'uuid' => $field->uuid,
                    'label' => $field->label,
                    'available' => $field->active && ! $field->trashed(),
                ]),
        ];
    }

    /**
     * Les contrats qui finissent dans les 30 prochains jours — la même règle
     * que le chiffre de l'accueil RH, pour que la carte et la liste disent le
     * même nombre.
     *
     * @param  Builder<EmploymentContract>  $query
     * @return Builder<EmploymentContract>
     */
    private function endingSoon($query)
    {
        return $query->whereBetween('ends_on', [now()->toDateString(), now()->addDays(30)->toDateString()]);
    }
}
