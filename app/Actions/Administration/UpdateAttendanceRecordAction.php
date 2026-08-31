<?php

namespace App\Actions\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class UpdateAttendanceRecordAction
{
    /** @param array<string, mixed> $data */
    public function execute(AttendanceRecord $record, array $data, User $actor): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('update', $record);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        unset($data['employee_uuid']);

        $record->fill([
            ...$data,
            'employee_id' => $employee->getKey(),
            'work_date' => CarbonImmutable::parse($data['started_at'])->toDateString(),
        ])->save();

        return $record->refresh()->load('employee');
    }
}
