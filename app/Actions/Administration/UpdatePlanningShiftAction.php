<?php

namespace App\Actions\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\PlanningShift;
use App\Models\User;
use App\Services\Administration\HrReferenceResolver;
use Illuminate\Support\Facades\Gate;

class UpdatePlanningShiftAction
{
    public function __construct(private readonly HrReferenceResolver $references) {}

    /** @param array<string, mixed> $data */
    public function execute(PlanningShift $shift, array $data, User $actor): PlanningShift
    {
        Gate::forUser($actor)->authorize('update', $shift);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $department = $this->references->resolve(
            $data['department_uuid'] ?? null,
            HrReferenceType::Department,
            'department_uuid',
        );
        unset($data['employee_uuid'], $data['department_uuid']);

        $shift->fill([
            ...$data,
            'employee_id' => $employee->getKey(),
            'department_id' => $department?->getKey() ?? $employee->department_id,
        ])->save();

        return $shift->refresh()->load(['employee', 'department']);
    }
}
