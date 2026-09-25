<?php

namespace App\Actions\Administration;

use App\Models\HrReferenceValue;
use App\Models\User;
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
            $reference = HrReferenceValue::query()->create($data);

            if (is_array($departmentUuids)) {
                $this->departments->execute($reference, $departmentUuids);
            }

            return $reference;
        });
    }
}
