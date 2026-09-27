<?php

namespace App\Actions\Administration;

use App\Enums\HrReferenceType;
use App\Models\HrReferenceValue;
use App\Services\Audit\Auditor;

/**
 * ADR-194 — règle les départements où une fonction existe. Le lien n'est
 * pas un attribut du référentiel : son changement est audité à part, avec
 * l'ancienne et la nouvelle liste, pour qu'on sache qui a retiré « Gardien »
 * du Laboratoire.
 */
class SyncJobTitleDepartmentsAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<int, string> $departmentUuids */
    public function execute(HrReferenceValue $jobTitle, array $departmentUuids): void
    {
        if ($jobTitle->type !== HrReferenceType::JobTitle) {
            return;
        }

        $before = $jobTitle->departments()->withTrashed()->orderBy('label')->pluck('label')->all();
        $ids = HrReferenceValue::query()->ofType(HrReferenceType::Department)
            ->whereIn('uuid', $departmentUuids)->pluck('id')->all();
        $changes = $jobTitle->departments()->sync($ids);

        if (($changes['attached'] ?? []) === [] && ($changes['detached'] ?? []) === []) {
            return;
        }

        $after = $jobTitle->departments()->withTrashed()->orderBy('label')->pluck('label')->all();
        $this->auditor->record(
            'hr_reference.departments.update',
            $jobTitle,
            ['departments' => $after],
            ['departments' => $before],
            module: 'administration',
        );
    }
}
