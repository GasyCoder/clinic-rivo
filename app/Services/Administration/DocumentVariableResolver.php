<?php

namespace App\Services\Administration;

use App\Enums\DocumentDataContext;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fills the variables a canevas actually uses (ADR-070). Anything the
 * template references that isn't in DocumentVariableCatalog for its context —
 * {{salaire}} included, since RIVO stores no payroll data (ADR-066/069) —
 * is never invented: it falls back to whatever the RH typed manually, and
 * is reported back as "missing" until they do.
 */
class DocumentVariableResolver
{
    /**
     * @param  array<string, string>  $manualVariables
     * @return array{resolved: array<string, string>, missing: array<int, string>, replacements: array<string, string>}
     */
    public function resolve(
        DocumentTemplate $template,
        Employee $employee,
        ?EmploymentContract $contract,
        ?LeaveRequest $leave,
        array $manualVariables = [],
    ): array {
        $this->assertContext($template->data_context, $contract, $leave);

        $known = $this->knownValues($template->data_context, $employee, $contract, $leave);
        $used = collect($template->variables_used ?? []);
        $missing = $used->diff($known->keys())->values();

        $replacements = $used->mapWithKeys(fn (string $code) => [
            $code => $known->has($code) ? $known->get($code) : (string) ($manualVariables[$code] ?? ''),
        ]);

        return [
            'resolved' => $known->only($used)->all(),
            'missing' => $missing->all(),
            'replacements' => $replacements->all(),
        ];
    }

    /** @param array<string, string> $replacements */
    public function render(string $html, array $replacements): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u',
            fn (array $matches) => array_key_exists($matches[1], $replacements)
                ? htmlspecialchars($replacements[$matches[1]], ENT_QUOTES, 'UTF-8')
                : $matches[0],
            $html,
        ) ?? $html;
    }

    private function assertContext(DocumentDataContext $context, ?EmploymentContract $contract, ?LeaveRequest $leave): void
    {
        if ($context === DocumentDataContext::EmployeeAndContract && ! $contract) {
            throw ValidationException::withMessages(['employment_contract_uuid' => 'Ce canevas nécessite de choisir un contrat.']);
        }

        if ($context === DocumentDataContext::EmployeeAndLeave && ! $leave) {
            throw ValidationException::withMessages(['leave_request_uuid' => 'Ce canevas nécessite de choisir une demande de congé.']);
        }
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
            'nom_complet' => trim(($employee->last_name ?? '').' '.($employee->first_name ?? '')),
            'matricule' => $employee->employee_number ?? '',
            'poste' => $employee->jobTitle?->label ?? $employee->profession ?? '',
            'service' => $employee->department?->label ?? '',
            'date_naissance' => $date($employee->birth_date),
            'date_embauche' => $date($employee->hire_date),
            'site' => (string) (config('rivo.site.name') ?? config('app.name')),
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
