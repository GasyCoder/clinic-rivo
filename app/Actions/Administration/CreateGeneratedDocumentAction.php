<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Administration\DocumentFormDataResolver;
use App\Services\Settings\AppSettings;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateGeneratedDocumentAction
{
    public function __construct(
        private readonly DocumentFormDataResolver $resolver,
        private readonly AppSettings $settings,
    ) {}

    /** @param array<string, string> $formData */
    public function execute(
        DocumentTemplate $template,
        Employee $employee,
        ?EmploymentContract $contract,
        ?LeaveRequest $leave,
        array $formData,
        User $actor,
        bool $withDirectorSignature = false,
    ): GeneratedDocument {
        Gate::forUser($actor)->authorize('create', GeneratedDocument::class);

        if ($contract && $contract->employee_id !== $employee->getKey()) {
            throw ValidationException::withMessages(['employment_contract_uuid' => 'Ce contrat n’appartient pas à cet employé.']);
        }

        if ($leave && $leave->employee_id !== $employee->getKey()) {
            throw ValidationException::withMessages(['leave_request_uuid' => 'Cette demande de congé n’appartient pas à cet employé.']);
        }

        return DB::transaction(function () use ($template, $employee, $contract, $leave, $formData, $actor, $withDirectorSignature): GeneratedDocument {
            $this->resolver->assertContext($template->data_context, $contract, $leave);
            $resolution = $this->resolver->resolve($template->data_context, $employee, $contract, $leave, $formData);

            // A document actually handed out must never carry a silently
            // blank legal field: unlike the live preview, generation itself
            // requires every required page-1 field to have a real value
            // (auto-resolved or explicitly typed by the RH).
            if ($resolution['missing_required'] !== []) {
                throw ValidationException::withMessages([
                    'form_data' => 'Renseignez tous les champs requis avant de générer le document : '
                        .implode(', ', $resolution['missing_required']).'.',
                ]);
            }

            $pageOneHtml = $this->resolver->renderPageOne($template->data_context, $resolution['values']);

            return GeneratedDocument::query()->create([
                'document_template_id' => $template->getKey(),
                'template_name_snapshot' => $template->name,
                'document_type_snapshot' => $template->document_type,
                'employee_id' => $employee->getKey(),
                'employment_contract_id' => $contract?->getKey(),
                'leave_request_id' => $leave?->getKey(),
                'form_data_snapshot' => $resolution['values'],
                'rendered_html_snapshot' => $pageOneHtml.DocumentFormDataResolver::PAGE_BREAK_HTML.$template->content_html
                    .($withDirectorSignature ? $this->resolver->renderDirectorSignature($this->settings) : ''),
                'generated_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('generated', $actor),
            ]);
        });
    }
}
