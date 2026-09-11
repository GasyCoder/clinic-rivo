<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\CreateGeneratedDocumentAction;
use App\Actions\Administration\PreviewGeneratedDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\PreviewGeneratedDocumentRequest;
use App\Http\Requests\Administration\StoreGeneratedDocumentRequest;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\LeaveRequest;
use App\Services\Administration\HrPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GeneratedDocumentController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', GeneratedDocument::class);
        $search = trim((string) $request->query('q', ''));

        $documents = GeneratedDocument::query()
            ->with(['employee.department', 'employee.jobTitle', 'generatedBy'])
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('template_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('document_type_snapshot', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('last_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%"));
            }))
            ->latest('created_at')->paginate(20)->withQueryString()
            ->through(fn (GeneratedDocument $document) => $this->summarize($document));

        return Inertia::render('Administration/Documents/Index', [
            'documents' => $documents,
            'filters' => ['q' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', GeneratedDocument::class);

        return Inertia::render('Administration/Documents/Create', [
            'employees' => $this->employees(),
            'templates' => $this->activeTemplates(),
            'contractsByEmployee' => $this->contractsByEmployee(),
            'leavesByEmployee' => $this->leavesByEmployee(),
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
            $request->validated('manual_variables', []) ?? [],
        ));
    }

    public function store(StoreGeneratedDocumentRequest $request, CreateGeneratedDocumentAction $action): RedirectResponse
    {
        [$template, $employee, $contract, $leave] = $this->resolveEntities($request->validated());
        $document = $action->execute(
            $template,
            $employee,
            $contract,
            $leave,
            $request->validated('manual_variables', []) ?? [],
            $request->user(),
        );

        return to_route('administration.generated-documents.print', $document->uuid)
            ->with('status', 'Document généré.');
    }

    public function print(Request $request, GeneratedDocument $generatedDocument): Response
    {
        Gate::forUser($request->user())->authorize('print', $generatedDocument);
        $generatedDocument->load(['employee.department', 'employee.jobTitle', 'generatedBy']);

        return Inertia::render('Administration/Documents/Print', [
            'document' => $this->detail($generatedDocument),
        ]);
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
                'variables_used' => $template->variables_used ?? [],
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
        return LeaveRequest::query()->with(['employee:id,uuid', 'leaveType'])->orderByDesc('starts_on')->get()
            ->groupBy(fn (LeaveRequest $leave) => $leave->employee->uuid)
            ->map(fn ($leaves) => $leaves->map(fn (LeaveRequest $leave) => [
                'uuid' => $leave->uuid,
                'label' => ($leave->leaveType?->label ?? 'Congé').' · '.$leave->starts_on?->toDateString().' → '.$leave->returns_on?->toDateString(),
            ])->values()->all())->all();
    }

    /** @return array<string, mixed> */
    private function summarize(GeneratedDocument $document): array
    {
        return [
            'uuid' => $document->uuid,
            'template_name' => $document->template_name_snapshot,
            'document_type' => $document->document_type_snapshot,
            'employee' => $this->presenter->employeeOption($document->employee),
            'generated_by' => $document->generatedBy?->name,
            'created_at' => $document->created_at?->toIso8601String(),
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
