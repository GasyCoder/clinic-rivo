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
use App\Enums\PatientSex;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveEmployeeRequest;
use App\Http\Requests\Administration\ImportEmployeesRequest;
use App\Http\Requests\Administration\StoreEmployeeRequest;
use App\Http\Requests\Administration\UpdateEmployeeRequest;
use App\Models\AddressEntry;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrDocument;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use App\Models\ProfessionalMailbox;
use App\Services\Administration\EmployeeNumberAllocator;
use App\Services\Administration\HrPresenter;
use App\Services\Administration\InternshipDirectory;
use App\Services\Administration\LeaveBalanceCalculator;
use App\Services\Administration\ProfessionalMailboxPresenter;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Support\ProfessionalEmailAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly HrPresenter $presenter,
        private readonly InternshipDirectory $internships,
    ) {}

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
            // ADR-194 — le repère « Stagiaire » de la ligne, en une requête.
            ->withExists(['contracts as has_current_internship' => fn ($query) => $this->internships->currentInternships($query)])
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

        $numbers = app(EmployeeNumberAllocator::class);

        return Inertia::render('Administration/Employees/Create', [
            ...$this->formData($request),
            // ADR-191 — proposé, jamais réservé : le RH peut le corriger.
            'suggestedEmployeeNumber' => $numbers->suggest(),
            'employeeNumberModel' => $numbers->format()->format(0),
            // ADR-194 — « Nouveau stagiaire » : le dossier d'abord, puis son stage.
            'internshipIntent' => $request->boolean('stagiaire')
                && $request->user()->can('contracts.create')
                && $this->internships->hasInternshipType(),
        ]);
    }

    /** ADR-194 — la photo 4 × 4, lue sur le disque privé après contrôle du droit. */
    public function photo(Employee $employee): BinaryFileResponse
    {
        abort_unless($employee->hasPhoto() && Storage::disk('local')->exists($employee->photo_path), 404);

        return response()->file(Storage::disk('local')->path($employee->photo_path), [
            'Content-Type' => 'image/jpeg',
            // L'adresse change avec la photo (`v`) : elle peut rester en cache,
            // mais seulement dans le navigateur de la personne autorisée.
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        Gate::forUser($request->user())->authorize('view', $employee);
        $employee->load([
            'addressEntry' => fn ($query) => $query->withTrashed(),
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
            'user:id,uuid,name,active,deactivated_at',
        ]);

        $contracts = $request->user()->can('contracts.view')
            ? EmploymentContract::withTrashed()
                ->where('employee_id', $employee->getKey())
                ->with(['employee.department', 'employee.jobTitle', 'contractType' => fn ($query) => $query->withTrashed(), 'internshipField', 'internshipSupervisor'])
                ->latest('starts_on')->get()->map(fn ($contract) => $this->presenter->contract($contract))
            : collect();
        $documents = $request->user()->can('hr_documents.view')
            ? HrDocument::withTrashed()->where('employee_id', $employee->getKey())
                ->with(['attestationType' => fn ($query) => $query->withTrashed(), 'employmentContract', 'leaveRequest'])
                ->latest()->get()->map(fn ($document) => $this->presenter->document($document))
            : collect();

        // Le dossier d'une personne réunit ce que les écrans RH montrent
        // séparément. Chaque bloc garde le droit qui possède sa donnée ;
        // sans lui, il n'est pas servi — jamais servi vide (ADR-102).
        $user = $request->user();
        $leave = $user->can('leave.view') ? [
            'balances' => app(LeaveBalanceCalculator::class)->annualBalances($employee, now()->year),
            'recent' => LeaveRequest::query()->where('employee_id', $employee->getKey())
                ->with(['employee.department', 'employee.jobTitle', 'leaveType' => fn ($query) => $query->withTrashed(), 'decidedBy:id,name'])
                ->latest('starts_on')->limit(8)->get()->map(fn ($leave) => $this->presenter->leave($leave)),
        ] : null;
        $attendance = $user->can('attendance.view')
            ? AttendanceRecord::query()->where('employee_id', $employee->getKey())
                ->with(['employee.department', 'employee.jobTitle'])
                ->latest('started_at')->limit(8)->get()->map(fn ($record) => $this->presenter->attendance($record))
            : null;
        $planning = $user->can('planning.view')
            ? PlanningShift::query()->where('employee_id', $employee->getKey())
                ->where('ends_at', '>=', now())
                ->with(['employee.department', 'employee.jobTitle', 'department' => fn ($query) => $query->withTrashed()])
                ->orderBy('starts_at')->limit(8)->get()->map(fn ($shift) => $this->presenter->planning($shift))
            : null;

        return Inertia::render('Administration/Employees/Show', [
            'employee' => $this->presenter->employee($employee),
            'contracts' => $contracts,
            'documents' => $documents,
            'leave' => $leave,
            'attendance' => $attendance,
            'planning' => $planning,
            'documentOptions' => $this->documentOptions(),
            'attestationTypes' => $this->references(HrReferenceType::AttestationType),
            'professionalEmail' => $this->professionalEmail($request, $employee),
        ]);
    }

    /**
     * ADR-190 — l'adresse email professionnelle de l'employé : la dernière
     * connue (ouverte, ou la plus récente refusée ou annulée) et, s'il peut en
     * demander une, la proposition prenom.nom@domaine. Rien sans le droit de la voir.
     *
     * @return array<string, mixed>|null
     */
    private function professionalEmail(Request $request, Employee $employee): ?array
    {
        $user = $request->user();
        if (! $user->can('professional_emails.view') && ! $user->can('professional_emails.request')) {
            return null;
        }

        $mailbox = ProfessionalMailbox::query()->where('employee_id', $employee->getKey())
            ->orderByRaw('CASE WHEN active_key IS NULL THEN 1 ELSE 0 END')
            ->latest('requested_at')->latest('id')
            ->first();

        return [
            'domain' => ProfessionalEmailAddress::domain(),
            'configured' => ProfessionalEmailAddress::configured(),
            'current' => $mailbox ? app(ProfessionalMailboxPresenter::class)->present($mailbox) : null,
            'suggestion' => ProfessionalEmailAddress::configured() ? ProfessionalEmailAddress::suggest($employee) : '',
            'can_request' => $user->can('professional_emails.request') && $employee->active && ! $employee->trashed(),
        ];
    }

    public function edit(Request $request, Employee $employee): Response
    {
        Gate::forUser($request->user())->authorize('update', $employee);
        $employee->load([
            'addressEntry' => fn ($query) => $query->withTrashed(),
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
            'user:id,uuid,name,active,deactivated_at',
        ]);

        return Inertia::render('Administration/Employees/Edit', [
            ...$this->formData($request, $employee),
            'employee' => $this->presenter->employee($employee),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): RedirectResponse
    {
        $employee = $action->execute($request->safe()->except('after'), $request->user());

        // ADR-194 — un stagiaire : le dossier est créé, son stage vient ensuite.
        if ($request->validated('after') === 'internship' && $request->user()->can('contracts.create')) {
            return to_route('administration.contracts.create', ['employee' => $employee->uuid, 'type' => 'stage'])
                ->with('status', "Dossier {$employee->employee_number} créé. Enregistrez maintenant son stage : filière, école, encadrant et dates.");
        }

        return to_route('administration.employees.show', $employee)
            ->with('status', "Dossier Employé {$employee->employee_number} créé.");
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): RedirectResponse
    {
        $employee = $action->execute($employee, $request->safe()->except('after'), $request->user());

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

    public function importPage(Request $request): Response
    {
        abort_unless($request->user()->can('employees.import'), 403);

        return Inertia::render('Administration/Employees/Import', [
            'columns' => $this->importColumns(),
            'referenceValues' => [
                'departments' => $this->references(HrReferenceType::Department),
                'jobTitles' => $this->references(HrReferenceType::JobTitle),
                'contractTypes' => $this->references(HrReferenceType::ContractType),
            ],
            'limits' => ['rows' => 1000, 'megabytes' => 5],
        ]);
    }

    public function importTemplate(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        abort_unless($request->user()->can('employees.import'), 403);

        // Le modèle à remplir n'a pas de colonne Email (ADR-190) ; l'export, lui, montre l'adresse pro.
        return $excel->download('modele-import-employes', 'Employés', array_values(array_diff($this->exportHeaders(), ['Email'])), []);
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
            'jobTitles' => $this->jobTitleOptions($employee?->job_title_id),
            // ADR-194 — le couple déjà enregistré reste choisissable, même s'il
            // ne suit plus la correspondance (JobTitleDepartmentGuard).
            'currentPair' => $employee ? [
                'department_uuid' => $employee->department?->uuid,
                'job_title_uuid' => $employee->jobTitle?->uuid,
            ] : null,
            'options' => [
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

    /**
     * ADR-194 — chaque fonction dit les départements où elle existe ; le
     * formulaire ne propose que celles du département choisi. Une liste vide
     * veut dire « tous les départements ».
     *
     * @return array<int, array<string, mixed>>
     */
    private function jobTitleOptions(?int $historicalId = null): array
    {
        return HrReferenceValue::withTrashed()->ofType(HrReferenceType::JobTitle)
            ->where(function ($query) use ($historicalId): void {
                $query->where(fn ($active) => $active->where('active', true)->whereNull('deleted_at'));
                if ($historicalId) {
                    $query->orWhere('id', $historicalId);
                }
            })
            ->with(['departments' => fn ($query) => $query->withTrashed()])
            ->orderBy('position')->orderBy('label')->get()
            ->map(fn (HrReferenceValue $reference) => [
                'uuid' => $reference->uuid,
                'label' => $reference->label,
                'available' => $reference->active && ! $reference->trashed(),
                'department_uuids' => $reference->departments->pluck('uuid')->values()->all(),
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

    /** @return array<int, array{name: string, required: bool, format: string}> */
    private function importColumns(): array
    {
        return [
            ['name' => 'Matricule', 'required' => false, 'format' => 'Unique, y compris parmi les archives ; vide, le prochain matricule du modèle du site'],
            ['name' => 'Nom', 'required' => true, 'format' => 'Texte'],
            ['name' => 'Prénoms', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Genre', 'required' => true, 'format' => 'M, F, Homme, Femme, Masculin ou Féminin'],
            ['name' => 'Fonction', 'required' => false, 'format' => 'Libellé exact des paramètres RH'],
            ['name' => 'Département', 'required' => false, 'format' => 'Libellé exact des paramètres RH'],
            ['name' => 'Date entrée', 'required' => false, 'format' => 'JJ/MM/AAAA, JJ-MM-AAAA ou AAAA-MM-JJ'],
            ['name' => 'Type contrat', 'required' => false, 'format' => 'Libellé exact ; exige une date d’entrée'],
            ['name' => 'Statut', 'required' => false, 'format' => 'Actif ou Inactif ; Actif par défaut'],
            ['name' => 'Date naissance', 'required' => false, 'format' => 'JJ/MM/AAAA, JJ-MM-AAAA ou AAAA-MM-JJ'],
            ['name' => 'Lieu naissance', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Numéro CIN', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Date CIN', 'required' => false, 'format' => 'JJ/MM/AAAA, JJ-MM-AAAA ou AAAA-MM-JJ'],
            ['name' => 'Lieu CIN', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Adresse', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Diplôme', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Niveau', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Nombre enfants', 'required' => false, 'format' => 'Nombre entier positif ou nul'],
            ['name' => 'Détails enfants', 'required' => false, 'format' => 'Note administrative libre'],
            ['name' => 'Badge', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Blouse', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Téléphone', 'required' => false, 'format' => 'Texte'],
            ['name' => 'Observation', 'required' => false, 'format' => 'Texte'],
        ];
    }
}
