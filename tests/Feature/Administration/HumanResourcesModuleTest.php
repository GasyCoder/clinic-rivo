<?php

namespace Tests\Feature\Administration;

use App\Enums\HrReferenceType;
use App\Enums\LeaveRequestStatus;
use App\Exceptions\ForceDeleteForbiddenException;
use App\Exceptions\InvalidLeaveRequestTransitionException;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrDocument;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HumanResourcesModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $administration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            HrReferenceSeeder::class,
        ]);

        $this->administration = $this->userWithRole('ADMINISTRATION');
    }

    public function test_hr_routes_are_permission_protected_and_administration_can_open_each_workspace(): void
    {
        $reception = $this->userWithRole('RECEPTION');
        $routes = [
            '/administration/employees/import',
            '/administration/contracts',
            '/administration/attendance',
            '/administration/leave',
            '/administration/planning',
            '/administration/reports',
            '/administration/settings',
        ];

        foreach ($routes as $route) {
            $this->actingAs($this->administration)->get($route)->assertOk();
            $this->actingAs($reception)->get($route)->assertForbidden();
        }
    }

    public function test_contract_uses_uuid_validates_dates_and_is_soft_deleted_restored_and_audited(): void
    {
        $employee = $this->employee();
        $type = $this->reference(HrReferenceType::ContractType, 'CDI');

        $this->actingAs($this->administration)->post('/administration/contracts', [
            'employee_uuid' => $employee->uuid,
            'contract_type_uuid' => $type->uuid,
            'reference_number' => 'CTR-TEST-001',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-07-31',
        ])->assertSessionHasErrors('ends_on');

        $this->actingAs($this->administration)->post('/administration/contracts', [
            'employee_uuid' => $employee->uuid,
            'contract_type_uuid' => $type->uuid,
            'reference_number' => 'CTR-TEST-001',
            'signed_on' => '2026-07-25',
            'starts_on' => '2026-08-01',
            'ends_on' => null,
            'trial_ends_on' => null,
            'observation' => 'Contrat administratif de test',
        ])->assertSessionHasNoErrors();

        $contract = EmploymentContract::query()->where('reference_number', 'CTR-TEST-001')->firstOrFail();
        $this->assertNotNull($contract->uuid);
        $this->actingAs($this->administration)
            ->get("/administration/contracts/{$contract->id}/edit")
            ->assertNotFound();
        $this->actingAs($this->administration)
            ->get("/administration/contracts/{$contract->uuid}/edit")
            ->assertOk();
        $this->assertAudit($contract, 'create');

        $this->actingAs($this->administration)->delete("/administration/contracts/{$contract->uuid}", [
            'reason' => 'Archivage contractuel contrôlé',
        ])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($contract);
        $this->assertAudit($contract, 'delete', 'Archivage contractuel contrôlé');

        $this->actingAs($this->administration)
            ->post("/administration/contracts/{$contract->uuid}/restore")
            ->assertSessionHasNoErrors();
        $this->assertFalse($contract->fresh()->trashed());
        $this->assertAudit($contract, 'restore');

        $this->expectException(ForceDeleteForbiddenException::class);
        $contract->fresh()->forceDelete();
    }

    public function test_leave_preview_uses_configured_weekdays_and_server_owned_balance(): void
    {
        $employee = $this->employee();
        $type = $this->reference(HrReferenceType::LeaveType, 'Congé annuel');
        $type->update(['metadata' => [
            ...$type->metadata,
            'day_count_method' => 'WEEKDAYS_INCLUSIVE',
            'annual_quota_days' => 30,
        ]]);

        $this->actingAs($this->administration)->postJson('/administration/leave/preview', [
            'employee_uuid' => $employee->uuid,
            'leave_type_uuid' => $type->uuid,
            'starts_on' => '2026-09-04',
            'returns_on' => '2026-09-07',
        ])->assertOk()
            ->assertJsonPath('preview.days_requested', '2.00')
            ->assertJsonPath('preview.balance_before', '30.00')
            ->assertJsonPath('preview.projected_balance', '28.00')
            ->assertJsonPath('preview.day_count_method', 'WEEKDAYS_INCLUSIVE');
    }

    public function test_leave_approval_is_blocked_when_the_firm_annual_balance_is_insufficient(): void
    {
        $employee = $this->employee();
        $type = $this->reference(HrReferenceType::LeaveType, 'Congé annuel');
        LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'reason' => 'Congé déjà consommé',
            'requested_on' => '2026-01-01',
            'starts_on' => '2026-01-01',
            'returns_on' => '2026-01-28',
            'days_requested' => 28,
            'consumes_balance_snapshot' => true,
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->actingAs($this->administration)
            ->post('/administration/leave', $this->leavePayload($employee))
            ->assertSessionHasNoErrors();
        $pending = LeaveRequest::query()->where('status', LeaveRequestStatus::Pending)->firstOrFail();

        $this->actingAs($this->administration)
            ->post("/administration/leave/{$pending->uuid}/approve", ['reason' => 'À contrôler'])
            ->assertSessionHasErrors('leave');

        $this->assertSame(LeaveRequestStatus::Pending, $pending->fresh()->status);
    }

    public function test_attendance_derives_work_date_validates_the_session_and_audits_changes(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->administration)->post('/administration/attendance', [
            'employee_uuid' => $employee->uuid,
            'started_at' => '2026-08-29 08:00:00',
            'ended_at' => '2026-08-29 07:59:00',
        ])->assertSessionHasErrors('ended_at');

        $this->actingAs($this->administration)->post('/administration/attendance', [
            'employee_uuid' => $employee->uuid,
            'started_at' => '2026-08-29 08:00:00',
            'ended_at' => '2026-08-29 16:30:00',
            'observation' => 'Horaires constatés',
        ])->assertSessionHasNoErrors();

        $record = AttendanceRecord::query()->firstOrFail();
        $this->assertNotNull($record->uuid);
        $this->assertSame('2026-08-29', $record->work_date->toDateString());
        $this->assertAudit($record, 'create');

        $this->actingAs($this->administration)->put("/administration/attendance/{$record->uuid}", [
            'employee_uuid' => $employee->uuid,
            'started_at' => '2026-08-30 09:00:00',
            'ended_at' => null,
            'observation' => 'Session encore ouverte',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-08-30', $record->fresh()->work_date->toDateString());
        $this->assertAudit($record, 'update');
    }

    public function test_attendance_employee_filter_is_kept_in_the_list_summary_and_print_view(): void
    {
        $selected = $this->employee();
        $other = $this->employee();

        AttendanceRecord::query()->create([
            'employee_id' => $selected->id,
            'work_date' => '2026-08-29',
            'started_at' => '2026-08-29 08:00:00',
            'ended_at' => '2026-08-29 16:00:00',
        ]);
        AttendanceRecord::query()->create([
            'employee_id' => $other->id,
            'work_date' => '2026-08-29',
            'started_at' => '2026-08-29 09:00:00',
            'ended_at' => null,
        ]);

        $query = "from=2026-08-01&to=2026-08-31&employee={$selected->uuid}";

        $this->actingAs($this->administration)
            ->get("/administration/attendance?{$query}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Attendance/Index')
                ->has('records.data', 1)
                ->where('records.data.0.employee.uuid', $selected->uuid)
                ->where('summary.sessions', 1)
                ->where('summary.employees', 1)
                ->where('summary.open', 0));

        $this->actingAs($this->administration)
            ->get("/administration/attendance/print?{$query}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Attendance/Print')
                ->has('records', 1)
                ->where('records.0.employee.uuid', $selected->uuid));
    }

    public function test_leave_validates_interim_and_dates_then_approves_once_with_an_explicit_audit(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->administration)->post('/administration/leave', [
            ...$this->leavePayload($employee),
            'interim_employee_uuid' => $employee->uuid,
            'returns_on' => '2026-08-31',
        ])->assertSessionHasErrors(['interim_employee_uuid', 'returns_on']);

        $this->actingAs($this->administration)
            ->post('/administration/leave', $this->leavePayload($employee))
            ->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();
        $this->assertNotNull($leave->uuid);
        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
        $this->assertSame(now()->toDateString(), $leave->requested_on->toDateString());
        $this->assertSame('5.00', $leave->days_requested);
        $this->assertSame('30.00', $leave->remaining_days_snapshot);
        $this->assertSame('25.00', $leave->projected_remaining_days_snapshot);
        $this->assertAudit($leave, 'create');

        $leave->leaveType->update(['metadata' => [
            ...$leave->leaveType->metadata,
            'annual_quota_days' => 10,
            'day_count_method' => 'WEEKDAYS_INCLUSIVE',
        ]]);

        $this->actingAs($this->administration)->post("/administration/leave/{$leave->uuid}/approve", [
            'reason' => 'Demande administrativement acceptée',
        ])->assertSessionHasNoErrors();

        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
        $this->assertSame('5.00', $leave->fresh()->days_requested);
        $this->assertSame('30.00', $leave->fresh()->annual_quota_snapshot);
        $this->assertSame('25.00', $leave->fresh()->remaining_days_snapshot);
        $this->assertAudit($leave, 'approve', 'Demande administrativement acceptée');

        $this->expectException(InvalidLeaveRequestTransitionException::class);
        $leave->fresh()->decide(LeaveRequestStatus::Rejected, 'Seconde décision interdite');
    }

    public function test_rejection_requires_a_reason_and_cancellation_is_audited_without_deleting_the_leave(): void
    {
        $employee = $this->employee();
        $leave = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'reason' => 'Demande administrative',
            'requested_on' => '2026-08-29',
            'starts_on' => '2026-09-10',
            'returns_on' => '2026-09-15',
        ]);

        $this->actingAs($this->administration)
            ->post("/administration/leave/{$leave->uuid}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->administration)
            ->post("/administration/leave/{$leave->uuid}/cancel", ['reason' => 'Demande retirée avant décision'])
            ->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(LeaveRequestStatus::Cancelled, $leave->status);
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id]);
        $this->assertAudit($leave, 'cancel', 'Demande retirée avant décision');
    }

    public function test_planning_uses_uuid_validates_chronology_and_is_reported_without_payroll_data(): void
    {
        $employee = $this->employee();
        $department = $this->reference(HrReferenceType::Department, 'Administration');

        $this->actingAs($this->administration)->post('/administration/planning', [
            'employee_uuid' => $employee->uuid,
            'department_uuid' => $department->uuid,
            'starts_at' => '2026-08-29 12:00:00',
            'ends_at' => '2026-08-29 11:00:00',
        ])->assertSessionHasErrors('ends_at');

        $this->actingAs($this->administration)->post('/administration/planning', [
            'employee_uuid' => $employee->uuid,
            'department_uuid' => $department->uuid,
            'title' => 'Permanence administrative',
            'starts_at' => '2026-08-29 08:00:00',
            'ends_at' => '2026-08-29 12:00:00',
            'observation' => null,
        ])->assertSessionHasNoErrors();

        $shift = PlanningShift::query()->firstOrFail();
        $this->assertNotNull($shift->uuid);
        $this->assertSame($department->id, $shift->department_id);
        $this->assertAudit($shift, 'create');

        $this->actingAs($this->administration)
            ->get('/administration/reports?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Reports/Index')
                ->where('report.summary.planning_shifts', 1)
                ->missing('report.payroll'));
    }

    public function test_attestation_type_and_private_document_are_uuid_addressed_validated_soft_deleted_and_audited(): void
    {
        Storage::fake('local');
        $employee = $this->employee();

        $this->actingAs($this->administration)->post('/administration/settings', [
            'type' => HrReferenceType::AttestationType->value,
            'code' => 'ATTESTATION_TEST',
            'label' => 'Attestation validée pour test',
            'active' => true,
            'position' => 1,
        ])->assertSessionHasNoErrors();
        $type = HrReferenceValue::query()->where('code', 'ATTESTATION_TEST')->firstOrFail();
        $this->assertNotNull($type->uuid);
        $this->assertAudit($type, 'create');

        $this->actingAs($this->administration)->post('/administration/documents', [
            'employee_uuid' => $employee->uuid,
            'category' => 'ATTESTATION',
            'title' => 'Document administratif contrôlé',
            'file' => UploadedFile::fake()->create('attestation.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('attestation_type_uuid');

        $this->actingAs($this->administration)->post('/administration/documents', [
            'employee_uuid' => $employee->uuid,
            'attestation_type_uuid' => $type->uuid,
            'category' => 'ATTESTATION',
            'title' => 'Document administratif contrôlé',
            'issued_on' => '2026-08-29',
            'notes' => 'Pièce privée RH',
            'file' => UploadedFile::fake()->create('attestation.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = HrDocument::query()->firstOrFail();
        $this->assertNotNull($document->uuid);
        Storage::disk('local')->assertExists($document->path);
        $this->assertAudit($document, 'create');

        $this->actingAs($this->administration)
            ->get("/administration/documents/{$document->id}/download")
            ->assertNotFound();
        $this->actingAs($this->administration)
            ->get("/administration/documents/{$document->uuid}/download")
            ->assertOk();

        $this->actingAs($this->administration)->delete("/administration/documents/{$document->uuid}", [
            'reason' => 'Classement en archive privée',
        ])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($document);
        Storage::disk('local')->assertExists($document->path);
        $this->assertAudit($document, 'delete', 'Classement en archive privée');

        $this->actingAs($this->administration)
            ->post("/administration/documents/{$document->uuid}/restore")
            ->assertSessionHasNoErrors();
        $this->assertFalse($document->fresh()->trashed());
        $this->assertTrue($type->isForceDeleteProtected());
    }

    public function test_employee_csv_import_is_atomic_uses_configured_references_and_rejects_unknown_status(): void
    {
        $csv = implode("\n", [
            'MATRICULE,NOM,PRENOMS,GENRE,DATE ENTREE,FONCTION,DEPARTEMENT,TYPE CONTRAT,STATUS',
            'RH-IMPORT-001,Rabe,Soa,Femme,01/08/2026,Admin,Administration,CDI,Actif',
        ]);

        $this->actingAs($this->administration)->post('/administration/employees/import', [
            'file' => UploadedFile::fake()->createWithContent('personnel.csv', $csv),
        ])->assertSessionHasNoErrors();

        $employee = Employee::query()->where('employee_number', 'RH-IMPORT-001')->firstOrFail();
        $this->assertTrue($employee->active);
        $this->assertNotNull($employee->uuid);
        $this->assertSame('Administration', $employee->department->label);
        $this->assertSame('Admin', $employee->jobTitle->label);
        $this->assertSame('MRS', $employee->civility->value);
        $this->assertCount(1, $employee->contracts);

        $invalidCsv = implode("\n", [
            'MATRICULE,NOM,GENRE,STATUS',
            'RH-IMPORT-002,Rakoto,Homme,Actif',
            'RH-IMPORT-003,Rasoa,Femme,STATUT-INCONNU',
        ]);
        $before = Employee::query()->count();

        $this->actingAs($this->administration)->post('/administration/employees/import', [
            'file' => UploadedFile::fake()->createWithContent('personnel.csv', $invalidCsv),
        ])->assertSessionHasErrors('file');

        $this->assertSame($before, Employee::query()->count());
        $this->assertDatabaseMissing('employees', ['employee_number' => 'RH-IMPORT-002']);
    }

    public function test_authorized_exports_templates_and_print_views_are_operational(): void
    {
        foreach ([
            '/administration/employees/export',
            '/administration/employees/import-template',
            '/administration/contracts/export',
            '/administration/attendance/export?from=2026-08-01&to=2026-08-31',
            '/administration/planning/export?from=2026-08-01&to=2026-08-31',
            '/administration/reports/export?from=2026-08-01&to=2026-08-31',
        ] as $route) {
            $this->actingAs($this->administration)->get($route)->assertOk();
        }

        $this->actingAs($this->administration)
            ->get('/administration/attendance/print?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Attendance/Print'));
        $this->actingAs($this->administration)
            ->get('/administration/planning/print?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Planning/Print'));
        $this->actingAs($this->administration)
            ->get('/administration/reports/print?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Reports/Print'));
    }

    private function userWithRole(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'RH-EMP-'.str()->random(8),
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'birth_date' => '1990-01-15',
            'active' => true,
        ]);
    }

    private function reference(HrReferenceType $type, string $label): HrReferenceValue
    {
        return HrReferenceValue::query()->where('type', $type->value)->where('label', $label)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function leavePayload(Employee $employee): array
    {
        return [
            'employee_uuid' => $employee->uuid,
            'interim_employee_uuid' => null,
            'leave_type_uuid' => $this->reference(HrReferenceType::LeaveType, 'Congé annuel')->uuid,
            'leave_address' => 'Adresse déclarée',
            'emergency_phone' => '0320000000',
            'reason' => 'Demande administrative de test',
            'starts_on' => '2026-09-01',
            'returns_on' => '2026-09-05',
        ];
    }

    private function assertAudit(object $entity, string $action, ?string $reason = null): void
    {
        $query = AuditLog::query()
            ->where('user_id', $this->administration->id)
            ->where('entity_type', $entity::class)
            ->where('entity_id', $entity->id)
            ->where('entity_uuid', $entity->uuid)
            ->where('action', $action);

        if ($reason !== null) {
            $query->where('reason', $reason);
        }

        $this->assertTrue($query->exists(), 'Audit '.$action.' missing for '.$entity::class.'.');
    }
}
