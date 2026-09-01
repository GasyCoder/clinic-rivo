<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Surgery\CreateAnesthesiaRecordAction;
use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Actions\Surgery\CreateSurgicalReportAction;
use App\Actions\Surgery\DischargeSurgicalRequestAction;
use App\Actions\Surgery\ScheduleSurgicalRequestAction;
use App\Actions\Surgery\UpdateSurgicalRequestAction;
use App\Actions\Surgery\ValidateAnesthesiaRecordAction;
use App\Actions\Surgery\ValidatePreoperativeAssessmentAction;
use App\Actions\Surgery\ValidateSurgicalReportAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\CareRecordProcedure;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SurgicalIntervention;
use App\Models\SurgicalRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaternityWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_nurse_profiles_keep_care_baseline_but_specialized_workspaces_require_individual_permissions(): void
    {
        $nurse = $this->profileUser('REGISTERED_NURSE', false);
        $midwifeWithoutOverrides = $this->profileUser('MIDWIFE', false);
        $midwife = $this->profileUser('MIDWIFE');
        $anesthetist = $this->profileUser('ANESTHETIST');

        $this->assertTrue($nurse->hasPermissionTo('care.view'));
        $this->assertFalse($nurse->hasPermissionTo('maternity.view'));
        $this->assertFalse($nurse->hasPermissionTo('anesthesia.view'));
        $this->actingAs($nurse)->get('/maternity')->assertForbidden();

        $this->assertTrue($midwifeWithoutOverrides->professionalProfile->recommendedPermissions()->where('name', 'maternity.view')->exists());
        $this->assertFalse($midwifeWithoutOverrides->hasPermissionTo('maternity.view'));
        $this->actingAs($midwifeWithoutOverrides)->get('/maternity')->assertForbidden();

        $this->assertTrue($midwife->hasPermissionTo('care.update'));
        $this->assertTrue($midwife->hasPermissionTo('maternity.view'));
        $this->actingAs($midwife)->get('/maternity')->assertOk();

        $this->assertTrue($anesthetist->hasPermissionTo('care.update'));
        $this->assertTrue($anesthetist->hasPermissionTo('anesthesia.view'));
        $this->assertFalse($anesthetist->hasPermissionTo('surgery.view'));

        $surgeryView = Permission::query()->where('name', 'surgery.view')->firstOrFail();
        $anesthetist->permissions()->syncWithoutDetaching([$surgeryView->id => ['effect' => 'allow']]);
        $this->assertTrue($anesthetist->fresh()->hasPermissionTo('surgery.view'));
        $this->assertFalse($anesthetist->fresh()->hasPermissionTo('surgery.intervention.create'));

        [$episode] = $this->maternityOrientation($anesthetist);
        $surgicalRequest = SurgicalRequest::query()->create([
            'episode_id' => $episode->id,
            'requested_by' => $anesthetist->id,
            'procedure_name' => 'Intervention de contrôle',
            'created_by' => $anesthetist->id,
        ]);

        $this->actingAs($anesthetist)
            ->post("/surgery/{$surgicalRequest->uuid}/intervention", [])
            ->assertForbidden();
        $this->assertSame(0, SurgicalIntervention::query()->count());
    }

    public function test_midwife_performs_care_act_as_midwife_on_the_shared_care_workspace(): void
    {
        $midwife = $this->profileUser('MIDWIFE');
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Fara', 'last_name' => 'Rabe', 'birth_date' => '1994-02-20', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $midwife);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Medicine, CatalogModule::Care, $midwife, 'Soins prescrits',
        );
        $procedure = $this->catalogItem($midwife, 'CARE-MIDWIFE-TEST', 'Soin obstétrical', CatalogModule::Care);

        $this->actingAs($midwife)
            ->post("/care/orientations/{$orientation->uuid}/accept")
            ->assertRedirect();
        $this->actingAs($midwife)->put("/care/orientations/{$orientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => 1,
                'notes' => 'Acte réalisé dans l’espace Soins commun.',
            ]],
        ])->assertRedirect();

        $performed = CareRecordProcedure::query()->sole();
        $this->assertSame($midwife->id, $performed->performed_by);
        $this->assertSame('MIDWIFE', $midwife->fresh()->professionalProfile->code);
    }

    public function test_midwife_records_maternity_file_and_procedure_without_changing_profession(): void
    {
        $midwife = $this->profileUser('MIDWIFE');
        [$episode, $orientation] = $this->maternityOrientation($midwife);
        $procedure = $this->catalogItem($midwife, 'MAT-DOPPLER', 'Doppler', CatalogModule::Maternity);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept")->assertRedirect();
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'obstetric_context' => 'Grossesse suivie, contractions régulières.',
            'pregnancy_data' => ['gravidity' => 2, 'parity' => 1, 'estimated_due_date' => '2026-09-10'],
            'prenatal_data' => ['gestational_age_weeks' => 39, 'fetal_heart_rate' => 142, 'notes' => 'Présentation céphalique.'],
            'labor_data' => ['membranes_status' => 'INTACT', 'cervical_dilation_cm' => 4, 'surveillance_notes' => 'Surveillance régulière.'],
            'newborn_data' => ['newborns' => [['sex' => 'F', 'birth_weight_g' => 3100, 'condition' => 'Bon', 'apgar' => 9]]],
            'observations' => 'Évolution favorable.',
        ])->assertRedirect();
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/procedures", [
            'catalog_item_uuid' => $procedure->uuid,
            'quantity' => 1,
            'notes' => 'Contrôle fœtal.',
        ])->assertRedirect();

        $record = MaternityRecord::query()->sole();
        $performed = MaternityProcedure::query()->sole();
        $this->assertSame($episode->id, $record->episode_id);
        $this->assertSame(39, $record->prenatal_data['gestational_age_weeks']);
        $this->assertSame($midwife->id, $performed->performed_by);
        $this->assertSame('MIDWIFE', $midwife->fresh()->professionalProfile->code);
    }

    public function test_cesarean_decision_creates_surgery_request_on_same_episode_without_intervention(): void
    {
        $midwife = $this->profileUser('MIDWIFE');
        [$episode, $orientation] = $this->maternityOrientation($midwife);
        $this->catalogItem($midwife, 'MAT-CESAREAN-TWIN', 'Opération Césarienne Gémellaire', CatalogModule::Maternity);
        $surgeryItem = $this->catalogItem($midwife, 'SURG-CESARIENNE', 'Opération césarienne', CatalogModule::Surgery);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept");
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/cesarean", [
            'type' => 'TWIN',
            'indication' => 'Souffrance fœtale et grossesse gémellaire.',
        ])->assertRedirect()->assertSessionHas('status');

        $request = SurgicalRequest::query()->sole();
        $this->assertSame($episode->id, $request->episode_id);
        $this->assertSame($surgeryItem->id, $request->catalog_item_id);
        $this->assertSame(1, Episode::query()->count());
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => 'MATERNITY',
            'destination_module' => 'SURGERY',
        ]);
        $this->assertSame(0, SurgicalIntervention::query()->count());

        $surgeon = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SURGERY')->value('id'),
        ]);
        $this->actingAs($surgeon);
        app(ScheduleSurgicalRequestAction::class)->execute($request, $surgeon, '2026-09-02 08:00:00');
        app(UpdateSurgicalRequestAction::class)->execute($request, ['preoperative_notes' => 'Bilan préopératoire validable.']);
        app(ValidatePreoperativeAssessmentAction::class)->execute($request, $surgeon);
        $anesthesia = app(CreateAnesthesiaRecordAction::class)->execute($request, ['notes' => 'Anesthésie standard.']);
        app(ValidateAnesthesiaRecordAction::class)->execute($anesthesia);
        app(CreateSurgicalInterventionAction::class)->execute($request, ['notes' => 'Césarienne réalisée au bloc.']);
        $report = app(CreateSurgicalReportAction::class)->execute($request, 'Intervention terminée sans incident.');
        app(ValidateSurgicalReportAction::class)->execute($report);
        app(DischargeSurgicalRequestAction::class)->execute($request->fresh(), $surgeon, 'Retour en surveillance Maternité.');

        $this->assertSame(SurgicalRequestStatus::Discharged, $request->fresh()->status);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
        $this->assertSame(1, Episode::query()->count());
        $this->actingAs($midwife)
            ->get("/maternity/orientations/{$orientation->uuid}")
            ->assertOk();
    }

    public function test_cesarean_catalog_item_cannot_be_recorded_as_maternity_procedure(): void
    {
        $midwife = $this->profileUser('MIDWIFE');
        [, $orientation] = $this->maternityOrientation($midwife);
        $cesarean = $this->catalogItem(
            $midwife,
            'MAT-CESAREAN-SIMPLE',
            'Opération Césarienne Simple',
            CatalogModule::Maternity,
        );

        $this->actingAs($midwife)
            ->post("/maternity/orientations/{$orientation->uuid}/accept")
            ->assertRedirect();
        $this->actingAs($midwife)
            ->post("/maternity/orientations/{$orientation->uuid}/procedures", [
                'catalog_item_uuid' => $cesarean->uuid,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('catalog_item_uuid');

        $this->assertSame(0, MaternityProcedure::query()->count());
        $this->assertSame(0, SurgicalRequest::query()->count());
        $this->assertSame(0, SurgicalIntervention::query()->count());
    }

    /** @return array{Episode, EpisodeOrientation} */
    private function maternityOrientation(User $actor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Soa', 'last_name' => 'Rasoa', 'birth_date' => '1996-05-12', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $actor);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Medicine, CatalogModule::Maternity, $actor, 'Suivi obstétrical',
        );

        return [$episode, $orientation];
    }

    private function profileUser(string $profileCode, bool $applyRecommendations = true): User
    {
        $profile = ProfessionalProfile::query()->where('code', $profileCode)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
            'professional_profile_id' => $profile->id,
        ]);
        if ($applyRecommendations) {
            $user->permissions()->syncWithoutDetaching($profile->recommendedPermissions->mapWithKeys(
                fn (Permission $permission) => [$permission->id => ['effect' => 'allow']],
            )->all());
        }

        return $user->fresh(['role', 'professionalProfile']);
    }

    private function catalogItem(User $actor, string $code, string $name, CatalogModule $module): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code, 'name' => $name, 'type' => CatalogItemType::Service,
            'module' => $module, 'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'reception_selectable' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }
}
