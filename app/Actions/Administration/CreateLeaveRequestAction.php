<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateLeaveRequestAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): LeaveRequest
    {
        Gate::forUser($actor)->authorize('create', LeaveRequest::class);

        return DB::transaction(function () use ($data): LeaveRequest {
            $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
            $interim = ! empty($data['interim_employee_uuid'])
                ? Employee::query()->where('uuid', $data['interim_employee_uuid'])->firstOrFail()
                : null;
            unset($data['employee_uuid'], $data['interim_employee_uuid']);

            return LeaveRequest::query()->create([
                ...$data,
                'employee_id' => $employee->getKey(),
                'interim_employee_id' => $interim?->getKey(),
            ])->load(['employee', 'interimEmployee']);
        });
    }
}
