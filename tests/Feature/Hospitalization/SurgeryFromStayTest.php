<?php

namespace Tests\Feature\Hospitalization;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\ReceptionRoutingMode;
use App\Enums\SurgicalRequestOrigin;
use App\Enums\SurgicalRequestStatus;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-160 — le patient hospitalisé descend au bloc, et garde son lit.
 */
class SurgeryFromStayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_patient_goes_to_the_block_and_keeps_his_bed(): void
    {
        $doctor = $this->doctor();
        [$episode, $stay] = $this->admitted($doctor);
        $act = $this->act('SURG-HERNIE-INGUINALE', 'Hernie inguinale');

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid,
            'indication' => 'Hernie étranglée',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $request = SurgicalRequest::query()->where('episode_id', $episode->id)->sole();
        $this->assertSame(SurgicalRequestOrigin::Hospitalization, $request->origin);
        $this->assertSame(SurgicalRequestStatus::Pending, $request->status);
        $this->assertSame('Hernie inguinale', $request->procedure_name);
        $this->assertSame('Hernie étranglée', $request->procedure_details);
        // Repris du séjour, jamais ressaisi.
        $this->assertStringContainsString('Service : Médecine interne', $request->notes);
        $this->assertStringContainsString('Diagnostic d’entrée : Gastro-entérite aiguë', $request->notes);
        $this->assertStringContainsString('Priorité : URGENT', $request->notes);

        // Le lit n'est pas perdu : c'est tout l'objet de cette décision.
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $stay->episodeOrientation->fresh()->status);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => CatalogModule::Hospitalization->value,
            'destination_module' => CatalogModule::Surgery->value,
            'status' => EpisodeOrientationStatus::Pending->value,
        ]);
    }

    public function test_a_second_click_never_opens_a_second_file_at_the_block(): void
    {
        $doctor = $this->doctor();
        [$episode, $stay] = $this->admitted($doctor);
        $act = $this->act('SURG-ABCES', 'Abcès');

        foreach ([1, 2] as $_) {
            $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
                'catalog_item_uuid' => $act->uuid,
                'priority' => 'NORMAL',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(1, SurgicalRequest::query()->where('episode_id', $episode->id)->count());
    }

    public function test_the_intervention_is_chosen_never_guessed(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", ['priority' => 'NORMAL'])
            ->assertSessionHasErrors('catalog_item_uuid');

        $this->assertDatabaseCount('surgical_requests', 0);
    }

    public function test_it_is_the_right_to_request_surgery_that_decides_not_the_role(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $act = $this->act('SURG-ABCES', 'Abcès');
        $withoutRight = $this->userWith('NURSE', ['hospitalization.view', 'patients.view']);

        $this->actingAs($withoutRight)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid,
            'priority' => 'NORMAL',
        ])->assertForbidden();

        $this->assertDatabaseCount('surgical_requests', 0);
    }

    public function test_a_finished_stay_sends_no_one_to_the_block(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $act = $this->act('SURG-ABCES', 'Abcès');
        $stay->update(['status' => HospitalStayStatus::Discharged, 'active_key' => null, 'discharged_at' => now()]);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid,
            'priority' => 'NORMAL',
        ])->assertForbidden();

        $this->assertDatabaseCount('surgical_requests', 0);
    }

    public function test_the_stay_page_shows_what_went_to_the_block(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $act = $this->act('SURG-ABCES', 'Abcès');
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid,
            'priority' => 'NORMAL',
        ]);

        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.can_request_surgery', true)
                ->has('surgeries', 1)
                ->where('surgeries.0.procedure_name', 'Abcès')
                ->where('surgeries.0.origin', 'HOSPITALIZATION')
                // Sans `surgery.view`, le fait est dit, le lien n'est pas proposé.
                ->where('surgeries.0.url', null)
                ->has('surgeryProcedures'));
    }

    public function test_the_block_knows_a_bed_is_waiting(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $stay->update(['room_bed' => 'Ch. 12 — lit B']);
        $act = $this->act('SURG-ABCES', 'Abcès');
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid,
            'priority' => 'NORMAL',
        ]);
        $request = SurgicalRequest::query()->sole();

        $surgeon = $this->userWith('SURGERY', ['surgery.view', 'patients.view']);
        $this->actingAs($surgeon)->get("/surgery/{$request->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('hospitalStay.room_bed', 'Ch. 12 — lit B')
                ->where('hospitalStay.service', 'Médecine interne')
                // Pas de `hospitalization.view` : pas de lien vers un refus.
                ->where('hospitalStay.url', null));

        $this->actingAs($surgeon)->get('/surgery')
            ->assertInertia(fn (Assert $page) => $page
                ->where('surgicalRequests.data.0.hospital_stay.room_bed', 'Ch. 12 — lit B')
                ->where('surgicalRequests.data.0.origin', 'HOSPITALIZATION'));

        $withStayRight = $this->userWith('SURGERY_LEAD', ['surgery.view', 'patients.view', 'hospitalization.view']);
        $this->actingAs($withStayRight)->get("/surgery/{$request->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('hospitalStay.url', "/hospitalisation/{$stay->uuid}"));
    }

    public function test_once_the_stay_is_over_the_block_no_longer_announces_a_bed(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $act = $this->act('SURG-ABCES', 'Abcès');
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid,
            'priority' => 'NORMAL',
        ]);
        $stay->update(['status' => HospitalStayStatus::Discharged, 'active_key' => null, 'discharged_at' => now()]);

        $surgeon = $this->userWith('SURGERY', ['surgery.view', 'patients.view']);
        $this->actingAs($surgeon)->get('/surgery/'.SurgicalRequest::query()->sole()->uuid)
            ->assertInertia(fn (Assert $page) => $page->where('hospitalStay', null));
    }

    public function test_the_list_of_inpatients_marks_who_goes_to_the_block(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        [, $other] = $this->admitted($doctor);
        $act = $this->act('SURG-ABCES', 'Abcès');
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid,
            'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->get('/hospitalisation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.active', 2)
                ->where('counts.bloc', 1)
                ->where('filter', null)
                ->has('stays.data', 2)
                ->where('stays.data', fn ($rows) => collect($rows)->firstWhere('uuid', $stay->uuid)['surgery']['status'] === 'PENDING'
                    && collect($rows)->firstWhere('uuid', $stay->uuid)['surgery']['active'] === true
                    && collect($rows)->firstWhere('uuid', $stay->uuid)['surgery']['procedure_name'] === 'Abcès'
                    // Sans `surgery.view`, le fait est dit, le lien vers le bloc n'est pas proposé.
                    && collect($rows)->firstWhere('uuid', $stay->uuid)['surgery']['url'] === null
                    // Un patient sans passage au bloc n'a aucun repère, jamais un repère vide.
                    && collect($rows)->firstWhere('uuid', $other->uuid)['surgery'] === null));

        // La carte filtre : seuls les patients qui vont au bloc, ou y sont.
        $this->actingAs($doctor)->get('/hospitalisation?filter=bloc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter', 'bloc')
                ->has('stays.data', 1)
                ->where('stays.data.0.uuid', $stay->uuid));

        // Un filtre inconnu ne filtre rien : un signet périmé ne vide pas la liste.
        $this->actingAs($doctor)->get('/hospitalisation?filter=nimporte')
            ->assertInertia(fn (Assert $page) => $page->where('filter', null)->has('stays.data', 2));

        $withBlockRight = $this->userWith('MEDICINE_LEAD', ['hospitalization.view', 'surgery.view']);
        $request = SurgicalRequest::query()->sole();
        $this->actingAs($withBlockRight)->get('/hospitalisation?filter=bloc')
            ->assertInertia(fn (Assert $page) => $page->where('stays.data.0.surgery.url', "/surgery/{$request->uuid}"));
    }

    public function test_the_list_shows_the_most_advanced_block_step_then_the_operation_once_done(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        foreach (['SURG-ABCES' => 'Abcès', 'SURG-HERNIE-INGUINALE' => 'Hernie inguinale'] as $code => $name) {
            $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
                'catalog_item_uuid' => $this->act($code, $name)->uuid,
                'priority' => 'NORMAL',
            ])->assertSessionHasNoErrors();
        }
        $hernia = SurgicalRequest::query()->where('procedure_name', 'Hernie inguinale')->sole();
        $abscess = SurgicalRequest::query()->where('procedure_name', 'Abcès')->sole();
        $hernia->forceFill(['status' => SurgicalRequestStatus::InProgress])->save();

        // Le patient en salle l'emporte ; l'autre demande ne disparaît pas.
        $this->actingAs($doctor)->get('/hospitalisation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stays.data.0.surgery.status', 'IN_PROGRESS')
                ->where('stays.data.0.surgery.procedure_name', 'Hernie inguinale')
                ->where('stays.data.0.surgery.others', 1));

        // Opéré, l'autre demande annulée : la liste dit l'opération, plus un passage au bloc en cours.
        $hernia->forceFill(['status' => SurgicalRequestStatus::Completed, 'completed_at' => now()])->save();
        $abscess->forceFill(['status' => SurgicalRequestStatus::Cancelled, 'cancelled_at' => now()])->save();

        $this->actingAs($doctor)->get('/hospitalisation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.bloc', 0)
                ->where('stays.data.0.surgery.status', 'COMPLETED')
                ->where('stays.data.0.surgery.active', false)
                ->where('stays.data.0.surgery.others', 0));

        $this->actingAs($doctor)->get('/hospitalisation?filter=bloc')
            ->assertInertia(fn (Assert $page) => $page->has('stays.data', 0));
    }

    /** @return array{0: Episode, 1: HospitalStay} */
    private function admitted(User $doctor): array
    {
        [$episode, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        return [$episode->fresh(), HospitalStay::query()->where('episode_id', $episode->id)->sole()];
    }

    private function act(string $code, string $name): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Surgery,
            'unit' => 'intervention',
            'billable' => true,
            'stockable' => false,
            'created_by' => User::query()->value('id'),
            'updated_by' => User::query()->value('id'),
        ]);
    }

    private function doctor(): User
    {
        return $this->userWith('MEDICINE', [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'diagnoses.view', 'diagnoses.create',
            'hospitalization.request', 'hospitalization.view', 'surgery.request',
        ]);
    }

    private function userWith(string $roleCode, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function consultation(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $item->uuid,
            'quantity' => 1,
        ]], $doctor);
        // ADR-177 — une prestation d'arrivée n'ouvre plus de file : l'orientation
        // vers ce service est désormais un geste réel, posé ici explicitement.
        $this->app->make(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Medicine, $doctor);
        $medicine = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($medicine, $doctor);

        return [$episode->fresh(), $medicine->fresh()];
    }
}
