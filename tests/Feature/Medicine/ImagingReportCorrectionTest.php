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
use App\Models\ImagingRequestItem;
use App\Models\ImagingResultRevision;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Support\ImagingReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * ADR-130 — corriger un compte rendu d'imagerie déjà enregistré.
 *
 * Ce que ces tests protègent : une correction ne perd jamais la version qu'elle
 * remplace, garde la signature d'origine, exige son propre droit, et ne
 * remplace pas la première saisie.
 */
class ImagingReportCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_correction_keeps_the_replaced_version_and_the_original_signature(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Rythme irrégulier.</p>', '<p>Note initiale</p>');
        $signedAt = $item->resulted_at;
        $corrector = $this->doctor();

        $this->actingAs($corrector)
            ->put($this->url($orientation, $item), [
                'result_value' => '<p>Rythme <strong>régulier</strong>.</p>',
                'result_notes' => '<p>Note corrigée</p>',
                'reason' => 'Faute de frappe sur le rythme',
            ])
            ->assertSessionHasNoErrors();

        $item->refresh();

        // Le compte rendu courant est le nouveau ; la signature d'origine ne bouge pas.
        $this->assertSame('<p>Rythme <strong>régulier</strong>.</p>', $item->result_value);
        $this->assertSame($doctor->id, $item->resulted_by);
        $this->assertTrue($signedAt->equalTo($item->resulted_at));
        $this->assertSame($corrector->id, $item->corrected_by);
        $this->assertNotNull($item->corrected_at);

        // L'ancienne version est intacte, avec son auteur et le motif.
        $revision = ImagingResultRevision::query()->sole();
        $this->assertSame(1, $revision->revision);
        $this->assertSame('<p>Rythme irrégulier.</p>', $revision->result_value);
        $this->assertSame('<p>Note initiale</p>', $revision->result_notes);
        $this->assertSame($doctor->id, $revision->resulted_by);
        $this->assertSame($corrector->id, $revision->superseded_by);
        $this->assertSame('Faute de frappe sur le rythme', $revision->reason);

        $this->assertTrue(AuditLog::query()->where('action', 'imaging.result.correct')->exists());
    }

    public function test_successive_corrections_stack_their_versions(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>V1</p>');

        $this->actingAs($doctor)->put($this->url($orientation, $item), ['result_value' => '<p>V2</p>'])->assertSessionHasNoErrors();
        $this->actingAs($doctor)->put($this->url($orientation, $item), ['result_value' => '<p>V3</p>'])->assertSessionHasNoErrors();

        $this->assertSame(['<p>V1</p>', '<p>V2</p>'], ImagingResultRevision::query()->orderBy('revision')->pluck('result_value')->all());
        $this->assertSame('<p>V3</p>', $item->fresh()->result_value);
    }

    public function test_a_correction_that_changes_nothing_is_refused(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Identique</p>');

        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<p>Identique</p>'])
            ->assertSessionHasErrors('result_value');

        $this->assertSame(0, ImagingResultRevision::query()->count());
        $this->assertNull($item->fresh()->corrected_at);
    }

    public function test_a_report_cannot_be_corrected_into_nothing(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Contenu</p>');

        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors('result_value');

        $this->assertSame('<p>Contenu</p>', $item->fresh()->result_value);
    }

    /**
     * Une feuille dont toutes les cases sont vidées ne se réduit plus qu'à ses
     * sauts de colonne : ce n'est pas un compte rendu.
     */
    public function test_a_report_made_only_of_column_breaks_is_not_a_report(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Contenu</p>');

        foreach (['<hr>', '<p><br></p><hr><p><br></p><hr><p><br></p>'] as $empty) {
            $this->actingAs($doctor)
                ->put($this->url($orientation, $item), ['result_value' => $empty])
                ->assertSessionHasErrors('result_value');
        }

        $this->assertSame('<p>Contenu</p>', $item->fresh()->result_value);
    }

    /**
     * ADR-108 — l'aperçu compose le document sans rien écrire : avant la
     * première saisie comme pendant une correction.
     */
    public function test_the_preview_composes_the_document_without_writing_anything(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->latest('id')->firstOrFail();

        $response = $this->actingAs($doctor)
            ->postJson($this->url($orientation, $item).'/preview', [
                'result_value' => '<p><strong>UTERUS</strong></p><hr><p><strong>CONCLUSION</strong></p><script>x()</script>',
                'result_notes' => '<p>À revoir</p>',
            ])
            ->assertOk();

        $document = $response->json('document');

        $this->assertSame('Ranavalona', $document['patient']['last_name']);
        $this->assertSame($doctor->name, $document['resulted_by']);
        $this->assertCount(2, $document['regions']);
        $this->assertStringNotContainsString('script', $document['value']);
        $this->assertStringContainsString('À revoir', $document['notes']);

        // Rien n'est enregistré : l'examen attend toujours son résultat.
        $fresh = $item->fresh();
        $this->assertNull($fresh->resulted_at);
        $this->assertNull($fresh->result_value);
        $this->assertFalse(AuditLog::query()->where('action', 'like', 'imaging.result%')->exists());
    }

    public function test_the_preview_is_refused_without_a_report_or_without_the_right(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Contenu</p>');

        $this->actingAs($doctor)
            ->postJson($this->url($orientation, $item).'/preview', ['result_value' => '<hr>'])
            ->assertUnprocessable();

        $outsider = User::factory()->create(['role_id' => Role::query()->create(['code' => 'OTHER', 'name' => 'Autre'])->id]);
        $this->actingAs($outsider)
            ->postJson($this->url($orientation, $item).'/preview', ['result_value' => '<p>Contenu</p>'])
            ->assertForbidden();
    }

    /**
     * ADR-108 — le bandeau porte le titre de la feuille choisie. Le serveur le
     * lit lui-même : le navigateur n'envoie qu'une clé.
     */
    public function test_the_sheet_title_is_frozen_on_the_report_and_read_by_the_server(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->latest('id')->firstOrFail();

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), [
                'result_value' => '<p>Contenu</p>',
                'sheet_key' => 'ECHO_OBSTETRICALE_T2_T3',
                // Un titre envoyé par le navigateur n'est jamais retenu.
                'sheet_title' => 'TITRE FORGÉ',
            ])
            ->assertSessionHasNoErrors();

        $item = $item->fresh();
        $this->assertSame('ÉCHOGRAPHIE OBSTÉTRICALE (2ème – 3ème TRIMESTRE)', $item->report_sheet_title);

        $document = ImagingReportDocument::for($item, $this->app->make(ClinicalRichTextSanitizer::class));
        $this->assertSame('ÉCHOGRAPHIE OBSTÉTRICALE (2ème – 3ème TRIMESTRE)', $document['sheet_title']);
    }

    public function test_free_text_keeps_the_exam_name_and_an_unknown_sheet_changes_nothing(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Contenu</p>');
        $this->assertNull($item->report_sheet_title);

        // Feuille disparue : traitée comme non touchée.
        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<p>Une</p>', 'sheet_key' => 'custom-disparue'])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->report_sheet_title);

        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<p>Deux</p>', 'sheet_key' => 'ECHO_PELVIENNE'])
            ->assertSessionHasNoErrors();
        $this->assertSame('ÉCHOGRAPHIE PELVIENNE', $item->fresh()->report_sheet_title);

        // Correction sans toucher à la feuille : le titre reste.
        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<p>Trois</p>'])
            ->assertSessionHasNoErrors();
        $this->assertSame('ÉCHOGRAPHIE PELVIENNE', $item->fresh()->report_sheet_title);

        // Retour à la saisie libre : le titre est retiré.
        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<p>Quatre</p>', 'sheet_key' => 'FREE'])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->report_sheet_title);
    }

    public function test_the_preview_carries_the_chosen_sheet_title(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->latest('id')->firstOrFail();

        $this->actingAs($doctor)
            ->postJson($this->url($orientation, $item).'/preview', ['result_value' => '<p>Contenu</p>', 'sheet_key' => 'ECHO_PELVIENNE'])
            ->assertOk()
            ->assertJsonPath('document.sheet_title', 'ÉCHOGRAPHIE PELVIENNE');

        $this->actingAs($doctor)
            ->postJson($this->url($orientation, $item).'/preview', ['result_value' => '<p>Contenu</p>'])
            ->assertOk()
            ->assertJsonPath('document.sheet_title', null);
    }

    public function test_the_corrected_text_is_sanitised_like_the_first_entry(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Avant</p>');

        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<p>Après</p><script>alert(1)</script>'])
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('<script', $item->fresh()->result_value);
    }

    public function test_there_is_nothing_to_correct_before_the_first_entry(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->sole();

        $this->actingAs($doctor)
            ->put($this->url($orientation, $item), ['result_value' => '<p>Trop tôt</p>'])
            ->assertSessionHasErrors('result_value');

        $this->assertNull($item->fresh()->resulted_at);
    }

    /** Écrire un compte rendu et revenir sur un document signé ne sont pas le même droit. */
    public function test_correcting_needs_its_own_permission(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Signé</p>');
        $doctor->role->permissions()->detach(Permission::query()->where('name', 'imaging_results.update')->value('id'));

        $this->actingAs($doctor->fresh())
            ->put($this->url($orientation, $item), ['result_value' => '<p>Modifié</p>'])
            ->assertForbidden();

        $this->assertSame('<p>Signé</p>', $item->fresh()->result_value);
    }

    public function test_the_first_entry_stays_unique_and_is_not_a_correction(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>Premier</p>');

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), ['result_value' => '<p>Second</p>'])
            ->assertSessionHasErrors('result_value');

        $this->assertSame('<p>Premier</p>', $item->fresh()->result_value);
        $this->assertSame(0, ImagingResultRevision::query()->count());
    }

    /** L'examen d'un autre passage ne s'atteint pas avec un UUID valide. */
    public function test_an_exam_of_another_passage_cannot_be_corrected_from_this_one(): void
    {
        [$doctor, , $item] = $this->recorded('<p>À moi</p>');
        [, $otherOrientation] = $this->passage($doctor);

        $this->actingAs($doctor)
            ->put($this->url($otherOrientation, $item), ['result_value' => '<p>Détourné</p>'])
            ->assertForbidden();

        $this->assertSame('<p>À moi</p>', $item->fresh()->result_value);
    }

    public function test_a_replaced_version_can_never_be_rewritten_or_deleted(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>V1</p>');
        $this->actingAs($doctor)->put($this->url($orientation, $item), ['result_value' => '<p>V2</p>']);
        $revision = ImagingResultRevision::query()->sole();

        $this->expectException(LogicException::class);
        $revision->update(['result_value' => '<p>Réécrit</p>']);
    }

    public function test_the_directory_offers_the_correction_and_serves_the_history(): void
    {
        [$doctor, $orientation, $item] = $this->recorded('<p>V1</p>');
        $this->actingAs($doctor)->put($this->url($orientation, $item), ['result_value' => '<p>V2</p>', 'reason' => 'Précision']);

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=recent')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('requests.0.items.0.can_correct', true)
                ->where('requests.0.items.0.can_record', false)
                ->where('requests.0.items.0.report_raw', '<p>V2</p>')
                ->where('requests.0.items.0.corrected_by', $doctor->name)
                ->has('requests.0.items.0.revisions', 1)
                ->where('requests.0.items.0.revisions.0.reason', 'Précision'));
    }

    public function test_the_correction_is_not_offered_without_the_right(): void
    {
        [$doctor] = $this->recorded('<p>V1</p>');
        $doctor->role->permissions()->detach(Permission::query()->where('name', 'imaging_results.update')->value('id'));

        $this->actingAs($doctor->fresh())
            ->get('/medicine/demandes-examens?filter=recent')
            ->assertInertia(fn ($page) => $page->where('requests.0.items.0.can_correct', false));
    }

    /** @return array{0: User, 1: EpisodeOrientation, 2: ImagingRequestItem} */
    private function recorded(string $report, ?string $notes = null): array
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);
        $this->imagingRequest($orientation, $doctor);
        $item = ImagingRequestItem::query()->latest('id')->firstOrFail();

        $this->actingAs($doctor)
            ->post($this->url($orientation, $item), ['result_value' => $report, 'result_notes' => $notes])
            ->assertSessionHasNoErrors();

        return [$doctor, $orientation, $item->fresh()];
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
            'imaging_orders.create', 'imaging_orders.view', 'imaging_results.create', 'imaging_results.update',
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
