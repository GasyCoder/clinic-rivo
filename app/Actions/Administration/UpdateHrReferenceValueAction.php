<?php

namespace App\Actions\Administration;

use App\Models\HrReferenceValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateHrReferenceValueAction
{
    public function __construct(private readonly SyncJobTitleDepartmentsAction $departments) {}

    /** @param array<string, mixed> $data */
    public function execute(HrReferenceValue $reference, array $data, User $actor): HrReferenceValue
    {
        Gate::forUser($actor)->authorize('update', $reference);

        return DB::transaction(function () use ($reference, $data): HrReferenceValue {
            $departmentUuids = $data['department_uuids'] ?? null;
            unset($data['department_uuids']);
            $reference->fill($data)->save();

            if (is_array($departmentUuids)) {
                $this->departments->execute($reference, $departmentUuids);
            }

            return $reference->refresh();
        });
    }
}
