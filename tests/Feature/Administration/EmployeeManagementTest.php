<?php

namespace Tests\Feature\Administration;

use App\Exceptions\ForceDeleteForbiddenException;
use App\Models\AddressEntry;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\PatientStaffLink;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            HrReferenceSeeder::class,
        ]);
    }

    public function test_administration_can_open_the_employee_directory_but_reception_cannot(): void
    {
        $administration = $this->userWithRole('ADMINISTRATION');
        $reception = $this->userWithRole('RECEPTION');

        $this->actingAs($administration)
            ->get('/administration/employees')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Administration/Employees/Index')
                ->has('employees.data'));

        $this->actingAs($administration)
            ->get('/administration/employees/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Administration/Employees/Create')
                ->has('addresses')
                ->has('options.sexes', 2));

        $this->actingAs($administration)
            ->get('/administration/employees/import')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Administration/Employees/Import')
                ->has('columns', 24)
                ->where('limits.rows', 1000)
                ->where('limits.megabytes', 5)
                ->has('referenceValues.departments'));

        $this->actingAs($reception)
            ->get('/administration/employees')
            ->assertForbidden();
    }

    public function test_authorized_user_can_create_an_employee_with_a_referenced_address_and_audit(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $address = AddressEntry::query()->create(['label' => 'Adresse RH validée', 'active' => true]);

        $response = $this->actingAs($actor)
            ->post('/administration/employees', [
                ...$this->validPayload(),
                'address_entry_uuid' => $address->uuid,
            ])
            ->assertSessionHasNoErrors();

        $employee = Employee::query()->where('employee_number', 'EMP-RH-001')->firstOrFail();
        $response->assertRedirect("/administration/employees/{$employee->uuid}");

        $this->assertNotNull($employee->uuid);
        $this->assertSame($address->id, $employee->address_entry_id);
        $this->assertSame('Adresse RH validée', $employee->address);
        $this->assertSame('MRS', $employee->civility->value);
        $this->assertTrue($employee->active);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'action' => 'create',
            'module' => 'administration',
        ]);
    }

    public function test_employee_civility_is_always_derived_from_sex_and_cannot_be_spoofed(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');

        $this->actingAs($actor)->post('/administration/employees', [
            ...$this->validPayload(),
            'sex' => 'M',
            'civility' => 'MRS',
        ])->assertSessionHasNoErrors();

        $employee = Employee::query()->where('employee_number', 'EMP-RH-001')->firstOrFail();
        $this->assertSame('MR', $employee->civility->value);

        $this->actingAs($actor)->put("/administration/employees/{$employee->uuid}", [
            ...$this->validPayload(),
            'employee_number' => $employee->employee_number,
            'sex' => 'F',
            'civility' => 'BOY',
        ])->assertSessionHasNoErrors();

        $this->assertSame('MRS', $employee->fresh()->civility->value);
    }

    public function test_employee_validation_rejects_invalid_identity_and_archived_number_reuse(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $employee = $this->employee();
        $employee->delete_reason = 'Dossier administratif archivé';
        $employee->delete();

        $this->actingAs($actor)
            ->post('/administration/employees', [
                ...$this->validPayload(),
                'employee_number' => $employee->employee_number,
                'last_name' => '',
                'sex' => 'X',
                'birth_date' => now()->addDay()->toDateString(),
                'identity_document_type' => 'CIN',
                'identity_document_number' => '',
                'email' => 'adresse-invalide',
            ])
            ->assertSessionHasErrors([
                'employee_number',
                'last_name',
                'sex',
                'birth_date',
                'identity_document_number',
                'email',
            ]);

        $this->assertSame(1, Employee::withTrashed()->count());
    }

    public function test_updating_an_employee_synchronizes_the_linked_patient_identity_and_audits_both_records(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $employee = $this->employee();
        $patient = Patient::query()->create([
            'patient_number' => 'M-26-0901',
            'first_name' => 'Ancien prénom',
            'last_name' => 'Ancien nom',
            'birth_date' => '1988-03-10',
            'sex' => 'F',
        ]);
        PatientStaffLink::query()->create([
            'patient_id' => $patient->id,
            'employee_id' => $employee->id,
            'linked_by' => $actor->id,
            'linked_at' => now(),
        ]);
        $address = AddressEntry::query()->create(['label' => 'Nouvelle adresse RH', 'active' => true]);

        $this->actingAs($actor)
            ->put("/administration/employees/{$employee->uuid}", [
                ...$this->validPayload(),
                'employee_number' => $employee->employee_number,
                'first_name' => 'Miora',
                'last_name' => 'Rakoto',
                'birth_date' => '1991-04-12',
                'phone' => '0340000000',
                'address_entry_uuid' => $address->uuid,
            ])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $patient->refresh();

        $this->assertSame('Miora', $employee->first_name);
        $this->assertSame('Miora', $patient->first_name);
        $this->assertSame('Rakoto', $patient->last_name);
        $this->assertSame('1991-04-12', $patient->birth_date?->toDateString());
        $this->assertSame('0340000000', $patient->phone);
        $this->assertSame($address->id, $patient->address_entry_id);
        $this->assertFalse($patient->birth_date_is_approximate);

        $this->assertTrue(AuditLog::query()
            ->where('user_id', $actor->id)
            ->where('entity_type', Employee::class)
            ->where('entity_id', $employee->id)
            ->where('action', 'update')
            ->exists());
        $this->assertTrue(AuditLog::query()
            ->where('user_id', $actor->id)
            ->where('entity_type', Patient::class)
            ->where('entity_id', $patient->id)
            ->where('action', 'update')
            ->exists());
    }

    public function test_linked_employee_cannot_lose_the_birth_date_required_for_patient_synchronization(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $employee = $this->employee();
        $patient = Patient::query()->create([
            'patient_number' => 'M-26-0902',
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'birth_date' => '1988-03-10',
            'sex' => 'F',
        ]);
        PatientStaffLink::query()->create([
            'patient_id' => $patient->id,
            'employee_id' => $employee->id,
            'linked_by' => $actor->id,
            'linked_at' => now(),
        ]);

        $this->actingAs($actor)
            ->put("/administration/employees/{$employee->uuid}", [
                ...$this->validPayload(),
                'employee_number' => $employee->employee_number,
                'birth_date' => null,
            ])
            ->assertSessionHasErrors('birth_date');

        $this->assertSame('1990-01-15', $employee->fresh()->birth_date?->toDateString());
    }

    public function test_archive_and_restore_are_soft_deleted_motivated_and_audited(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $employee = $this->employee();

        $this->actingAs($actor)
            ->delete("/administration/employees/{$employee->uuid}", [
                'reason' => 'Fin du dossier administratif actif',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($employee);
        $archived = Employee::withTrashed()->findOrFail($employee->id);
        $this->assertSame($actor->id, $archived->deleted_by);
        $this->assertSame('Fin du dossier administratif actif', $archived->delete_reason);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'action' => 'delete',
            'reason' => 'Fin du dossier administratif actif',
        ]);

        $this->actingAs($actor)
            ->post("/administration/employees/{$employee->uuid}/restore")
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertFalse($employee->trashed());
        $this->assertNull($employee->delete_reason);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'action' => 'restore',
        ]);
    }

    public function test_unauthorized_role_cannot_create_update_archive_or_restore_an_employee(): void
    {
        $actor = $this->userWithRole('RECEPTION');
        $employee = $this->employee();

        $this->actingAs($actor)->post('/administration/employees', $this->validPayload())->assertForbidden();
        $this->actingAs($actor)->put("/administration/employees/{$employee->uuid}", $this->validPayload())->assertForbidden();
        $this->actingAs($actor)->delete("/administration/employees/{$employee->uuid}", ['reason' => 'Non autorisé'])->assertForbidden();

        $employee->delete_reason = 'Archive de contrôle';
        $employee->delete();

        $this->actingAs($actor)->post("/administration/employees/{$employee->uuid}/restore")->assertForbidden();
    }

    public function test_employee_with_patient_history_is_force_delete_protected_and_policy_never_authorizes_it(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $employee = $this->employee();
        $patient = Patient::query()->create([
            'patient_number' => 'M-26-0903',
            'first_name' => 'Tiana',
            'last_name' => 'Rajaona',
            'birth_date' => '1992-08-11',
            'sex' => 'M',
        ]);
        PatientStaffLink::query()->create([
            'patient_id' => $patient->id,
            'employee_id' => $employee->id,
            'linked_by' => $actor->id,
            'linked_at' => now(),
        ]);

        $this->assertFalse($actor->can('forceDelete', $employee));

        try {
            $employee->forceDelete();
            $this->fail('Expected ForceDeleteForbiddenException to be thrown.');
        } catch (ForceDeleteForbiddenException) {
            $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        }
    }

    private function userWithRole(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'employee_number' => 'EMP-RH-001',
            'civility' => 'MRS',
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'birth_date' => '1990-01-15',
            'identity_document_type' => 'CIN',
            'identity_document_number' => 'RH-IDENTITE-001',
            'marital_status' => 'MARRIED',
            'children_count' => 2,
            'profession' => 'Personnel administratif',
            'phone' => '0320000000',
            'email' => 'soa.rabe@clinic.test',
            'address_entry_uuid' => null,
            'new_address_label' => null,
            'active' => true,
        ];
    }

    private function employee(): Employee
    {
        return Employee::query()->create($this->validPayload());
    }
}
