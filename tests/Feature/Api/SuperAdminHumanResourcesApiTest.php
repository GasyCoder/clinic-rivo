<?php

namespace Tests\Feature\Api;

use App\Enums\HrReferenceType;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminHumanResourcesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'M',
            'rivo.site.name' => 'Mampikony',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
    }

    public function test_remote_hr_overview_requires_employee_permission_and_exposes_only_authorized_metrics(): void
    {
        $employee = Employee::query()->create([
            'employee_number' => 'RH-API-001',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'civility' => 'MRS',
            'active' => true,
        ]);
        $contractType = HrReferenceValue::query()->create([
            'type' => HrReferenceType::ContractType,
            'code' => 'CDI',
            'label' => 'CDI',
            'active' => true,
        ]);
        EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type_id' => $contractType->id,
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addDays(15)->toDateString(),
        ]);
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'work_date' => now()->toDateString(),
            'started_at' => now()->subHour(),
        ]);
        LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'reason' => 'Repos demandé',
            'requested_on' => now()->toDateString(),
            'starts_on' => now()->addDay()->toDateString(),
            'returns_on' => now()->addDays(2)->toDateString(),
        ]);
        PlanningShift::query()->create([
            'employee_id' => $employee->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(8),
        ]);

        $this->withHeaders($this->headers([]))
            ->getJson('/api/v1/super-admin/human-resources')
            ->assertForbidden();

        $this->withHeaders($this->headers([
            'employees.view', 'contracts.view', 'attendance.view', 'leave.view', 'planning.view',
        ]))->getJson('/api/v1/super-admin/human-resources')
            ->assertOk()
            ->assertJsonPath('data.summary.active_employees', 1)
            ->assertJsonPath('data.summary.current_contracts', 1)
            ->assertJsonPath('data.summary.contracts_ending_soon', 1)
            ->assertJsonPath('data.summary.open_attendance', 1)
            ->assertJsonPath('data.summary.pending_leave', 1)
            ->assertJsonPath('data.summary.upcoming_shifts', 1)
            ->assertJsonPath('meta.site.code', 'M')
            ->assertJsonPath('meta.scope', 'READ_ONLY');

        $this->withHeaders($this->headers(['employees.view']))
            ->getJson('/api/v1/super-admin/human-resources')
            ->assertOk()
            ->assertJsonPath('data.summary.active_employees', 1)
            ->assertJsonPath('data.summary.current_contracts', null)
            ->assertJsonPath('data.summary.open_attendance', null);
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }
}
