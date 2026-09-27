<?php

namespace App\Actions\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Administration\AttendanceOverlapGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateAttendanceRecordAction
{
    public function __construct(private readonly AttendanceOverlapGuard $guard) {}

    /** @param array<string, mixed> $data */
    public function execute(AttendanceRecord $record, array $data, User $actor): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($record, $data): AttendanceRecord {
            $employee = Employee::query()->where('uuid', $data['employee_uuid'])->lockForUpdate()->firstOrFail();
            unset($data['employee_uuid']);
            $this->guard->assertFree($employee, $data['started_at'], $data['ended_at'] ?? null, $record);

            $record->fill([
                ...$data,
                'employee_id' => $employee->getKey(),
                'work_date' => CarbonImmutable::parse($data['started_at'])->toDateString(),
            ])->save();

            return $record->refresh()->load('employee');
        });
    }
}
