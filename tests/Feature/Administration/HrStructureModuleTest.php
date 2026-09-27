<?php

namespace Tests\Feature\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-188 — Départements et Fonctions, chacun son module, sur le même
 * référentiel et avec les mêmes droits que les Paramètres RH.
 */
class HrStructureModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->hr = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_the_departments_module_lists_only_departments_with_their_headcount(): void
    {
        $medicine = HrReferenceValue::query()->create(['type' => HrReferenceType::Department->value, 'label' => 'Médecine', 'active' => true]);
        HrReferenceValue::query()->create(['type' => HrReferenceType::JobTitle->value, 'label' => 'Infirmier', 'active' => true]);
        Employee::query()->create(['employee_number' => 'E-1', 'last_name' => 'A', 'sex' => 'M', 'active' => true, 'department_id' => $medicine->id]);
        Employee::query()->create(['employee_number' => 'E-2', 'last_name' => 'B', 'sex' => 'F', 'active' => false, 'department_id' => $medicine->id]);

        $this->actingAs($this->hr)
            ->get('/administration/departments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Administration/HrStructure/Index')
                ->where('kind', 'departments')
                ->has('items', 1)
                ->where('items.0.label', 'Médecine')
                ->where('items.0.employees_count', 2)
                ->where('items.0.active_employees_count', 1));
    }

    public function test_a_function_is_created_with_a_generated_code_and_the_type_comes_from_the_address(): void
    {
        $this->actingAs($this->hr)
            ->post('/administration/job-titles', ['label' => 'Sage-femme', 'type' => HrReferenceType::Department->value])
            ->assertSessionHasNoErrors();

        // « Sage-femme » est aussi une filière de stage livrée (ADR-194) : on lit la valeur créée.
        $created = HrReferenceValue::query()->where('label', 'Sage-femme')->latest('id')->firstOrFail();
        $this->assertSame(HrReferenceType::JobTitle, $created->type, 'le type ne vient jamais du navigateur');
        $this->assertSame('SAGE_FEMME', $created->code);

        $this->actingAs($this->hr)
            ->post('/administration/job-titles', ['label' => 'Sage-femme'])
            ->assertSessionHasErrors('label');
    }

    public function test_a_value_is_renamed_archived_with_a_reason_and_restored_only_through_its_own_module(): void
    {
        $department = HrReferenceValue::query()->create(['type' => HrReferenceType::Department->value, 'label' => 'Accueil', 'active' => true]);

        $this->actingAs($this->hr)->put("/administration/job-titles/{$department->uuid}", ['label' => 'Réception'])->assertNotFound();

        $this->actingAs($this->hr)->put("/administration/departments/{$department->uuid}", ['label' => 'Réception', 'code' => 'RECEPTION'])->assertSessionHasNoErrors();
        $this->assertSame('Réception', $department->fresh()->label);

        $this->actingAs($this->hr)->delete("/administration/departments/{$department->uuid}", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->hr)->delete("/administration/departments/{$department->uuid}", ['reason' => 'Service fermé'])->assertSessionHasNoErrors();
        $this->assertTrue($department->fresh()->trashed());

        $this->actingAs($this->hr)->post("/administration/departments/{$department->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertFalse($department->fresh()->trashed());
    }

    public function test_the_modules_keep_the_hr_settings_rights(): void
    {
        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);

        $this->actingAs($reception)->get('/administration/departments')->assertForbidden();
        $this->actingAs($reception)->post('/administration/job-titles', ['label' => 'Caissier'])->assertForbidden();
    }

    public function test_hr_settings_no_longer_list_departments_and_functions(): void
    {
        $this->actingAs($this->hr)
            ->get('/administration/settings')
            ->assertInertia(fn ($page) => $page
                ->where('types', fn ($types) => collect($types)->pluck('value')->intersect(['DEPARTMENT', 'JOB_TITLE'])->isEmpty()));
    }
}
