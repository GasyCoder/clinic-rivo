<?php

namespace Tests\Feature\Administration;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-183 (amende ADR-168) — un compte se relie à la fiche Employé de la
 * personne depuis « Utilisateurs », au moment de le créer ou de le modifier :
 * « Personnel clinique » choisit une fiche, « Externe » n'en a aucune. La fiche
 * Employé ne relie plus rien. Un compte, une fiche ; une fiche, un compte.
 */
class EmployeeAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
    }

    private function userWithRole(string $roleCode, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            ...$attributes,
        ]);
    }

    private function accountManager(): User
    {
        $manager = $this->userWithRole('ADMINISTRATION');
        $manager->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('name', ['users.view', 'users.create', 'users.update', 'roles.assign', 'employees.create'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['effect' => 'allow']])
                ->all(),
        );

        return $manager->fresh();
    }

    private function employee(string $number, array $attributes = []): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'last_name' => 'RABE',
            'first_name' => 'Hery',
            'sex' => 'M',
            'email' => strtolower($number).'@clinique.test',
            'active' => true,
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function account(array $overrides = []): array
    {
        return [
            'name' => 'Hery RABE',
            'email' => 'hery.rabe@clinique.test',
            'password' => 'Valid-password1!',
            'password_confirmation' => 'Valid-password1!',
            'role_id' => Role::query()->where('code', 'RECEPTION')->value('id'),
            ...$overrides,
        ];
    }

    public function test_a_clinic_staff_account_is_linked_to_its_employee_record_at_creation(): void
    {
        $employee = $this->employee('RH-001');

        $this->actingAs($this->accountManager())
            ->post('/administration/users', $this->account(['account_kind' => 'STAFF', 'employee_uuid' => $employee->uuid]))
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'hery.rabe@clinique.test')->firstOrFail();
        $this->assertSame($user->id, (int) $employee->fresh()->user_id);
        $this->assertTrue(AuditLog::query()->where('action', 'user.employee.link')->where('entity_id', $user->id)->exists());

        $this->actingAs($this->accountManager())
            ->get('/administration/users')
            ->assertInertia(function ($page): void {
                $row = collect($page->toArray()['props']['users']['data'])->firstWhere('email', 'hery.rabe@clinique.test');
                $this->assertSame('STAFF', $row['account_kind']);
                $this->assertSame('RH-001', $row['employee']['employee_number']);
            });
    }

    public function test_an_external_account_has_no_employee_record(): void
    {
        $this->employee('RH-001');

        $this->actingAs($this->accountManager())
            ->post('/administration/users', $this->account(['account_kind' => 'EXTERNAL']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Employee::query()->where('employee_number', 'RH-001')->value('user_id'));
    }

    public function test_the_choice_is_required_at_creation_and_staff_needs_a_record(): void
    {
        $manager = $this->accountManager();

        $this->actingAs($manager)
            ->post('/administration/users', $this->account())
            ->assertSessionHasErrors('account_kind');

        $this->actingAs($manager)
            ->post('/administration/users', $this->account(['account_kind' => 'STAFF']))
            ->assertSessionHasErrors(['employee_uuid' => 'Choisissez la fiche employé de cette personne.']);

        $this->assertFalse(User::query()->where('email', 'hery.rabe@clinique.test')->exists());
    }

    public function test_a_record_carries_one_account_and_must_be_in_post(): void
    {
        $manager = $this->accountManager();
        $holder = $this->userWithRole('SURGERY', ['name' => 'Dr Andry']);
        $taken = $this->employee('RH-TAKEN', ['user_id' => $holder->id]);
        $inactive = $this->employee('RH-OFF', ['active' => false]);
        $archived = $this->employee('RH-OLD');
        $archived->delete();

        $this->actingAs($manager)
            ->post('/administration/users', $this->account(['account_kind' => 'STAFF', 'employee_uuid' => $taken->uuid]))
            ->assertSessionHasErrors(['employee_uuid' => 'La fiche RH-TAKEN est déjà reliée au compte « Dr Andry ».']);
        $this->actingAs($manager)
            ->post('/administration/users', $this->account(['account_kind' => 'STAFF', 'employee_uuid' => $inactive->uuid]))
            ->assertSessionHasErrors('employee_uuid');
        $this->actingAs($manager)
            ->post('/administration/users', $this->account(['account_kind' => 'STAFF', 'employee_uuid' => $archived->uuid]))
            ->assertSessionHasErrors('employee_uuid');

        $this->assertFalse(User::query()->where('email', 'hery.rabe@clinique.test')->exists(), 'un refus n’écrit pas de compte à moitié');
    }

    public function test_changing_the_choice_moves_or_removes_the_link_and_omitting_it_keeps_it(): void
    {
        $manager = $this->accountManager();
        $first = $this->employee('RH-001');
        $second = $this->employee('RH-002');
        $user = $this->userWithRole('RECEPTION', ['email' => 'hery.rabe@clinique.test']);
        $first->forceFill(['user_id' => $user->id])->save();
        $payload = $this->account(['role_id' => $user->role_id, 'password' => null, 'password_confirmation' => null]);

        // Corriger le nom seul ne délie rien (ADR-074).
        $this->actingAs($manager)->put("/administration/users/{$user->uuid}", [...$payload, 'name' => 'Hery Rabe'])->assertSessionHasNoErrors();
        $this->assertSame($user->id, (int) $first->fresh()->user_id);

        $this->actingAs($manager)->put("/administration/users/{$user->uuid}", [...$payload, 'account_kind' => 'STAFF', 'employee_uuid' => $second->uuid])->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->user_id);
        $this->assertSame($user->id, (int) $second->fresh()->user_id);

        $this->actingAs($manager)->put("/administration/users/{$user->uuid}", [...$payload, 'account_kind' => 'EXTERNAL'])->assertSessionHasNoErrors();
        $this->assertNull($second->fresh()->user_id);
        $this->assertTrue(AuditLog::query()->where('action', 'user.employee.unlink')->where('entity_id', $user->id)->exists());
    }

    public function test_the_employee_record_no_longer_links_an_account(): void
    {
        $hr = $this->userWithRole('ADMINISTRATION');
        $surgeon = $this->userWithRole('SURGERY');

        $this->actingAs($hr)
            ->post('/administration/employees', [
                'employee_number' => 'RH-BLOC-001', 'last_name' => 'Rabe', 'sex' => 'M', 'active' => true,
                'user_uuid' => $surgeon->uuid,
            ])
            ->assertSessionHasErrors('user_uuid');

        $this->actingAs($hr)
            ->get('/administration/employees/create')
            ->assertInertia(fn ($page) => $page->missing('accounts'));
    }

    public function test_the_site_api_serves_linkable_records_and_links_from_the_portal(): void
    {
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $employee = $this->employee('RH-001');
        $headers = fn (array $permissions, bool $write = false) => array_filter([
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => $write ? (string) Str::uuid() : null,
        ]);

        $this->withHeaders($headers(['users.view', 'users.create']))
            ->getJson('/api/v1/super-admin/users')
            ->assertOk()
            ->assertJsonPath('data.employees.0.employee_number', 'RH-001')
            ->assertJsonPath('data.employees.0.account', null);

        $this->withHeaders($headers(['users.create', 'roles.assign'], true))
            ->postJson('/api/v1/super-admin/users', $this->account(['account_kind' => 'STAFF', 'employee_uuid' => $employee->uuid]))
            ->assertCreated()
            ->assertJsonPath('data.account_kind', 'STAFF')
            ->assertJsonPath('data.employee.employee_number', 'RH-001');

        $this->assertNotNull($employee->fresh()->user_id);
    }
}
