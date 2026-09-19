<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CreateImagingRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ImagingModality;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequestItem;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le compte rendu d'imagerie saisi depuis « Demandes d'examens ».
 *
 * L'écran est nouveau, l'endpoint ne l'est pas : c'est celui de la
 * consultation. Ce fichier protège donc surtout ce qui l'entoure — la portée
 * de la demande, le texte mis en forme, et le refus d'un second compte rendu.
 */
class ImagingReportFromDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_directory_offers_the_entry_only_for_an_exam_still_awaiting_a_result(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('requests.0.items.0.can_record', true)
                ->where('requests.0.items.0.resulted_at', null));
    }

    /** L'identifiant exposé est l'UUID, jamais la clé SQL (ADR-005). */
    public function test_the_exam_is_identified_by_its_uuid(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);

        $uuid = ImagingRequestItem::query()->sole()->uuid;

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('requests.0.items.0.uuid', $uuid));
    }

    public function test_the_doctor_records_a_formatted_report(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), [
                'result_value' => '<p>Rythme sinusal <strong>régulier</strong>.</p>',
                'result_notes' => '<p>À recontrôler dans un mois.</p>',
            ])
            ->assertSessionHasNoErrors();

        $item->refresh();

        $this->assertSame('<p>Rythme sinusal <strong>régulier</strong>.</p>', $item->result_value);
        $this->assertNotNull($item->resulted_at);
        $this->assertSame($doctor->id, $item->resulted_by);
    }

    /** Mise en forme oui, script non. */
    public function test_the_report_keeps_formatting_but_never_executable_markup(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), [
                'result_value' => '<p>Normal</p><script>alert(1)</script><a href="http://x">lien</a>',
            ])
            ->assertSessionHasNoErrors();

        $stored = $item->fresh()->result_value;

        $this->assertStringContainsString('<p>Normal</p>', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('href', $stored);
    }

    /** Un compte rendu vide après nettoyage n'en est pas un. */
    public function test_markup_alone_is_not_a_report(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), ['result_value' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors('result_value');

        $this->assertNull($item->fresh()->resulted_at);
    }

    public function test_a_second_report_is_refused(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)->post($this->url($orientation, $item), ['result_value' => '<p>Premier</p>']);

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), ['result_value' => '<p>Second</p>'])
            ->assertSessionHasErrors('result_value');

        $this->assertSame('<p>Premier</p>', $item->fresh()->result_value);
    }

    /**
     * Le trou que ce travail ferme : l'examen d'un autre passage était
     * atteignable avec un UUID valide, la requête ne vérifiant que la
     * permission.
     */
    public function test_an_exam_from_another_passage_is_unreachable(): void
    {
        $doctor = $this->doctor();
        [, $mine] = $this->passage($doctor);
        [, $other] = $this->passage($doctor);

        $this->imagingRequest($other, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)
            ->post($this->url($mine, $item), ['result_value' => '<p>Compte rendu</p>'])
            ->assertForbidden();

        $this->assertNull($item->fresh()->resulted_at);
    }

    /** Un résultat peut arriver après la clôture : le refuser le perdrait. */
    public function test_a_report_is_still_recordable_after_the_consultation_closed(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $consultation = $orientation->consultation()->firstOrFail();
        $consultation->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();

        $this->assertFalse($consultation->fresh()->isEditable());

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), ['result_value' => '<p>Compte rendu tardif</p>'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($item->fresh()->resulted_at);
    }

    /* ── Le compte rendu imprimable ────────────────────────────────── */

    public function test_a_recorded_report_is_printable_with_the_patient_and_the_clinic_letterhead(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)->post($this->url($orientation, $item), [
            'result_value' => '<p>Rythme sinusal <strong>régulier</strong>.</p>',
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/imaging-requests/{$item->uuid}/compte-rendu")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/ImagingReportPrint')
                ->where('document.value', '<p>Rythme sinusal <strong>régulier</strong>.</p>')
                ->where('document.resulted_by', $doctor->name)
                ->where('document.patient.patient_number', $episode->patient->patient_number)
                ->where('document.episode_number', $episode->episode_number)
                // La feuille papier porte l'adresse et le sexe : l'identité
                // vient du dossier, jamais du canevas (ADR-108).
                ->has('document.patient.address')
                ->where('document.patient.sex', $episode->patient->sex->value));
    }

    /**
     * Le titre suit la famille réglée au catalogue (ADR-106), jamais le
     * libellé de l'examen : « RÉSULTATS D'ÉCHOGRAPHIE » comme sur la feuille.
     */
    public function test_the_document_title_follows_the_catalog_family(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();
        $item->catalogItem->update(['imaging_modality' => ImagingModality::Ultrasound]);

        $this->actingAs($doctor)->post($this->url($orientation, $item), ['result_value' => '<p>Foie normal</p>']);

        $this->actingAs($doctor)
            ->get("/medicine/imaging-requests/{$item->uuid}/compte-rendu")
            ->assertInertia(fn ($page) => $page->where('document.title', 'Résultats d’échographie'));
    }

    /** Ce que le médecin relit à l'écran est ce que la famille emporte. */
    public function test_the_results_view_receives_the_same_document_as_the_print(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)->post($this->url($orientation, $item), ['result_value' => '<p>Rythme sinusal</p>']);

        $printed = null;
        $this->actingAs($doctor)
            ->get("/medicine/imaging-requests/{$item->uuid}/compte-rendu")
            ->assertInertia(function ($page) use (&$printed) {
                $printed = $page->toArray()['props']['document'];

                return $page;
            });

        $this->actingAs($doctor)
            // Une demande qui vient de recevoir son compte rendu est
            // « rendue récemment ».
            ->get('/medicine/demandes-examens?filter=recent')
            ->assertInertia(function ($page) use ($printed) {
                $shown = collect($page->toArray()['props']['requests'])
                    ->flatMap(fn ($row) => $row['items'])
                    ->firstWhere('uuid', $printed['uuid'])['document'] ?? null;

                $this->assertEquals($printed, $shown);

                return $page;
            });
    }

    /**
     * Un examen sans compte rendu n'a rien à imprimer : mieux vaut 404
     * qu'une feuille vide portant l'en-tête de la clinique, qui se lirait
     * comme un document officiel sans contenu.
     */
    public function test_an_exam_without_a_report_has_nothing_to_print(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)
            ->get("/medicine/imaging-requests/{$item->uuid}/compte-rendu")
            ->assertNotFound();
    }

    /** L'URL d'impression est construite par le serveur, jamais devinée. */
    public function test_the_directory_carries_the_print_url_only_once_the_report_exists(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('requests.0.items.0.print_url', null));

        $this->actingAs($doctor)->post($this->url($orientation, $item), [
            'result_value' => '<p>Normal</p>',
        ]);

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=recent')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('requests.0.items.0.print_url', "/medicine/imaging-requests/{$item->uuid}/compte-rendu"));
    }

    private function url(EpisodeOrientation $orientation, ImagingRequestItem $item): string
    {
        return "/medicine/orientations/{$orientation->uuid}/imaging-requests/{$item->uuid}/result";
    }

    private function imagingRequest(EpisodeOrientation $orientation, User $doctor): void
    {
        $item = CatalogItem::query()->create([
            'code' => 'ECG-'.uniqid(),
            'name' => 'Électrocardiogramme (ECG)',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->app->make(CreateImagingRequestAction::class)->execute(
            $orientation->consultation()->firstOrFail(),
            [['catalog_item_uuid' => $item->uuid]],
            null,
            $doctor,
        );
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);

        foreach ([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view',
            'paraclinical_requests.view',
            'imaging_orders.create', 'imaging_orders.view', 'imaging_results.create',
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
