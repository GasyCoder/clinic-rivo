<?php

namespace App\Actions\Administration;

use App\Models\HrReferenceValue;
use App\Models\User;
use App\Support\Hr\JobTitleAccountRole;
use App\Support\Hr\JobTitleBenefits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateHrReferenceValueAction
{
    public function __construct(private readonly SyncJobTitleDepartmentsAction $departments) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): HrReferenceValue
    {
        Gate::forUser($actor)->authorize('create', HrReferenceValue::class);

        return DB::transaction(function () use ($data): HrReferenceValue {
            $departmentUuids = $data['department_uuids'] ?? null;
            unset($data['department_uuids']);
            // ADR-199 — le rôle proposé est rangé dans les métadonnées de la fonction.
            [$data, $metadata] = JobTitleAccountRole::takeFrom($data, $data['metadata'] ?? null);
            if ($metadata !== null) {
                $data['metadata'] = $metadata;
            }
            // ADR-221 — la fonction ouvre-t-elle droit aux avantages et primes ?
            [$data, $benefits] = JobTitleBenefits::takeFrom($data, $data['metadata'] ?? null);
            if ($benefits !== null) {
                $data['metadata'] = $benefits;
            }
            $reference = HrReferenceValue::query()->create($data);

            if (is_array($departmentUuids)) {
                $this->departments->execute($reference, $departmentUuids);
            }

            return $reference;
        });
    }
}
