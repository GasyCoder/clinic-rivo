<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveEmployeeAction;
use App\Actions\Administration\CreateEmployeeAction;
use App\Actions\Administration\ImportEmployeesAction;
use App\Actions\Administration\RestoreEmployeeAction;
use App\Actions\Administration\UpdateEmployeeAction;
use App\Enums\HrDocumentCategory;
use App\Enums\HrReferenceType;
use App\Enums\IdentityDocumentType;
use App\Enums\MaritalStatus;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveEmployeeRequest;
use App\Http\Requests\Administration\ImportEmployeesRequest;
use App\Http\Requests\Administration\StoreEmployeeRequest;
use App\Http\Requests\Administration\UpdateEmployeeRequest;
use App\Models\AddressEntry;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrDocument;
use App\Models\HrReferenceValue;
use App\Services\Administration\HrPresenter;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Employee::class);
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'inactive', 'archived', 'all'], true)
            ? $request->query('status') : 'active';

        $employees = Employee::query()
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('profession', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('identity_document_number', 'like', "%{$search}%")
                        ->orWhereHas('department', fn ($reference) => $reference->where('label', 'like', "%{$search}%"))
                        ->orWhereHas('jobTitle', fn ($reference) => $reference->where('label', 'like', "%{$search}%"));
                });
            })
            ->with([
                'addressEntry' => fn ($query) => $query->withTrashed(),
                'department' => fn ($query) => $query->withTrashed(),
                'jobTitle' => fn ($query) => $query->withTrashed(),
            ])
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(20)->withQueryString()
            ->through(fn (Employee $employee) => $this->presenter->employee($employee));

        return Inertia::render('Administration/Employees/Index', [
            'employees' => $employees,
            'filters' => ['q' => $search, 'status' => $status],
            'summary' => [
                'active' => Employee::query()->where('active', true)->count(),
                'inactive' => Employee::query()->where('active', false)->count(),
                'archived' => Employee::onlyTrashed()->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', Employee::class);

        return Inertia::render('Administration/Employees/Create', $this->formData($request));
    }

    public function show(Request $request, Employee $employee): Response
    {
        Gate::forUser($request->user())->authorize('view', $employee);
        $employee->load([
            'addressEntry' => fn ($query) => $query->withTrashed(),
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
        ]);

        $contracts = $request->user()->can('contracts.view')
            ? EmploymentContract::withTrashed()
                ->where('employee_id', $employee->getKey())
                ->with(['employee.department', 'employee.jobTitle', 'contractType' => fn ($query) => $query->withTrashed()])
                ->latest('starts_on')->get()->map(fn ($contract) => $this->presenter->contract($contract))
            : collect();
        $documents = $request->user()->can('hr_documents.view')
            ? HrDocument::withTrashed()->where('employee_id', $employee->getKey())
                ->with(['attestationType' => fn ($query) => $query->withTrashed(), 'employmentContract', 'leaveRequest'])
                ->latest()->get()->map(fn ($document) => $this->presenter->document($document))
            : collect();

        return Inertia::render('Administration/Employees/Show', [
            'employee' => $this->presenter->employee($employee),
            'contracts' => $contracts,
            'documents' => $documents,
            'documentOptions' => $this->documentOptions(),
            'attestationTypes' => $this->references(HrReferenceType::AttestationType),
        ]);
    }

    public function edit(Request $request, Employee $employee): Response
    {
        Gate::forUser($request->user())->authorize('update', $employee);
        $employee->load([
            'addressEntry' => fn ($query) => $query->withTrashed(),
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
        ]);

        return Inertia::render('Administration/Employees/Edit', [
            ...$this->formData($request, $employee),
            'employee' => $this->presenter->employee($employee),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): RedirectResponse
    {
        $employee = $action->execute($request->validated(), $request->user());

        return to_route('administration.employees.show', $employee)
            ->with('status', "Dossier Employé {$employee->employee_number} créé.");
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): RedirectResponse
    {
        $employee = $action->execute($employee, $request->validated(), $request->user());

        return to_route('administration.employees.show', $employee)
            ->with('status', "Dossier Employé {$employee->employee_number} mis à jour.");
    }

    public function destroy(ArchiveEmployeeRequest $request, Employee $employee, ArchiveEmployeeAction $action): RedirectResponse
    {
        $number = $employee->employee_number;
        $action->execute($employee, $request->validated('reason'), $request->user());

        return to_route('administration.employees.index', ['status' => 'archived'])
            ->with('status', "Dossier Employé {$number} archivé.");
    }

    public function restore(Request $request, Employee $employee, RestoreEmployeeAction $action): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('restore', $employee);
        $employee = $action->execute($employee, $request->user());

        return to_route('administration.employees.show', $employee)
            ->with('status', "Dossier Employé {$employee->employee_number} restauré.");
    }

    public function print(Request $request, Employee $employee): Response
    {
        abort_unless($request->user()->can('employees.print'), 403);
        Gate::forUser($request->user())->authorize('view', $employee);
        $employee->load([
            'addressEntry' => fn ($query) => $query->withTrashed(),
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
        ]);

        return Inertia::render('Administration/Employees/Print', [
            'employee' => $this->presenter->employee($employee),
        ]);
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('employees.export'), 403);
        $employees = Employee::withTrashed()->with([
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
            'contracts' => fn ($query) => $query->withTrashed()->with('contractType')->latest('starts_on'),
        ])->orderBy('last_name')->get();

        return $excel->download(
            'employes-'.now()->format('Y-m-d'), 'Employés', $this->exportHeaders(),
            $employees->map(fn (Employee $employee) => [
                $employee->employee_number, $employee->last_name, $employee->first_name,
                $employee->jobTitle?->label ?? $employee->profession, $employee->department?->label,
                $employee->diploma, $employee->education_level, $employee->sex->value,
                $employee->hire_date?->toDateString(), $employee->birth_date?->toDateString(),
                $employee->birth_place, $employee->identity_document_number,
                $employee->identity_document_issued_on?->toDateString(), $employee->identity_document_issued_at,
                $employee->address, $employee->children_count, $employee->children_details,
                $employee->badge, $employee->blouse, $employee->email, $employee->phone,
                $employee->trashed() ? 'ARCHIVÉ' : ($employee->active ? 'ACTIF' : 'INACTIF'),
                $employee->contracts->first()?->contractType?->label, $employee->observation,
            ]),
        );
    }

    public function importTemplate(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('employees.import'), 403);

        return $excel->download('modele-import-employes', 'Employés', $this->exportHeaders(), []);
    }

    public function import(ImportEmployeesRequest $request, ImportEmployeesAction $action): RedirectResponse
    {
        $result = $action->execute($request->file('file'), $request->user());

        return to_route('administration.employees.index')->with(
            'status',
            "{$result['created']} employé(s) importé(s), {$result['contracts']} contrat(s) initial(aux) créé(s).",
        );
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?Employee $employee = null): array
    {
        $addresses = $request->user()->can('address_entries.view')
            ? AddressEntry::withTrashed()->where(function ($query) use ($employee): void {
                $query->where(fn ($active) => $active->where('active', true)->whereNull('deleted_at'));
                if ($employee?->address_entry_id) {
                    $query->orWhere('id', $employee->address_entry_id);
                }
            })->orderBy('label')->get()->map(fn (AddressEntry $address) => [
                'uuid' => $address->uuid, 'label' => $address->label,
                'available' => $address->active && ! $address->trashed(),
            ]) : collect();

        return [
            'addresses' => $addresses,
            'departments' => $this->references(HrReferenceType::Department, $employee?->department_id),
            'jobTitles' => $this->references(HrReferenceType::JobTitle, $employee?->job_title_id),
            'options' => [
                'civilities' => [
                    ['value' => PatientCivility::Mr->value, 'label' => 'Monsieur'],
                    ['value' => PatientCivility::Mrs->value, 'label' => 'Madame'],
                    ['value' => PatientCivility::Girl->value, 'label' => 'Fille'],
                    ['value' => PatientCivility::Boy->value, 'label' => 'Garçon'],
                ],
                'sexes' => [
                    ['value' => PatientSex::Male->value, 'label' => 'Masculin'],
                    ['value' => PatientSex::Female->value, 'label' => 'Féminin'],
                ],
                'identity_document_types' => [
                    ['value' => IdentityDocumentType::Cin->value, 'label' => 'CIN'],
                    ['value' => IdentityDocumentType::Passport->value, 'label' => 'Passeport'],
                ],
                'marital_statuses' => collect(MaritalStatus::cases())->map(fn ($status) => [
                    'value' => $status->value, 'label' => $status->label(),
                ])->all(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function references(HrReferenceType $type, ?int $historicalId = null): array
    {
        return HrReferenceValue::withTrashed()->ofType($type)
            ->where(function ($query) use ($historicalId): void {
                $query->where(fn ($active) => $active->where('active', true)->whereNull('deleted_at'));
                if ($historicalId) {
                    $query->orWhere('id', $historicalId);
                }
            })->orderBy('position')->orderBy('label')->get()
            ->map(fn ($reference) => [
                'uuid' => $reference->uuid, 'label' => $reference->label,
                'available' => $reference->active && ! $reference->trashed(),
            ])->all();
    }

    /** @return array<int, array{value: string, label: string}> */
    private function documentOptions(): array
    {
        return collect(HrDocumentCategory::cases())->map(fn ($category) => [
            'value' => $category->value, 'label' => $category->label(),
        ])->all();
    }

    /** @return array<int, string> */
    private function exportHeaders(): array
    {
        return ['Matricule', 'Nom', 'Prénoms', 'Fonction', 'Département', 'Diplôme', 'Niveau', 'Genre', 'Date entrée', 'Date naissance', 'Lieu naissance', 'Numéro CIN', 'Date CIN', 'Lieu CIN', 'Adresse', 'Nombre enfants', 'Détails enfants', 'Badge', 'Blouse', 'Email', 'Téléphone', 'Statut', 'Type contrat', 'Observation'];
    }
}
