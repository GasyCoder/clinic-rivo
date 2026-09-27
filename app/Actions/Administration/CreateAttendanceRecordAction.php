<?php

namespace App\Actions\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Administration\AttendanceOverlapGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateAttendanceRecordAction
{
    public function __construct(private readonly AttendanceOverlapGuard $guard) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('create', AttendanceRecord::class);

        return DB::transaction(function () use ($data): AttendanceRecord {
            $employee = Employee::query()->where('uuid', $data['employee_uuid'])->lockForUpdate()->firstOrFail();
            unset($data['employee_uuid']);
            $this->guard->assertFree($employee, $data['started_at'], $data['ended_at'] ?? null);

            return AttendanceRecord::query()->create([
                ...$data,
                'employee_id' => $employee->getKey(),
                'work_date' => CarbonImmutable::parse($data['started_at'])->toDateString(),
            ])->load('employee');
        });
    }
}
