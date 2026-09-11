<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Administration\DocumentVariableResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateGeneratedDocumentAction
{
    public function __construct(private readonly DocumentVariableResolver $resolver) {}

    /** @param array<string, string> $manualVariables */
    public function execute(
        DocumentTemplate $template,
        Employee $employee,
        ?EmploymentContract $contract,
        ?LeaveRequest $leave,
        array $manualVariables,
        User $actor,
    ): GeneratedDocument {
        Gate::forUser($actor)->authorize('create', GeneratedDocument::class);

        if ($contract && $contract->employee_id !== $employee->getKey()) {
            throw ValidationException::withMessages(['employment_contract_uuid' => 'Ce contrat n’appartient pas à cet employé.']);
        }

        if ($leave && $leave->employee_id !== $employee->getKey()) {
            throw ValidationException::withMessages(['leave_request_uuid' => 'Cette demande de congé n’appartient pas à cet employé.']);
        }

        return DB::transaction(function () use ($template, $employee, $contract, $leave, $manualVariables, $actor): GeneratedDocument {
            $resolution = $this->resolver->resolve($template, $employee, $contract, $leave, $manualVariables);

            // A document actually handed out must never carry a silently
            // blank legal clause: unlike the live preview, generation itself
            // requires every variable the canevas references to have a real
            // value (auto-resolved or explicitly typed by the RH) — {{salaire}}
            // and any other manual-only variable included.
            $stillMissing = collect($resolution['missing'])
                ->reject(fn (string $code) => trim((string) ($manualVariables[$code] ?? '')) !== '')
                ->values();

            if ($stillMissing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'manual_variables' => 'Renseignez toutes les variables demandées avant de générer le document : '.$stillMissing->implode(', ').'.',
                ]);
            }

            return GeneratedDocument::query()->create([
                'document_template_id' => $template->getKey(),
                'template_name_snapshot' => $template->name,
                'document_type_snapshot' => $template->document_type,
                'employee_id' => $employee->getKey(),
                'employment_contract_id' => $contract?->getKey(),
                'leave_request_id' => $leave?->getKey(),
                'resolved_variables_snapshot' => $resolution['resolved'],
                'manual_variables_snapshot' => $manualVariables,
                'rendered_html_snapshot' => $this->resolver->render($template->content_html, $resolution['replacements']),
                'generated_by' => $actor->getKey(),
            ]);
        });
    }
}
