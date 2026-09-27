<?php

namespace App\Services\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use Illuminate\Validation\ValidationException;

class HrReferenceResolver
{
    public function __construct(private readonly JobTitleDepartmentGuard $jobTitles) {}

    public function resolve(?string $uuid, HrReferenceType $type, string $field): ?HrReferenceValue
    {
        if (! $uuid) {
            return null;
        }

        $reference = HrReferenceValue::query()
            ->where('uuid', $uuid)
            ->where('type', $type->value)
            ->where('active', true)
            ->first();

        if (! $reference) {
            throw ValidationException::withMessages([
                $field => "Cette valeur de {$type->label()} n’est plus disponible.",
            ]);
        }

        return $reference;
    }

    /**
     * Comme `resolve()`, mais la valeur déjà enregistrée sur le dossier est
     * reprise même archivée : le formulaire la propose (ADR-066), et corriger
     * un autre champ ne doit pas exiger d'en choisir une nouvelle.
     */
    public function resolveKeeping(?string $uuid, HrReferenceType $type, string $field, ?HrReferenceValue $current): ?HrReferenceValue
    {
        if ($uuid && $current && $current->uuid === $uuid) {
            return $current;
        }

        return $this->resolve($uuid, $type, $field);
    }

    /** @param array<string, mixed> $data */
    public function employeeData(array $data, ?Employee $employee = null): array
    {
        $currentDepartment = $employee?->department_id
            ? HrReferenceValue::withTrashed()->find($employee->department_id)
            : null;
        $currentJobTitle = $employee?->job_title_id
            ? HrReferenceValue::withTrashed()->find($employee->job_title_id)
            : null;
        $department = $currentDepartment;
        $jobTitle = $currentJobTitle;

        if (array_key_exists('department_uuid', $data)) {
            $department = $this->resolveKeeping($data['department_uuid'], HrReferenceType::Department, 'department_uuid', $currentDepartment);
            $data['department_id'] = $department?->getKey();
            unset($data['department_uuid']);
        }

        if (array_key_exists('job_title_uuid', $data)) {
            $jobTitle = $this->resolveKeeping($data['job_title_uuid'], HrReferenceType::JobTitle, 'job_title_uuid', $currentJobTitle);
            $data['job_title_id'] = $jobTitle?->getKey();
            $data['profession'] = $jobTitle?->label;
            unset($data['job_title_uuid']);
        }

        // ADR-194 — la fonction doit exister dans le département choisi.
        $this->jobTitles->ensure($department, $jobTitle, $employee);

        return $data;
    }
}
