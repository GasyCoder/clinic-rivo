<?php

namespace App\Actions\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class CreateAttendanceRecordAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('create', AttendanceRecord::class);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        unset($data['employee_uuid']);

        return AttendanceRecord::query()->create([
            ...$data,
            'employee_id' => $employee->getKey(),
            'work_date' => CarbonImmutable::parse($data['started_at'])->toDateString(),
        ])->load('employee');
    }
}
