<?php

namespace App\Http\Controllers\Administration;

use App\Support\Documents\DocumentFamily;
use App\Models\User;
use App\Http\Requests\Administration\ArchiveGeneratedDocumentRequest;
use App\Actions\Administration\RestoreGeneratedDocumentAction;
use App\Actions\Administration\ArchiveGeneratedDocumentAction;
use App\Actions\Administration\CreateGeneratedDocumentAction;
use App\Actions\Administration\PreviewGeneratedDocumentAction;
use App\Enums\DocumentDataContext;
use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\PreviewGeneratedDocumentRequest;
use App\Http\Requests\Administration\StoreGeneratedDocumentRequest;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\LeaveRequest;
use App\Services\Administration\DocumentFormFieldCatalog;
use App\Services\Administration\HrPresenter;
use App\Services\Settings\AppSettings;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GeneratedDocumentController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    /**
     * ADR-199 — les documents en dossiers, comme les fournisseurs : un dossier par
     * type (Contrats, Congés, Attestations…). La racine montre les dossiers ;
     * un dossier montre ses canevas (pour générer) et ses documents (actifs ou
     * archivés). Une recherche traverse tous les dossiers.
     */
    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', GeneratedDocument::class);
        $search = trim((string) $request->query('q', ''));
        $archived = $request->query('statut') === 'archives';
        $folder = filled($request->query('dossier')) ? DocumentFamily::key((string) $request->query('dossier')) : null;

        $templateTypes = DocumentTemplate::query()->where('active', true)->pluck('document_type');
        $documentTypes = GeneratedDocument::withTrashed()->distinct()->pluck('document_type_snapshot');
        $allTypes = $templateTypes->merge($documentTypes);

        $documents = null;
        if ($folder !== null || $search !== '') {
            $documents = GeneratedDocument::query()
                ->when($archived, fn ($query) => $query->onlyTrashed())
                ->when($folder !== null, fn ($query) => $query->whereIn('document_type_snapshot', DocumentFamily::typesIn($folder, $documentTypes)))
                ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                    $nested->where('template_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('document_type_snapshot', 'like', "%{$search}%")
                        ->orWhereHas('employee', fn ($employee) => $employee
                            ->where('last_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('employee_number', 'like', "%{$search}%"));
                }))
                ->with(['employee.department', 'employee.jobTitle', 'generatedBy', 'employmentContract.contractType', 'leaveRequest.leaveType', 'replaces', 'replacedBy'])
                ->latest('created_at')->paginate(20)->withQueryString()
                ->through(fn (GeneratedDocument $document) => $this->summarize($document));
        }

        return Inertia::render('Administration/Documents/Index', [
            'folders' => collect(DocumentFamily::keys($allTypes))->map(function (string $key) use ($templateTypes, $documentTypes): array {
                $types = DocumentFamily::typesIn($key, $documentTypes);

                return [
                    'key' => $key,
                    'label' => DocumentFamily::label($key),
                    'templates' => $templateTypes->filter(fn ($type) => DocumentFamily::key($type) === $key)->count(),
                    'documents' => $types === [] ? 0 : GeneratedDocument::query()->whereIn('document_type_snapshot', $types)->count(),
                    'archived' => $types === [] ? 0 : GeneratedDocument::onlyTrashed()->whereIn('document_type_snapshot', $types)->count(),
                    'last_generated_at' => $types === [] ? null : GeneratedDocument::query()->whereIn('document_type_snapshot', $types)->max('created_at'),
                ];
            })->values(),
            'folder' => $folder === null ? null : [
                'key' => $folder,
                'label' => DocumentFamily::label($folder),
                'context_label' => DocumentFamily::context($folder)->label(),
            ],
            'templates' => $folder === null ? [] : collect($this->activeTemplates())
                ->filter(fn (array $template) => DocumentFamily::key($template['document_type']) === $folder)->values()->all(),
            'documents' => $documents,
            'filters' => ['q' => $search, 'dossier' => $folder, 'statut' => $archived ? 'archives' : 'actifs'],
        ]);
    }

    public function create(Request $request, DocumentFormFieldCatalog $catalog, AppSettings $settings): Response
    {
        Gate::forUser($request->user())->authorize('create', GeneratedDocument::class);

        $templates = $this->activeTemplates();
        $employees = $this->employees();
        $contracts = $this->contractsByEmployee();

        // Opened from a contract ("Imprimer" → canevas): keep only references
        // that exist in the lists the page can actually select.
        // ADR-199 — « Modifier » un document : une nouvelle version préremplie avec
        // ses données ; l'ancienne sera archivée à la génération.
        $replaces = filled($request->query('from'))
            ? GeneratedDocument::query()->where('uuid', (string) $request->query('from'))->first()
            : null;
        if ($replaces && $request->user()->cannot('delete', $replaces)) {
            $replaces = null;
        }

        $employee = (string) ($replaces?->employee?->uuid ?? $request->query('employee', ''));
        $employee = collect($employees)->contains('uuid', $employee) ? $employee : '';
        $template = (string) ($replaces ? $this->currentTemplateUuid($replaces) : $request->query('template', ''));
        $template = collect($templates)->contains('uuid', $template) ? $template : '';
        $contract = (string) ($replaces?->employmentContract?->uuid ?? $request->query('contract', ''));
        $contract = $employee !== '' && collect($contracts[$employee] ?? [])->contains('uuid', $contract) ? $contract : '';
        // ADR-198 — ouvert depuis un congé : la demande, et son canevas s'il n'y en a qu'un.
        $leaves = $this->leavesByEmployee();
        $leave = (string) ($replaces?->leaveRequest?->uuid ?? $request->query('leave', ''));
        $leave = $employee !== '' && collect($leaves[$employee] ?? [])->contains('uuid', $leave) ? $leave : '';
        $context = $leave !== '' ? DocumentDataContext::EmployeeAndLeave : ($contract !== '' ? DocumentDataContext::EmployeeAndContract : null);
        $candidates = $context ? collect($templates)->where('data_context', $context->value)->values() : collect();
        if ($template === '' && $candidates->count() === 1) {
            $template = $candidates->first()['uuid'];
        }

        return Inertia::render('Administration/Documents/Create', [
            'prefill' => [
                'document_template_uuid' => $template,
                'employee_uuid' => $employee,
                'employment_contract_uuid' => $contract,
                'leave_request_uuid' => $leave,
                'form_data' => $replaces?->form_data_snapshot ?? [],
            ],
            'replaces' => $replaces ? $this->summarize($replaces->loadMissing(['employee.department', 'employee.jobTitle', 'generatedBy'])) : null,
            // Ouvert depuis un dossier : ses canevas d'abord.
            'folder' => filled($request->query('dossier')) ? DocumentFamily::key((string) $request->query('dossier')) : null,
            'employees' => $employees,
            'templates' => $templates,
            'contractsByEmployee' => $contracts,
            'leavesByEmployee' => $leaves,
            // ADR-184 — le directeur général réglé pour ce site, s'il l'est.
            'director' => $settings->director(),
            'formFieldsByContext' => collect(DocumentDataContext::cases())
                ->mapWithKeys(fn (DocumentDataContext $context) => [$context->value => $catalog->fieldsForContext($context)])
                ->all(),
        ]);
    }

    public function preview(PreviewGeneratedDocumentRequest $request, PreviewGeneratedDocumentAction $action): JsonResponse
    {
        [$template, $employee, $contract, $leave] = $this->resolveEntities($request->validated());

        return response()->json($action->execute(
            $template,
            $employee,
            $contract,
            $leave,
            $request->validated('form_data', []) ?? [],
            $request->boolean('with_director_signature'),
        ));
    }

    public function store(StoreGeneratedDocumentRequest $request, CreateGeneratedDocumentAction $action): RedirectResponse
    {
        [$template, $employee, $contract, $leave] = $this->resolveEntities($request->validated());
        $replaces = filled($request->validated('replaces_uuid'))
            ? GeneratedDocument::withTrashed()->where('uuid', $request->validated('replaces_uuid'))->firstOrFail()
            : null;
        $document = $action->execute(
            $template,
            $employee,
            $contract,
            $leave,
            $request->validated('form_data', []) ?? [],
            $request->user(),
            $request->boolean('with_director_signature'),
            $replaces,
        );

        return to_route('administration.generated-documents.print', $document->uuid)
            ->with('status', $replaces ? 'Nouvelle version générée ; l’ancienne est archivée.' : 'Document généré.');
    }

    public function print(Request $request, GeneratedDocument $generatedDocument): Response
    {
        Gate::forUser($request->user())->authorize('print', $generatedDocument);
        $generatedDocument->load(['employee.department', 'employee.jobTitle', 'generatedBy', 'employmentContract.contractType', 'leaveRequest.leaveType', 'replaces', 'replacedBy']);

        return Inertia::render('Administration/Documents/Print', [
            'document' => $this->detail($generatedDocument),
        ]);
    }

    /** ADR-199 — « Supprimer » : archiver avec un motif, jamais effacer. */
    public function destroy(ArchiveGeneratedDocumentRequest $request, GeneratedDocument $generatedDocument, ArchiveGeneratedDocumentAction $action): RedirectResponse
    {
        $action->execute($generatedDocument, $request->validated('reason'), $request->user());

        return to_route('administration.generated-documents.index', ['dossier' => DocumentFamily::key($generatedDocument->document_type_snapshot)])
            ->with('status', 'Document archivé.');
    }

    public function restore(Request $request, GeneratedDocument $generatedDocument, RestoreGeneratedDocumentAction $action): RedirectResponse
    {
        $action->execute($generatedDocument, $request->user());

        return to_route('administration.generated-documents.index', ['dossier' => DocumentFamily::key($generatedDocument->document_type_snapshot)])
            ->with('status', 'Document restauré.');
    }

    /** Le canevas en vigueur pour refaire un document : le même, sinon sa version active. */
    private function currentTemplateUuid(GeneratedDocument $document): string
    {
        $template = $document->documentTemplate;
        if ($template && $template->active && ! $template->trashed()) {
            return $template->uuid;
        }

        return (string) DocumentTemplate::query()->where('lineage_id', $template?->lineage_id)
            ->where('active', true)->latest('id')->value('uuid');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: DocumentTemplate, 1: Employee, 2: ?EmploymentContract, 3: ?LeaveRequest}
     */
    private function resolveEntities(array $validated): array
    {
        $template = DocumentTemplate::query()->where('uuid', $validated['document_template_uuid'])->firstOrFail();
        $employee = Employee::query()->where('uuid', $validated['employee_uuid'])->firstOrFail();
        $contract = filled($validated['employment_contract_uuid'] ?? null)
            ? EmploymentContract::query()->where('uuid', $validated['employment_contract_uuid'])->firstOrFail()
            : null;
        $leave = filled($validated['leave_request_uuid'] ?? null)
            ? LeaveRequest::query()->where('uuid', $validated['leave_request_uuid'])->firstOrFail()
            : null;

        return [$template, $employee, $contract, $leave];
    }

    /** @return array<int, array<string, mixed>> */
    private function employees(): array
    {
        return Employee::query()->where('active', true)->with(['department', 'jobTitle'])
            ->orderBy('last_name')->get()
            ->map(fn (Employee $employee) => $this->presenter->employeeOption($employee))->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function activeTemplates(): array
    {
        return DocumentTemplate::query()->where('active', true)->orderBy('name')->get()
            ->map(fn (DocumentTemplate $template) => [
                'uuid' => $template->uuid,
                'name' => $template->name,
                'document_type' => $template->document_type,
                'data_context' => $template->data_context->value,
                'data_context_label' => $template->data_context->label(),
                'description' => $template->description,
            ])->all();
    }

    /** @return array<string, array<int, array<string, string>>> employee uuid => contracts */
    private function contractsByEmployee(): array
    {
        return EmploymentContract::query()->with('employee:id,uuid')->orderByDesc('starts_on')->get()
            ->groupBy(fn (EmploymentContract $contract) => $contract->employee->uuid)
            ->map(fn ($contracts) => $contracts->map(fn (EmploymentContract $contract) => [
                'uuid' => $contract->uuid,
                'label' => trim(($contract->reference_number ?: 'Contrat').' · '.$contract->starts_on?->toDateString()),
            ])->values()->all())->all();
    }

    /** @return array<string, array<int, array<string, string>>> employee uuid => leave requests */
    private function leavesByEmployee(): array
    {
        return LeaveRequest::query()->with(['employee:id,uuid', 'leaveType'])
            ->where('status', '!=', LeaveRequestStatus::Cancelled->value)
            ->orderByDesc('starts_on')->get()
            ->groupBy(fn (LeaveRequest $leave) => $leave->employee->uuid)
            ->map(fn ($leaves) => $leaves->map(fn (LeaveRequest $leave) => [
                'uuid' => $leave->uuid,
                'label' => ($leave->leaveType?->label ?? 'Congé').' · du '.$leave->starts_on?->format('d/m/Y').' au '.$leave->returns_on?->format('d/m/Y'),
                'status' => $leave->status->label(),
            ])->values()->all())->all();
    }

    /** @return array<string, mixed> */
    private function summarize(GeneratedDocument $document): array
    {
        $contract = $document->relationLoaded('employmentContract') ? $document->employmentContract : null;
        $leave = $document->relationLoaded('leaveRequest') ? $document->leaveRequest : null;

        return [
            'uuid' => $document->uuid,
            'template_name' => $document->template_name_snapshot,
            'document_type' => $document->document_type_snapshot,
            'folder' => DocumentFamily::key($document->document_type_snapshot),
            'employee' => $this->presenter->employeeOption($document->employee),
            'generated_by' => RemoteActorAttribution::name($document->generatedBy?->name, $document->external_generated_by_name),
            'created_at' => $document->created_at?->toIso8601String(),
            // D'où il vient : le contrat ou le congé qu'il reprend.
            'source' => match (true) {
                $contract !== null => ['kind' => 'contract', 'uuid' => $contract->uuid, 'label' => trim(($contract->contractType?->label ?? 'Contrat').' · '.($contract->reference_number ?: 'du '.$contract->starts_on?->format('d/m/Y')))],
                $leave !== null => ['kind' => 'leave', 'uuid' => $leave->uuid, 'label' => ($leave->leaveType?->label ?? 'Congé').' · du '.$leave->starts_on?->format('d/m/Y').' au '.$leave->returns_on?->format('d/m/Y')],
                default => null,
            },
            // ADR-199 — archivé (motif, par qui) et chaîne des versions.
            'archived' => $document->trashed(),
            'archived_at' => $document->deleted_at?->toIso8601String(),
            'archive_reason' => $document->delete_reason,
            'archived_by' => $document->trashed()
                ? RemoteActorAttribution::name($document->deleted_by ? User::query()->whereKey($document->deleted_by)->value('name') : null, $document->external_deleted_by_name)
                : null,
            'replaces' => $document->relationLoaded('replaces') && $document->replaces
                ? ['uuid' => $document->replaces->uuid, 'created_at' => $document->replaces->created_at?->toIso8601String()]
                : null,
            'replaced_by' => $document->relationLoaded('replacedBy') && $document->replacedBy
                ? ['uuid' => $document->replacedBy->uuid, 'created_at' => $document->replacedBy->created_at?->toIso8601String()]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(GeneratedDocument $document): array
    {
        return [
            ...$this->summarize($document),
            'rendered_html' => $document->rendered_html_snapshot,
        ];
    }
}
