<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CareRecord;
use App\Models\CareRecordProcedure;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareRecordFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_nurse_records_vitals_context_and_append_only_performed_procedures(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation($nurse);

        $response = $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'blood_group' => 'O+',
            'height_cm' => '175',
            'weight_kg' => '70',
            'allergy_note' => 'Pénicilline signalée',
            'smoker' => false,
            'hospitalization_reason' => 'Surveillance clinique',
            'hospitalized_at' => '2026-08-23 08:00:00',
            'discharged_at' => '2026-08-23 12:00:00',
            'diagnostic_note' => 'Diagnostic communiqué par le médecin',
            'transmission_reason' => 'Contrôler la température.',
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => '2',
                'notes' => 'Deux injections réalisées.',
            ]],
        ]);

        $response->assertRedirect(route('care.orientations.show', $orientation));

        $record = CareRecord::query()->sole();
        $this->assertSame('22.86', $record->bmi);
        $this->assertFalse($record->smoker);
        $this->assertSame($nurse->id, $record->created_by);
        $this->assertDatabaseHas('care_record_procedures', [
            'care_record_id' => $record->id,
            'procedure_code' => 'INJECTION-IM',
            'procedure_name' => 'Injection IM',
            'quantity' => 2,
            'performed_by' => $nurse->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'care',
            'user_id' => $nurse->id,
            'entity_type' => CareRecord::class,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'care',
            'user_id' => $nurse->id,
            'entity_type' => CareRecordProcedure::class,
        ]);

        $this->actingAs($nurse)->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->component('Care/Show')
                ->where('careRecord.blood_group', 'O+')
                ->where('careRecord.bmi', '22.86')
                ->where('careRecord.bmi_assessment.code', 'NORMAL')
                ->where('bmiReference.adult_min_age', 20)
                ->has('careRecord.procedures', 1)
                ->where('careRecord.procedures.0.name', 'Injection IM')
            );
    }

    public function test_updating_the_worksheet_preserves_previous_procedure_history(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation($nurse);
        $url = "/care/orientations/{$orientation->uuid}/record";

        $this->actingAs($nurse)->put($url, [
            'height_cm' => 160,
            'weight_kg' => 60,
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect();

        $this->actingAs($nurse)->put($url, [
            'height_cm' => 160,
            'weight_kg' => 62,
            'transmission_reason' => 'Nouvelle observation.',
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseCount('care_records', 1);
        $this->assertDatabaseCount('care_record_procedures', 2);
        $this->assertSame('24.22', CareRecord::query()->sole()->bmi);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'module' => 'care',
            'entity_type' => CareRecord::class,
        ]);
    }

    public function test_other_procedure_requires_a_description(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);
        $other = $this->procedure($nurse, 'CARE-OTHER', 'Autres', false);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $other->uuid,
                'quantity' => 1,
                'notes' => '',
            ]],
        ])->assertSessionHasErrors('procedures.0.notes');

        $this->assertDatabaseCount('care_records', 0);
        $this->assertDatabaseCount('care_record_procedures', 0);
    }

    public function test_record_cannot_be_written_before_care_is_accepted(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        $patient = $this->patient();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $this->app->make(PlanEpisodeRoutingAction::class)->planUnknownNeed($episode, $nurse);
        $orientation = $episode->orientations()->sole();

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'blood_group' => 'A+',
        ])->assertSessionHasErrors('care_record');

        $this->assertDatabaseCount('care_records', 0);
    }

    public function test_read_only_care_account_cannot_write_the_record_or_vitals(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);
        $viewer = $this->userWithPermissions(['care.view', 'vitals.view'], 'CARE_VIEWER');

        $this->actingAs($viewer)->get("/care/orientations/{$orientation->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_edit', false)
                ->where('capabilities.can_complete', false)
            );

        $this->actingAs($viewer)->put("/care/orientations/{$orientation->uuid}/record", [
            'blood_group' => 'AB+',
        ])->assertForbidden();
    }

    /** @return array{0: EpisodeOrientation, 1: CatalogItem} */
    private function activeCareOrientation(User $nurse): array
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $procedure = $this->procedure($nurse, 'INJECTION-IM', 'Injection IM');
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $procedure->uuid,
            'quantity' => 1,
        ]], $nurse);
        $orientation = $episode->orientations()->sole();
        $this->app->make(AcceptCareOrientationAction::class)->execute($orientation, $nurse);

        return [$orientation->fresh(), $procedure];
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    private function procedure(User $actor, string $code, string $name, bool $receptionSelectable = true): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'billable' => $code !== 'CARE-OTHER',
            'stockable' => false,
            'reception_selectable' => $receptionSelectable,
            'reception_routing_mode' => $receptionSelectable ? ReceptionRoutingMode::CareOnly : null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function userWithPermissions(array $permissions, string $roleCode = 'NURSE'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
