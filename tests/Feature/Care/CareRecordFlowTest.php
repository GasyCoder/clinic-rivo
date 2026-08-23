<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\AllergenCategory;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\AllergenReference;
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
            'patients.medical_history.view', 'patients.medical_history.manage',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation(
            $nurse,
            ReceptionRoutingMode::CareThenMedicine,
        );

        $response = $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'blood_group' => 'O+',
            'blood_pressure_left_systolic' => 122,
            'blood_pressure_left_diastolic' => 78,
            'blood_pressure_right_systolic' => 118,
            'blood_pressure_right_diastolic' => 76,
            'temperature_celsius' => '37.20',
            'known_diabetes' => true,
            'height_cm' => '175',
            'weight_kg' => '70',
            'allergy_note' => 'Pénicilline signalée',
            'smoker' => false,
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
        $this->assertSame(122, $record->blood_pressure_left_systolic);
        $this->assertSame(78, $record->blood_pressure_left_diastolic);
        $this->assertSame(118, $record->blood_pressure_right_systolic);
        $this->assertSame(76, $record->blood_pressure_right_diastolic);
        $this->assertSame('37.20', $record->temperature_celsius);
        $this->assertTrue($record->known_diabetes);
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
                ->where('careRecord.blood_pressure_left_systolic', 122)
                ->where('careRecord.blood_pressure_left_diastolic', 78)
                ->where('careRecord.blood_pressure_right_systolic', 118)
                ->where('careRecord.blood_pressure_right_diastolic', 76)
                ->where('careRecord.temperature_celsius', '37.20')
                ->where('careRecord.known_diabetes', true)
                ->where('careRecord.bmi', '22.86')
                ->where('careRecord.bmi_assessment.code', 'NORMAL')
                ->where('bmiReference.adult_min_age', 20)
                ->where('orientation.episode.care_completion_mode', 'MEDICINE')
                ->where('orientation.episode.care_transmission_expected', true)
                ->where('orientation.episode.care_vitals_recommended', true)
                ->has('careRecord.procedures', 1)
                ->where('careRecord.procedures.0.name', 'Injection IM')
            );
    }

    public function test_care_only_never_collects_hospitalization_or_medical_transmission_fields(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);

        $this->actingAs($nurse)->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('orientation.episode.care_completion_mode', 'FINISH')
                ->where('orientation.episode.care_transmission_expected', false)
                ->where('orientation.episode.care_vitals_recommended', false)
            );

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'hospitalization_reason' => 'Hospitaliser le patient',
            'hospitalized_at' => '2026-08-23 08:00:00',
            'discharged_at' => '2026-08-23 12:00:00',
        ])->assertSessionHasErrors([
            'hospitalization_reason',
            'hospitalized_at',
            'discharged_at',
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'transmission_reason' => 'Envoyer en Médecine',
        ])->assertSessionHasErrors('transmission_reason');

        $this->assertDatabaseCount('care_records', 0);
    }

    public function test_a_care_only_procedure_can_be_recorded_without_routine_vitals(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation($nurse);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => 1,
                'notes' => 'Pansement réalisé sans relevé systématique des constantes.',
            ]],
        ])->assertRedirect(route('care.orientations.show', $orientation));

        $record = CareRecord::query()->sole();

        $this->assertNull($record->blood_group);
        $this->assertNull($record->height_cm);
        $this->assertNull($record->weight_kg);
        $this->assertNull($record->bmi);
        $this->assertDatabaseHas('care_record_procedures', [
            'care_record_id' => $record->id,
            'procedure_code' => $procedure->code,
            'quantity' => 1,
        ]);
    }

    public function test_blood_pressure_temperature_and_diabetes_values_are_validated(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'blood_pressure_left_systolic' => 80,
            'blood_pressure_left_diastolic' => 120,
            'blood_pressure_right_systolic' => 130,
            'temperature_celsius' => 48,
            'known_diabetes' => 'inconnu',
        ])->assertSessionHasErrors([
            'blood_pressure_left_systolic',
            'blood_pressure_left_diastolic',
            'blood_pressure_right_diastolic',
            'temperature_celsius',
            'known_diabetes',
        ]);

        $this->assertDatabaseCount('care_records', 0);
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
            'smoker' => false,
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

    public function test_nurse_selects_a_known_allergy_and_adds_a_new_one_to_the_patient_record(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
            'patients.medical_history.view', 'patients.medical_history.manage',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);
        $patient = $orientation->episode->patient;
        $known = $patient->allergies()->create([
            'substance' => 'Pénicilline',
            'reaction' => 'Urticaire',
            'severity' => 'MODERATE',
            'recorded_by' => $nurse->id,
        ]);

        $this->actingAs($nurse)->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_view_allergies', true)
                ->where('capabilities.can_manage_allergies', true)
                ->has('patientAllergies', 1)
                ->where('patientAllergies.0.uuid', $known->uuid)
            );

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'allergy_uuids' => [$known->uuid],
            'new_allergies' => [[
                'substance' => 'Arachides',
                'reaction' => 'Dyspnée',
                'severity' => 'SEVERE',
            ]],
            'allergy_note' => 'Informations confirmées pendant le passage.',
        ])->assertRedirect();

        $this->assertDatabaseHas('patient_allergies', [
            'patient_id' => $patient->id,
            'substance' => 'Arachides',
            'reaction' => 'Dyspnée',
            'severity' => 'SEVERE',
            'recorded_by' => $nurse->id,
        ]);

        $record = CareRecord::query()->sole();
        $this->assertSame(
            ['Pénicilline', 'Arachides'],
            collect($record->allergy_snapshot)->pluck('substance')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'medical',
            'user_id' => $nurse->id,
        ]);
    }

    public function test_nurse_can_select_an_allergen_from_the_reference_for_a_new_patient(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
            'patients.medical_history.view', 'patients.medical_history.manage',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);
        $patient = $orientation->episode->patient;
        $reference = AllergenReference::query()->create([
            'code' => 'LATEX',
            'name' => 'Latex',
            'category' => AllergenCategory::Material,
            'active' => true,
        ]);

        $this->actingAs($nurse)->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->has('allergenReference', 1)
                ->where('allergenReference.0.uuid', $reference->uuid)
                ->where('allergenReference.0.category_label', 'Matériaux')
                ->has('patientAllergies', 0)
            );

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'allergen_reference_uuids' => [$reference->uuid],
        ])->assertRedirect();

        $this->assertDatabaseHas('patient_allergies', [
            'patient_id' => $patient->id,
            'allergen_reference_id' => $reference->id,
            'substance' => 'Latex',
            'recorded_by' => $nurse->id,
        ]);
        $this->assertSame(
            ['Latex'],
            collect(CareRecord::query()->sole()->allergy_snapshot)->pluck('substance')->all(),
        );
    }

    public function test_a_missing_historical_allergy_is_preserved_without_blocking_the_next_save(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
            'patients.medical_history.view',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation($nurse);
        $historicalUuid = '043463b7-1732-4715-8ce0-e4b339923e0d';

        CareRecord::query()->create([
            'episode_id' => $orientation->episode_id,
            'allergy_note' => 'Allergie déclarée pendant le premier relevé.',
            'allergy_snapshot' => [[
                'uuid' => $historicalUuid,
                'substance' => 'Ancienne allergie déclarée',
                'reaction' => null,
                'severity' => null,
            ]],
            'created_by' => $nurse->id,
            'updated_by' => $nurse->id,
        ]);

        $this->actingAs($nurse)->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->has('patientAllergies', 0)
                ->where('careRecord.allergy_snapshot.0.uuid', $historicalUuid)
                ->where('orientation.episode.care_vitals_recommended', false)
            );

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'allergy_uuids' => [$historicalUuid],
            'allergy_note' => 'Observation infirmière mise à jour.',
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect(route('care.orientations.show', $orientation));

        $record = CareRecord::query()->sole();

        $this->assertSame('Observation infirmière mise à jour.', $record->allergy_note);
        $this->assertSame(
            ['Ancienne allergie déclarée'],
            collect($record->allergy_snapshot)->pluck('substance')->all(),
        );
        $this->assertDatabaseHas('care_record_procedures', [
            'care_record_id' => $record->id,
            'procedure_code' => $procedure->code,
        ]);
    }

    public function test_an_allergy_from_another_patient_cannot_be_selected(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
            'patients.medical_history.view',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);
        $foreignAllergy = $this->patient()->allergies()->create(['substance' => 'Latex']);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'allergy_uuids' => [$foreignAllergy->uuid],
        ])->assertSessionHasErrors('allergy_uuids.0');

        $this->assertDatabaseCount('care_records', 0);
    }

    public function test_adding_a_permanent_allergy_requires_medical_history_manage_permission(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update',
            'vitals.view', 'vitals.create', 'vitals.update',
            'patients.medical_history.view',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'new_allergies' => [['substance' => 'Latex']],
        ])->assertForbidden();

        $reference = AllergenReference::query()->create([
            'code' => 'LATEX',
            'name' => 'Latex',
            'category' => AllergenCategory::Material,
            'active' => true,
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'allergen_reference_uuids' => [$reference->uuid],
        ])->assertForbidden();

        $this->assertDatabaseCount('patient_allergies', 0);
        $this->assertDatabaseCount('care_records', 0);
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

    public function test_an_injection_requires_an_explicit_allergy_check_and_keeps_the_trace(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update',
            'patients.medical_history.view',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation(
            $nurse,
            ReceptionRoutingMode::CareOnly,
            requiresAllergyCheck: true,
        );
        $payload = [
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => 1,
                'allergy_checked' => false,
            ]],
        ];

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record", $payload)
            ->assertSessionHasErrors('procedures.0.allergy_checked');
        $this->assertDatabaseCount('care_records', 0);

        $payload['procedures'][0]['allergy_checked'] = true;
        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record", $payload)
            ->assertRedirect(route('care.orientations.show', $orientation));

        $this->assertNotNull(CareRecordProcedure::query()->sole()->allergy_checked_at);
        $this->actingAs($nurse)->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('orientation.episode.care_requires_allergy_check', true)
                ->where('orientation.episode.designations.0.care_requires_allergy_check', true)
                ->where('procedureCatalog.0.care_requires_allergy_check', true)
            );
    }

    public function test_a_simple_care_act_can_be_saved_and_completed_atomically_without_vitals(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation($nurse);

        $this->actingAs($nurse)->put(
            "/care/orientations/{$orientation->uuid}/record-and-complete",
            [
                'procedures' => [[
                    'catalog_item_uuid' => $procedure->uuid,
                    'quantity' => 1,
                ]],
                'orient_to_medicine' => false,
            ],
        )->assertRedirect(route('care.index'));

        $record = CareRecord::query()->sole();
        $this->assertNull($record->blood_group);
        $this->assertNull($record->height_cm);
        $this->assertNull($record->weight_kg);
        $this->assertSame('COMPLETED', $orientation->fresh()->status->value);
        $this->assertDatabaseCount('care_record_procedures', 1);
        $this->assertDatabaseMissing('episode_orientations', [
            'episode_id' => $orientation->episode_id,
            'destination_module' => CatalogModule::Medicine->value,
        ]);
    }

    public function test_recording_a_care_act_does_not_require_permission_to_edit_vitals(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update', 'care.complete',
        ]);
        [$orientation, $procedure] = $this->activeCareOrientation($nurse);

        $this->actingAs($nurse)->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_edit', true)
                ->where('capabilities.can_edit_vitals', false)
            );

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $procedure->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect(route('care.orientations.show', $orientation));

        $this->assertDatabaseCount('care_record_procedures', 1);
    }

    public function test_care_only_cannot_be_completed_without_a_recorded_act(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        [$orientation] = $this->activeCareOrientation($nurse);

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasErrors('procedures');

        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
    }

    public function test_an_unknown_need_requires_a_reason_to_finish_without_an_act(): void
    {
        $nurse = $this->userWithPermissions([
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $this->app->make(PlanEpisodeRoutingAction::class)->planUnknownNeed($episode, $nurse);
        $orientation = $episode->orientations()->sole();
        $this->app->make(AcceptCareOrientationAction::class)->execute($orientation, $nurse);

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasErrors('no_procedure_reason');

        $this->actingAs($nurse)->put(
            "/care/orientations/{$orientation->uuid}/record-and-complete",
            [
                'no_procedure_reason' => 'Patient reparti avant la réalisation d’un acte.',
                'orient_to_medicine' => false,
            ],
        )->assertRedirect(route('care.index'));

        $this->assertSame(
            'Patient reparti avant la réalisation d’un acte.',
            CareRecord::query()->sole()->no_procedure_reason,
        );
        $this->assertSame('COMPLETED', $orientation->fresh()->status->value);
    }

    /** @return array{0: EpisodeOrientation, 1: CatalogItem} */
    private function activeCareOrientation(
        User $nurse,
        ReceptionRoutingMode $routingMode = ReceptionRoutingMode::CareOnly,
        bool $requiresAllergyCheck = false,
        bool $recommendsVitals = false,
    ): array {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $procedure = $this->procedure(
            $nurse,
            'INJECTION-IM',
            'Injection IM',
            true,
            $routingMode,
            $requiresAllergyCheck,
            $recommendsVitals,
        );
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

    private function procedure(
        User $actor,
        string $code,
        string $name,
        bool $receptionSelectable = true,
        ReceptionRoutingMode $routingMode = ReceptionRoutingMode::CareOnly,
        bool $requiresAllergyCheck = false,
        bool $recommendsVitals = false,
    ): CatalogItem {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'billable' => $code !== 'CARE-OTHER',
            'stockable' => false,
            'reception_selectable' => $receptionSelectable,
            'reception_routing_mode' => $receptionSelectable ? $routingMode : null,
            'care_requires_allergy_check' => $requiresAllergyCheck,
            'care_recommends_vitals' => $recommendsVitals,
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
