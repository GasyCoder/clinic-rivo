<?php

namespace App\Services\Administration;

use App\Enums\HrReferenceType;
use App\Models\HrReferenceValue;
use Illuminate\Validation\ValidationException;

class HrReferenceResolver
{
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

    /** @param array<string, mixed> $data */
    public function employeeData(array $data): array
    {
        if (array_key_exists('department_uuid', $data)) {
            $data['department_id'] = $this->resolve(
                $data['department_uuid'],
                HrReferenceType::Department,
                'department_uuid',
            )?->getKey();
            unset($data['department_uuid']);
        }

        if (array_key_exists('job_title_uuid', $data)) {
            $jobTitle = $this->resolve(
                $data['job_title_uuid'],
                HrReferenceType::JobTitle,
                'job_title_uuid',
            );
            $data['job_title_id'] = $jobTitle?->getKey();
            $data['profession'] = $jobTitle?->label;
            unset($data['job_title_uuid']);
        }

        return $data;
    }
}
