<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CreateImagingRequestAction;
use App\Actions\Medicine\CreateLabRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ImagingModality;
use App\Enums\ReceptionRoutingMode;
use App\Models\AuditLog;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequest;
use App\Models\ImagingRequestItem;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-131 — filtrer « Demandes d'examens » par famille et ranger soi-même une
 * demande lue.
 */
class ParaclinicalRequestArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_read_request_is_archived_by_hand_and_leaves_the_recent_view(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $item = $this->exam($orientation, $doctor, ImagingModality::Cardiology, 'ECG');
        $this->record($orientation, $doctor, $item);
        $request = ImagingRequest::query()->sole();

        $this->actingAs($doctor)->get('/medicine/demandes-examens?filter=recent')
            ->assertInertia(fn ($page) => $page->has('requests', 1)->where('requests.0.can_archive', true)->where('counts.recent', 1));

        $this->actingAs($doctor)->post("/medicine/paraclinical-requests/imaging/{$request->uuid}/archive")
            ->assertSessionHasNoErrors();

        $this->assertNotNull($request->fresh()->archived_at);
        $this->assertSame($doctor->id, $request->fresh()->archived_by);
        $this->assertTrue(AuditLog::query()->where('action', 'paraclinical_request.archive')->exists());

        // Rangée à la main : hors « récentes », dans « archivées », et rien n'est supprimé.
        $this->actingAs($doctor)->get('/medicine/demandes-examens?filter=recent')
            ->assertInertia(fn ($page) => $page->has('requests', 0)->where('counts.recent', 0)->where('counts.archived', 1));
        $this->actingAs($doctor)->get('/medicine/demandes-examens?filter=archived')
            ->assertInertia(fn ($page) => $page->has('requests', 1)
                ->where('requests.0.can_unarchive', true)
                ->where('requests.0.can_archive', false));
    }

    public function test_an_archived_request_can_be_brought_back(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->record($orientation, $doctor, $this->exam($orientation, $doctor, ImagingModality::Ultrasound, 'Écho'));
        $request = ImagingRequest::query()->sole();

        $this->actingAs($doctor)->post("/medicine/paraclinical-requests/imaging/{$request->uuid}/archive");
        $this->actingAs($doctor)->post("/medicine/paraclinical-requests/imaging/{$request->uuid}/unarchive")
            ->assertSessionHasNoErrors();

        $this->assertNull($request->fresh()->archived_at);
        $this->actingAs($doctor)->get('/medicine/demandes-examens?filter=recent')
            ->assertInertia(fn ($page) => $page->has('requests', 1));
    }

    /** Ranger du travail à faire le ferait disparaître : un vide muet se lit « rien à faire ». */
    public function test_a_request_still_waiting_for_a_result_cannot_be_archived(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->exam($orientation, $doctor, ImagingModality::Cardiology, 'ECG');
        $request = ImagingRequest::query()->sole();

        $this->actingAs($doctor)->post("/medicine/paraclinical-requests/imaging/{$request->uuid}/archive")
            ->assertSessionHasErrors('request');

        $this->assertNull($request->fresh()->archived_at);
        $this->actingAs($doctor)->get('/medicine/demandes-examens')
            ->assertInertia(fn ($page) => $page->where('requests.0.can_archive', false));
    }

    public function test_archiving_needs_its_own_permission(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->record($orientation, $doctor, $this->exam($orientation, $doctor, ImagingModality::Cardiology, 'ECG'));
        $request = ImagingRequest::query()->sole();
        $doctor->role->permissions()->detach(Permission::query()->where('name', 'paraclinical_requests.archive')->value('id'));

        $this->actingAs($doctor->fresh())->post("/medicine/paraclinical-requests/imaging/{$request->uuid}/archive")
            ->assertForbidden();

        $this->assertNull($request->fresh()->archived_at);
    }

    public function test_an_unknown_family_is_not_a_route(): void
    {
        $this->actingAs($this->doctor())->post('/medicine/paraclinical-requests/nonsense/'.fake()->uuid().'/archive')
            ->assertNotFound();
    }

    public function test_the_list_filters_by_family_and_counts_each_family_in_the_current_view(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->exam($orientation, $doctor, ImagingModality::Cardiology, 'ECG');
        $this->exam($orientation, $doctor, ImagingModality::Ultrasound, 'Échographie abdominale');
        $this->exam($orientation, $doctor, ImagingModality::Ultrasound, 'Échographie pelvienne');
        $this->labExam($orientation, $doctor);

        $this->actingAs($doctor)->get('/medicine/demandes-examens')
            ->assertInertia(fn ($page) => $page
                ->where('type_counts.ALL', 4)
                ->where('type_counts.ECG', 1)
                ->where('type_counts.ULTRASOUND', 2)
                ->where('type_counts.LAB', 1)
                ->where('type_counts.UNCLASSIFIED', 0));

        $this->actingAs($doctor)->get('/medicine/demandes-examens?type=ULTRASOUND')
            ->assertInertia(fn ($page) => $page
                ->has('requests', 2)
                ->where('filters.type', 'ULTRASOUND')
                // Les vues comptent sous la famille choisie.
                ->where('counts.active', 2));

        $this->actingAs($doctor)->get('/medicine/demandes-examens?type=LAB')
            ->assertInertia(fn ($page) => $page->has('requests', 1)->where('requests.0.kind', 'lab'));
    }

    /** ADR-106 — un examen sans famille a la sienne, il n'est pas rangé au hasard. */
    public function test_an_exam_with_no_family_is_neither_ecg_nor_ultrasound(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->exam($orientation, $doctor, null, 'Doppler');

        $this->actingAs($doctor)->get('/medicine/demandes-examens')
            ->assertInertia(fn ($page) => $page
                ->where('type_counts.UNCLASSIFIED', 1)
                ->where('type_counts.ECG', 0)
                ->where('type_counts.ULTRASOUND', 0));
    }

    public function test_an_unknown_family_filter_filters_nothing(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->exam($orientation, $doctor, ImagingModality::Cardiology, 'ECG');

        $this->actingAs($doctor)->get('/medicine/demandes-examens?type=BOGUS')
            ->assertInertia(fn ($page) => $page->has('requests', 1)->where('filters.type', null));
    }

    private function url(EpisodeOrientation $orientation, ImagingRequestItem $item): string
    {
        return "/medicine/orientations/{$orientation->uuid}/imaging-requests/{$item->uuid}/result";
    }

    private function exam(EpisodeOrientation $orientation, User $doctor, ?ImagingModality $modality, string $name): ImagingRequestItem
    {
        $item = CatalogItem::query()->create([
            'code' => 'IMG-'.uniqid(),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
            'billable' => false,
            'stockable' => false,
            'imaging_modality' => $modality,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->app->make(CreateImagingRequestAction::class)->execute(
            $orientation->consultation()->firstOrFail(),
            [['catalog_item_uuid' => $item->uuid]],
            null,
            $doctor,
        );

        return ImagingRequestItem::query()->latest('id')->firstOrFail();
    }

    private function labExam(EpisodeOrientation $orientation, User $doctor): void
    {
        $item = CatalogItem::query()->create([
            'code' => 'LAB-'.uniqid(),
            'name' => 'Numération formule sanguine (NFS)',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory,
            'unit' => 'examen',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->app->make(CreateLabRequestAction::class)->execute(
            $orientation->consultation()->firstOrFail(),
            [['catalog_item_uuid' => $item->uuid]],
            null,
            $doctor,
        );
    }

    private function record(EpisodeOrientation $orientation, User $doctor, ImagingRequestItem $item): void
    {
        $this->actingAs($doctor)->post($this->url($orientation, $item), ['result_value' => '<p>Rendu</p>'])->assertSessionHasNoErrors();
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);

        foreach ([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view',
            'paraclinical_requests.view',
            'imaging_orders.create', 'imaging_orders.view', 'imaging_results.create', 'imaging_results.update',
            'paraclinical_requests.archive', 'laboratory_orders.create',
            'laboratory_orders.view', 'diagnoses.view', 'prescriptions.view',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function passage(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Eliana',
            'last_name' => 'Ranavalona',
            'birth_date' => '1985-04-12',
            'sex' => 'F',
        ]);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);

        $item = CatalogItem::query()->create([
            'code' => 'ITEM-'.uniqid(),
            'name' => 'Échographie abdominale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
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

        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();

        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
