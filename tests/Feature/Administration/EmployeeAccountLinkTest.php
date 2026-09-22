<?php

namespace Tests\Feature\Administration;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-168 — une fiche Employé se relie au compte qui se connecte, pour que son
 * planning RH dise quand la personne est disponible. Un compte, une fiche.
 */
class EmployeeAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
    }

    private function userWithRole(string $roleCode, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'employee_number' => 'RH-BLOC-001',
            'last_name' => 'Rabe',
            'first_name' => 'Hery',
            'sex' => 'M',
            'address_entry_uuid' => null,
            'new_address_label' => null,
            'active' => true,
            ...$overrides,
        ];
    }

    public function test_hr_links_an_account_and_can_unlink_it(): void
    {
        $hr = $this->userWithRole('ADMINISTRATION');
        $surgeon = $this->userWithRole('SURGERY');

        $this->actingAs($hr)
            ->post('/administration/employees', $this->payload(['user_uuid' => $surgeon->uuid]))
            ->assertSessionHasNoErrors();

        $employee = Employee::query()->where('employee_number', 'RH-BLOC-001')->firstOrFail();
        $this->assertSame($surgeon->id, (int) $employee->user_id);

        $this->actingAs($hr)
            ->get("/administration/employees/{$employee->uuid}")
            ->assertInertia(fn ($page) => $page->where('employee.user_account.name', $surgeon->name));

        $this->actingAs($hr)
            ->put("/administration/employees/{$employee->uuid}", $this->payload(['user_uuid' => null]))
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_an_account_cannot_be_linked_to_two_records_even_an_archived_one(): void
    {
        $hr = $this->userWithRole('ADMINISTRATION');
        $surgeon = $this->userWithRole('SURGERY');
        $archived = Employee::query()->create([
            'employee_number' => 'RH-OLD', 'last_name' => 'Ancien', 'sex' => 'M', 'active' => true, 'user_id' => $surgeon->id,
        ]);
        $archived->delete();

        $this->actingAs($hr)
            ->post('/administration/employees', $this->payload(['user_uuid' => $surgeon->uuid]))
            ->assertSessionHasErrors(['user_uuid' => "Le compte « {$surgeon->name} » est déjà relié à la fiche RH-OLD (archivée)."]);
    }

    public function test_a_new_link_needs_an_active_account_but_an_existing_link_survives_deactivation(): void
    {
        $hr = $this->userWithRole('ADMINISTRATION');
        $inactive = $this->userWithRole('SURGERY', ['active' => false, 'deactivated_at' => now()]);

        $this->actingAs($hr)
            ->post('/administration/employees', $this->payload(['user_uuid' => $inactive->uuid]))
            ->assertSessionHasErrors('user_uuid');

        $surgeon = $this->userWithRole('SURGERY');
        $employee = Employee::query()->create([
            'employee_number' => 'RH-BLOC-002', 'last_name' => 'Rabe', 'sex' => 'M', 'active' => true, 'user_id' => $surgeon->id,
        ]);
        $surgeon->forceFill(['active' => false, 'deactivated_at' => now()])->save();

        $this->actingAs($hr)
            ->put("/administration/employees/{$employee->uuid}", $this->payload(['employee_number' => 'RH-BLOC-002', 'user_uuid' => $surgeon->uuid]))
            ->assertSessionHasNoErrors();
        $this->assertSame($surgeon->id, (int) $employee->fresh()->user_id);
    }

    public function test_the_form_offers_only_accounts_free_to_link(): void
    {
        $hr = $this->userWithRole('ADMINISTRATION');
        $free = $this->userWithRole('SURGERY');
        $taken = $this->userWithRole('SURGERY');
        $deactivated = $this->userWithRole('SURGERY', ['active' => false, 'deactivated_at' => now()]);
        $superAdmin = $this->userWithRole('SUPER_ADMIN');
        Employee::query()->create([
            'employee_number' => 'RH-TAKEN', 'last_name' => 'Pris', 'sex' => 'F', 'active' => true, 'user_id' => $taken->id,
        ]);

        $this->actingAs($hr)
            ->get('/administration/employees/create')
            ->assertInertia(function ($page) use ($free, $taken, $deactivated, $superAdmin) {
                $uuids = collect($page->toArray()['props']['accounts'])->pluck('uuid');
                $this->assertTrue($uuids->contains($free->uuid));
                $this->assertFalse($uuids->contains($taken->uuid));
                $this->assertFalse($uuids->contains($deactivated->uuid));
                $this->assertFalse($uuids->contains($superAdmin->uuid));
            });
    }
}
