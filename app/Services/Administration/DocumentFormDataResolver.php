<?php

namespace App\Services\Administration;

use App\Enums\DocumentDataContext;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Builds "page 1" of a generated document (ADR-087): the RH-facing form
 * pre-filled from Employee/EmploymentContract/LeaveRequest, still editable
 * before generation. Replaces the removed DocumentVariableResolver — the
 * canevas content itself (from `DocumentTemplate::content_html`) is never
 * touched here, it is passed through verbatim, no substitution needed.
 */
class DocumentFormDataResolver
{
    /**
     * Literal separator between page 1 and the canevas content — must stay
     * identical to the one used by the SuperAdmin editor and Print.vue's
     * print CSS (`.canevas-page-break`).
     */
    public const PAGE_BREAK_HTML = '<div data-page-break class="canevas-page-break"></div>';

    public function __construct(private readonly DocumentFormFieldCatalog $catalog) {}

    public function assertContext(DocumentDataContext $context, ?EmploymentContract $contract, ?LeaveRequest $leave): void
    {
        if ($context === DocumentDataContext::EmployeeAndContract && ! $contract) {
            throw ValidationException::withMessages(['employment_contract_uuid' => 'Ce canevas nécessite de choisir un contrat.']);
        }

        if ($context === DocumentDataContext::EmployeeAndLeave && ! $leave) {
            throw ValidationException::withMessages(['leave_request_uuid' => 'Ce canevas nécessite de choisir une demande de congé.']);
        }
    }

    /**
     * @param  array<string, string>  $formData  RH-submitted values — win over the auto-filled known values for the same key.
     * @return array{values: array<string, string>, missing_required: array<int, string>}
     */
    public function resolve(
        DocumentDataContext $context,
        Employee $employee,
        ?EmploymentContract $contract,
        ?LeaveRequest $leave,
        array $formData,
    ): array {
        $known = $this->knownValues($context, $employee, $contract, $leave);
        $fields = $this->catalog->fieldsForContext($context);

        $values = collect($fields)->mapWithKeys(fn (array $field) => [
            $field['key'] => trim((string) ($formData[$field['key']] ?? $known->get($field['key'], ''))),
        ]);

        $missingRequired = collect($fields)
            ->filter(fn (array $field) => $field['required'] && $values->get($field['key']) === '')
            ->pluck('label')->values();

        return ['values' => $values->all(), 'missing_required' => $missingRequired->all()];
    }

    /** @param array<string, string> $values */
    public function renderPageOne(DocumentDataContext $context, array $values): string
    {
        $rows = collect($this->catalog->fieldsForContext($context))
            ->map(fn (array $field) => sprintf(
                '<p><strong>%s :</strong> %s</p>',
                e($field['label']),
                e($values[$field['key']] ?? ''),
            ))
            ->implode('');

        return '<div data-generated-page-one class="canevas-page-one">'.$rows.'</div>';
    }

    /** @return Collection<string, string> */
    private function knownValues(
        DocumentDataContext $context,
        Employee $employee,
        ?EmploymentContract $contract,
        ?LeaveRequest $leave,
    ): Collection {
        $employee->loadMissing(['department', 'jobTitle']);
        $date = fn ($value) => $value?->translatedFormat('d/m/Y') ?? '';

        $values = collect([
            'nom' => $employee->last_name ?? '',
            'prenom' => $employee->first_name ?? '',
            'matricule' => $employee->employee_number ?? '',
            'poste' => $employee->jobTitle?->label ?? $employee->profession ?? '',
            'service' => $employee->department?->label ?? '',
            'date_naissance' => $date($employee->birth_date),
            'date_embauche' => $date($employee->hire_date),
            'date' => now()->translatedFormat('d/m/Y'),
        ]);

        if ($context === DocumentDataContext::EmployeeAndContract && $contract) {
            $contract->loadMissing('contractType');
            $values = $values->merge([
                'type_contrat' => $contract->contractType?->label ?? '',
                'reference_contrat' => $contract->reference_number ?? '',
                'date_signature' => $date($contract->signed_on),
                'date_debut' => $date($contract->starts_on),
                'fin_periode_essai' => $date($contract->trial_ends_on),
                'date_fin' => $date($contract->ends_on),
            ]);
        }

        if ($context === DocumentDataContext::EmployeeAndLeave && $leave) {
            $leave->loadMissing('leaveType');
            $values = $values->merge([
                'type_conge' => $leave->leaveType?->label ?? '',
                'date_depart' => $date($leave->starts_on),
                'date_retour' => $date($leave->returns_on),
                'jours_demandes' => (string) $leave->days_requested,
                'motif_conge' => $leave->reason ?? '',
            ]);
        }

        return $values;
    }
}
