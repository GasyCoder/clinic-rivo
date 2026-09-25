<?php

namespace App\Services\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use Illuminate\Validation\ValidationException;

/**
 * ADR-194 — ce qu'un contrat de stage porte en plus : sa filière (exigée),
 * l'école, le niveau et l'encadrant. Un contrat qui n'est pas de stage ne
 * garde aucun de ces champs : passer un contrat de « Stagiaire » à « CDD »
 * les efface, plutôt que de laisser une filière sur un CDD.
 */
class InternshipContractResolver
{
    public const KEYS = ['internship_field_uuid', 'internship_school', 'internship_level', 'internship_supervisor_uuid'];

    public function __construct(private readonly HrReferenceResolver $references) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(HrReferenceValue $type, array $data, ?EmploymentContract $current = null): array
    {
        if (! $type->isInternshipContractType()) {
            return [
                'internship_field_id' => null,
                'internship_school' => null,
                'internship_level' => null,
                'internship_supervisor_id' => null,
            ];
        }

        $uuid = $data['internship_field_uuid'] ?? null;

        if (! $uuid) {
            throw ValidationException::withMessages([
                'internship_field_uuid' => 'Choisissez la filière du stage (Infirmier, Sage-femme…).',
            ]);
        }

        $currentField = $current?->internship_field_id
            ? HrReferenceValue::withTrashed()->find($current->internship_field_id)
            : null;
        $field = $this->references->resolveKeeping($uuid, HrReferenceType::InternshipField, 'internship_field_uuid', $currentField);
        $supervisorUuid = $data['internship_supervisor_uuid'] ?? null;

        return [
            'internship_field_id' => $field?->getKey(),
            'internship_school' => $data['internship_school'] ?? null,
            'internship_level' => $data['internship_level'] ?? null,
            'internship_supervisor_id' => $supervisorUuid
                ? Employee::query()->where('uuid', $supervisorUuid)->value('id')
                : null,
        ];
    }
}
