<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CreateImagingRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\AuditLog;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\ImagingExamReportTemplate;
use App\Models\ImagingReportTemplate;
use App\Models\ImagingRequestItem;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-108 — ouvrir la saisie d'une échographie pré-applique sa feuille.
 *
 * Le lien examen → feuille est **réglé**, jamais déduit du nom ou du code par
 * un motif (ADR-052) : une liste explicite par code, que le site peut
 * corriger, y compris pour dire « aucune ».
 */
class ImagingExamDefaultTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exam_with_a_known_sheet_opens_on_it(): void
    {
        $catalog = $this->app->make(ImagingReportTemplateCatalog::class);

        $this->assertSame('ECHO_PELVIENNE', $catalog->defaultKeyFor($this->line('ECHO-PEL')));
        $this->assertSame('ECHO_ABDOMINO_PELVIENNE', $catalog->defaultKeyFor($this->line('ECHO-ABD-PEL')));
        $this->assertSame('ECHO_OBSTETRICALE_T2_T3', $catalog->defaultKeyFor($this->line('ECHO-OBS-T2')));
        $this->assertSame('ECHO_OBSTETRICALE_T2_T3', $catalog->defaultKeyFor($this->line('ECHO-OBS-T3')));
    }

    /** Aucune feuille, papier ou proposée, ne correspond sans ambiguïté à ces examens. */
    public function test_an_exam_without_a_configured_sheet_gets_none_and_nothing_is_guessed(): void
    {
        $catalog = $this->app->make(ImagingReportTemplateCatalog::class);

        foreach (['ECHO-OBS', 'ECHO-MORPHO', 'ECHO-CARD', 'ECG-STD'] as $code) {
            $this->assertNull($catalog->defaultKeyFor($this->line($code)), $code);
        }
    }

    public function test_the_site_setting_wins_over_the_default_list_including_none(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);
        $mammary = $this->line('ECHO-MAMMAIRE');
        $pelvic = $this->line('ECHO-PEL');

        ImagingExamReportTemplate::query()->create(['catalog_item_id' => $mammary->catalog_item_id, 'template_key' => 'ECHO_PELVIENNE']);
        ImagingExamReportTemplate::query()->create(['catalog_item_id' => $pelvic->catalog_item_id, 'template_key' => null]);

        $catalog = $this->app->make(ImagingReportTemplateCatalog::class);

        $this->assertSame('ECHO_PELVIENNE', $catalog->defaultKeyFor($mammary));
        // « Aucune » est un choix explicite : il l'emporte sur la liste du code.
        $this->assertNull($catalog->defaultKeyFor($pelvic));
    }

    public function test_a_doctor_sets_the_sheet_of_an_exam_and_it_is_audited(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);
        $item = $this->persistedItem($doctor);

        $this->actingAs($doctor)
            ->put("/medicine/imaging-request-items/{$item->uuid}/default-template", ['template_key' => 'ECHO_PELVIENNE'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ECHO_PELVIENNE', $this->app->make(ImagingReportTemplateCatalog::class)->defaultKeyFor($item->fresh()));
        $this->assertTrue(AuditLog::query()->where('action', 'imaging.exam_template.set')->exists());

        // Et on peut ne plus rien proposer.
        $this->actingAs($doctor)
            ->put("/medicine/imaging-request-items/{$item->uuid}/default-template", ['template_key' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->app->make(ImagingReportTemplateCatalog::class)->defaultKeyFor($item->fresh()));
    }

    public function test_setting_a_sheet_needs_the_right_and_an_existing_sheet(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);
        $item = $this->persistedItem($doctor);
        $url = "/medicine/imaging-request-items/{$item->uuid}/default-template";

        $this->actingAs(User::factory()->create(['role_id' => Role::query()->create(['code' => 'OTHER', 'name' => 'Autre'])->id]))->put($url, ['template_key' => 'ECHO_PELVIENNE'])->assertForbidden();
        $this->actingAs($doctor)->put($url, ['template_key' => 'NOPE'])->assertSessionHasErrors('template_key');

        $this->assertSame(0, ImagingExamReportTemplate::query()->count());
    }

    public function test_a_sheet_created_from_an_exam_can_be_proposed_for_it_at_once(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);
        $item = $this->persistedItem($doctor);

        $this->actingAs($doctor)->post('/medicine/imaging-report-templates', [
            'name' => 'Échographie mammaire',
            'body_html' => '<p><strong>SEIN DROIT</strong></p><hr><p><strong>SEIN GAUCHE</strong></p>',
            'default_for_item_uuid' => $item->uuid,
        ])->assertSessionHasNoErrors();

        $template = ImagingReportTemplate::query()->firstOrFail();

        $this->assertSame(
            ImagingReportTemplateCatalog::CUSTOM_KEY_PREFIX.$template->uuid,
            $this->app->make(ImagingReportTemplateCatalog::class)->defaultKeyFor($item->fresh()),
        );
    }

    /** Une feuille retirée n'est plus proposée : mieux vaut aucune feuille qu'une feuille disparue. */
    public function test_an_archived_sheet_is_no_longer_proposed(): void
    {
        $doctor = $this->doctor(['imaging_templates.create', 'imaging_templates.archive']);
        $item = $this->persistedItem($doctor);

        $this->actingAs($doctor)->post('/medicine/imaging-report-templates', [
            'name' => 'Ma feuille',
            'body_html' => '<p>Contenu</p>',
            'default_for_item_uuid' => $item->uuid,
        ]);
        $template = ImagingReportTemplate::query()->firstOrFail();

        $this->actingAs($doctor)->delete('/medicine/imaging-report-templates/'.$template->uuid);

        $this->assertNull($this->app->make(ImagingReportTemplateCatalog::class)->defaultKeyFor($item->fresh()));
    }

    /** Une ligne non enregistrée suffit à interroger le catalogue : seuls deux champs comptent. */
    private function line(string $code): ImagingRequestItem
    {
        $catalogItem = CatalogItem::query()->firstOrCreate(['code' => $code], [
            'name' => 'Examen '.$code,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
            'billable' => false,
            'stockable' => false,
        ]);

        return new ImagingRequestItem([
            'catalog_item_id' => $catalogItem->getKey(),
            'catalog_item_code_snapshot' => $code,
        ]);
    }

    private function persistedItem(User $doctor): ImagingRequestItem
    {
        [, $orientation] = $this->passage($doctor);

        $catalogItem = CatalogItem::query()->create([
            'code' => 'ECHO-MAMMAIRE-'.uniqid(),
            'name' => 'Échographie mammaire',
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
            [['catalog_item_uuid' => $catalogItem->uuid]],
            null,
            $doctor,
        );

        return ImagingRequestItem::query()->latest('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function doctor(array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);

        foreach ([...$permissions, 'medical_record.view', 'consultations.view', 'consultations.create', 'imaging_orders.create', 'imaging_orders.view'] as $name) {
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
        // ADR-177 — une prestation d'arrivée n'ouvre plus de file : l'orientation
        // vers ce service est désormais un geste réel, posé ici explicitement.
        $this->app->make(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Medicine, $doctor);

        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();

        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
