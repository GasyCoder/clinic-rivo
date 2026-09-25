<?php

namespace Tests\Feature\Api;

use App\Enums\HrReferenceType;
use App\Enums\LeaveRequestStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-182 — le Super Admin du portail gère les Ressources humaines d'un site
 * par son API : mêmes routes, mêmes droits, mêmes actions que /administration.
 */
class SiteHrThroughPortalApiTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_UUID = '6d3f4a8e-1c2b-4d5e-9f60-7a8b9c0d1e2f';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_portal_reads_the_hr_screens_of_the_site_as_data(): void
    {
        $this->employee('RH-001', 'Rabe');

        $this->withHeaders($this->headers(['employees.view']))
            ->getJson('/api/v1/super-admin/hr/employees')
            ->assertOk()
            ->assertJsonPath('component', 'Administration/Employees/Index')
            ->assertJsonPath('props.employees.data.0.employee_number', 'RH-001')
            ->assertJsonMissingPath('props.auth');

        $this->withHeaders($this->headers(['employees.view']))
            ->getJson('/api/v1/super-admin/hr')
            ->assertOk()
            ->assertJsonPath('component', 'Administration/Index');
    }

    public function test_each_screen_keeps_its_own_permission(): void
    {
        $this->withHeaders($this->headers(['contracts.view']))
            ->getJson('/api/v1/super-admin/hr/employees')
            ->assertForbidden();

        $this->withHeaders($this->headers([]))
            ->getJson('/api/v1/super-admin/hr/employees')
            ->assertForbidden();

        $this->withHeaders([...$this->headers(['employees.view', 'employees.create']), 'Authorization' => 'Bearer wrong'])
            ->getJson('/api/v1/super-admin/hr/employees')
            ->assertUnauthorized();
    }

    public function test_a_write_runs_the_site_action_and_is_signed_by_the_super_admin(): void
    {
        $users = User::query()->count();

        $response = $this->withHeaders($this->writeHeaders(['employees.view', 'employees.create']))
            ->postJson('/api/v1/super-admin/hr/employees', [
                'employee_number' => 'RH-100',
                'last_name' => 'Rasoa',
                'first_name' => 'Hanta',
                'sex' => 'F',
                'active' => true,
            ])
            ->assertOk();

        $employee = Employee::query()->where('employee_number', 'RH-100')->sole();

        $response->assertJsonPath('redirect', '/administration/employees/'.$employee->uuid)
            ->assertJsonPath('status', 'Dossier Employé RH-100 créé.');

        $audit = AuditLog::query()->where('entity_type', $employee->getMorphClass())->where('entity_id', $employee->id)->firstOrFail();
        $this->assertNull($audit->user_id);
        $this->assertSame(self::ACTOR_UUID, $audit->external_actor_uuid);
        $this->assertSame('Direction centrale', $audit->external_actor_name);

        $this->assertSame($users, User::query()->count(), 'le Super Admin distant n’est jamais enregistré sur le site');
    }

    public function test_a_validation_error_comes_back_with_its_fields(): void
    {
        $this->employee('RH-200', 'Rakoto');

        $this->withHeaders($this->writeHeaders(['employees.view', 'employees.create']))
            ->postJson('/api/v1/super-admin/hr/employees', ['employee_number' => 'RH-200', 'last_name' => '', 'sex' => 'F'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_number', 'last_name']);
    }

    public function test_back_follows_the_page_the_super_admin_came_from(): void
    {
        $employee = $this->employee('RH-300', 'Rabe');
        $leave = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'reason' => 'Repos demandé',
            'requested_on' => now()->toDateString(),
            'starts_on' => now()->addDay()->toDateString(),
            'returns_on' => now()->addDays(2)->toDateString(),
        ]);

        $this->withHeaders([...$this->writeHeaders(['leave.view', 'leave.reject']), 'Referer' => 'http://localhost/administration/leave?status=pending'])
            ->postJson("/api/v1/super-admin/hr/leave/{$leave->uuid}/reject", ['reason' => 'Effectif insuffisant cette semaine.'])
            ->assertOk()
            ->assertJsonPath('redirect', '/administration/leave?status=pending')
            ->assertJsonPath('status', 'Demande de congé refusée.');

        $leave->refresh();
        $this->assertSame(LeaveRequestStatus::Rejected, $leave->status);
        $this->assertNull($leave->decided_by);
        $this->assertSame('Direction centrale', $leave->external_decided_by_name);
    }

    public function test_a_session_error_bag_becomes_a_validation_error(): void
    {
        $reference = HrReferenceValue::query()->create([
            'type' => HrReferenceType::Department, 'code' => 'SOINS', 'label' => 'Soins', 'active' => true,
        ]);
        $this->employee('RH-400', 'Rabe', ['department_id' => $reference->id]);

        $this->withHeaders($this->writeHeaders(['hr_settings.view', 'hr_settings.update']))
            ->putJson("/api/v1/super-admin/hr/settings/{$reference->uuid}", [
                'type' => HrReferenceType::JobTitle->value, 'code' => 'SOINS', 'label' => 'Soins', 'position' => 1, 'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_an_export_is_served_as_a_file(): void
    {
        $this->employee('RH-500', 'Rabe');

        $response = $this->withHeaders($this->headers(['employees.view', 'employees.export']))
            ->get('/api/v1/super-admin/hr/employees/export')
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
    }

    /** @param array<string, mixed> $attributes */
    private function employee(string $number, string $lastName, array $attributes = []): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'last_name' => $lastName,
            'sex' => 'F',
            'civility' => 'MRS',
            'active' => true,
            ...$attributes,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => self::ACTOR_UUID,
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }

    /** @param array<int, string> $permissions */
    private function writeHeaders(array $permissions): array
    {
        return [...$this->headers($permissions), 'Idempotency-Key' => (string) Str::uuid()];
    }
}
