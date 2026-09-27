<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeServiceRequest;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecord;
use App\Models\MaternityRecordDraft;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\MaternityActProfile;
use App\Support\MaternityReference;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-136 — le dossier Maternité s'adapte à l'acte demandé à la Réception :
 * sections mises en avant, actes prévus en un clic, saisie conservée. Rien
 * n'est verrouillé, aucun champ clinique n'est ajouté ni exigé par acte.
 */
class MaternityActWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_profile_puts_forward_the_sections_an_act_expects(): void
    {
        $delivery = MaternityActProfile::forCodes(['MAT-DELIVERY-SIMPLE']);
        $this->assertSame(['context', 'labor', 'delivery', 'newborn', 'procedures'], $delivery['sections']);
        $this->assertSame(1, $delivery['expected_newborns']);

        // Les sections suivent l'ordre du chevet, quel que soit l'ordre des actes.
        $mixed = MaternityActProfile::forCodes(['MAT-BABY-CARE', 'MAT-CONSULT-PRENATAL']);
        $this->assertSame(['context', 'prenatal', 'newborn', 'procedures'], $mixed['sections']);

        $this->assertSame(2, MaternityActProfile::forCodes(['MAT-DELIVERY-TWIN'])['expected_newborns']);
    }

    /** « Nursie », « IEC », un acte ajouté depuis : rien n'est deviné, seuls les actes sont mis en avant. */
    public function test_an_act_without_a_profile_only_puts_the_procedures_forward(): void
    {
        foreach (['MAT-NURSIE', 'MAT-IEC', 'MAT-OTHER', 'MAT-UNKNOWN-FUTURE'] as $code) {
            $this->assertSame(
                ['sections' => ['procedures'], 'expected_newborns' => null],
                MaternityActProfile::forCodes([$code]),
                $code,
            );
        }

        // Aucun acte demandé : rien n'est mis en avant, le dossier s'ouvre comme avant.
        $this->assertSame(['sections' => [], 'expected_newborns' => null], MaternityActProfile::forCodes([]));
    }

    public function test_the_page_serves_what_reception_requested_and_what_is_already_recorded(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $baby = $this->catalogItem($midwife, 'MAT-BABY-CARE', 'Soins bébé');
        $iud = $this->catalogItem($midwife, 'MAT-IUD-INSERT', 'Insertion DIU');
        $this->plan($episode, $midwife, $baby);
        $this->plan($episode, $midwife, $iud, quantity: 2);

        // Une césarienne demandée n'est jamais un acte à enregistrer ici.
        $this->plan($episode, $midwife, $this->catalogItem($midwife, 'MAT-CESAREAN-SIMPLE', 'Opération Césarienne Simple'));

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/procedures", [
            'catalog_item_uuid' => $baby->uuid, 'quantity' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->component('Maternity/Show')
                ->has('plannedProcedures', 2)
                ->where('plannedProcedures.0.code', 'MAT-BABY-CARE')
                ->where('plannedProcedures.0.done', true)
                ->where('plannedProcedures.1.code', 'MAT-IUD-INSERT')
                ->where('plannedProcedures.1.done', false)
                ->where('plannedProcedures.1.quantity', 2)
                ->where('actProfile.sections', ['newborn', 'procedures'])
                ->missing('plannedProcedures.2'));
    }

    public function test_a_twin_delivery_announces_two_newborns_without_filling_them(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $this->plan($episode, $midwife, $this->catalogItem($midwife, 'MAT-DELIVERY-TWIN', 'Accouchement gémellaire'));

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('actProfile.expected_newborns', 2)
                ->where('record', null));
    }

    public function test_the_catalogue_marks_the_act_that_needs_a_note(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $this->catalogItem($midwife, MaternityActProfile::OTHER_CODE, 'Autres');
        $this->catalogItem($midwife, 'MAT-IEC', 'IEC');

        $catalog = collect($this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->viewData('page')['props']['procedureCatalog'])->keyBy('code');

        $this->assertTrue($catalog['MAT-OTHER']['requires_note']);
        $this->assertFalse($catalog['MAT-IEC']['requires_note']);
    }

    /** « Autre acte de maternité, à préciser » : sans précision, il ne dit rien à personne. */
    public function test_the_other_act_cannot_be_recorded_without_its_description(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $other = $this->catalogItem($midwife, MaternityActProfile::OTHER_CODE, 'Autres');

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/procedures", [
            'catalog_item_uuid' => $other->uuid, 'quantity' => 1, 'notes' => '   ',
        ])->assertSessionHasErrors('notes');
        $this->assertSame(0, MaternityProcedure::query()->count());

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/procedures", [
            'catalog_item_uuid' => $other->uuid, 'quantity' => 1, 'notes' => 'Massage périnéal',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, MaternityProcedure::query()->count());
    }

    public function test_a_draft_survives_a_reload_and_is_restored_to_its_author_only(): void
    {
        $midwife = $this->midwife();
        $colleague = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);

        $this->actingAs($midwife)->putJson("/maternity/orientations/{$orientation->uuid}/draft", [
            'payload' => [
                // Volontairement incomplet : c'est précisément ce qui doit survivre.
                'record' => ['pregnancy_data' => ['gravidity' => '3', 'parity' => ''], 'obstetric_context' => 'Douleurs'],
                'unexpected' => ['ignored' => true],
            ],
        ])->assertOk()->assertJsonStructure(['saved_at']);

        $draft = MaternityRecordDraft::query()->sole();
        $this->assertSame($midwife->id, $draft->created_by);
        $this->assertSame('3', $draft->payload['record']['pregnancy_data']['gravidity']);
        $this->assertSame('', $draft->payload['record']['pregnancy_data']['parity'], 'un champ vide reste une chaîne vide');
        $this->assertArrayNotHasKey('unexpected', $draft->payload);

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page->where('recordDraft.payload.record.obstetric_context', 'Douleurs'));

        // Un poste partagé ne donne jamais à une collègue la saisie non validée d'une autre.
        $this->actingAs($colleague)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page->where('recordDraft', null));
    }

    public function test_a_real_save_and_an_explicit_discard_both_remove_the_draft(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);

        $this->actingAs($midwife)->putJson("/maternity/orientations/{$orientation->uuid}/draft", [
            'payload' => ['record' => ['obstetric_context' => 'Brouillon']],
        ])->assertOk();
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'pregnancy_choice' => 'CREATE',
            'obstetric_context' => 'Dossier enregistré',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, MaternityRecordDraft::query()->count());

        $this->actingAs($midwife)->putJson("/maternity/orientations/{$orientation->uuid}/draft", [
            'payload' => ['record' => ['obstetric_context' => 'Autre brouillon']],
        ])->assertOk();
        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/draft")->assertRedirect();
        $this->assertSame(0, MaternityRecordDraft::query()->count());
    }

    public function test_finishing_the_care_discards_a_draft_that_was_never_saved(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        MaternityRecord::query()->create([
            'episode_id' => $orientation->episode_id, 'episode_orientation_id' => $orientation->id,
            'created_by' => $midwife->id, 'updated_by' => $midwife->id,
        ]);
        MaternityRecordDraft::query()->create([
            'episode_orientation_id' => $orientation->id, 'created_by' => $midwife->id, 'payload' => ['record' => ['observations' => 'x']],
        ]);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();

        $this->assertSame(0, MaternityRecordDraft::query()->count());
    }

    public function test_a_draft_is_refused_once_the_care_is_no_longer_in_progress(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $orientation->forceFill(['status' => EpisodeOrientationStatus::Completed])->save();

        $this->actingAs($midwife)->putJson("/maternity/orientations/{$orientation->uuid}/draft", [
            'payload' => ['record' => ['observations' => 'trop tard']],
        ])->assertForbidden();
        $this->assertSame(0, MaternityRecordDraft::query()->count());
    }

    /** ADR-140 : le personnel Maternité corrige la quantité et la précision d'un acte enregistré. */
    public function test_a_recorded_act_can_be_corrected_and_the_correction_is_traced(): void
    {
        $midwife = $this->midwife();
        $colleague = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $procedure = $this->recordAct($midwife, $orientation, 'MAT-BABY-CARE', 'Soins bébé');

        $this->actingAs($colleague)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}", [
            'quantity' => 3, 'notes' => 'Bain et pesée',
        ])->assertSessionHasNoErrors();

        $procedure->refresh();
        $this->assertSame('3.00', $procedure->quantity);
        $this->assertSame('Bain et pesée', $procedure->notes);
        $this->assertSame($colleague->id, $procedure->edited_by, 'la correction est attribuée à qui l\'a faite');
        $this->assertSame($midwife->id, $procedure->performed_by, 'l\'auteur d\'origine ne change pas');
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'entity_type' => MaternityProcedure::class, 'entity_id' => $procedure->id, 'user_id' => $colleague->id]);
    }

    public function test_the_other_act_keeps_its_description_when_corrected(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $procedure = $this->recordAct($midwife, $orientation, MaternityActProfile::OTHER_CODE, 'Autres', 'Massage');

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}", ['quantity' => 1, 'notes' => ' '])
            ->assertSessionHasErrors('notes');
        $this->assertSame('Massage', $procedure->fresh()->notes);
    }

    /** Retirer ne détruit rien : l'acte quitte la liste, avec son auteur et son motif (ADR-009). */
    public function test_removing_an_act_hides_it_without_destroying_it(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $baby = $this->catalogItem($midwife, 'MAT-BABY-CARE', 'Soins bébé');
        $this->plan($episode, $midwife, $baby);
        $procedure = $this->recordAct($midwife, $orientation, 'MAT-BABY-CARE', 'Soins bébé');

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page->has('record.procedures', 1)->where('plannedProcedures.0.done', true));

        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}")
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('maternity_procedures', ['id' => $procedure->id]);
        $row = MaternityProcedure::withTrashed()->findOrFail($procedure->id);
        $this->assertSame($midwife->id, $row->deleted_by);
        $this->assertSame('Acte retiré depuis le dossier Maternité', $row->delete_reason);

        // Il quitte la liste, et l'acte demandé à la Réception redevient à enregistrer.
        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page->has('record.procedures', 0)->where('plannedProcedures.0.done', false));
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete', 'entity_type' => MaternityProcedure::class, 'entity_id' => $procedure->id]);
    }

    /** L'acte d'un médecin reste intact pour le personnel Maternité ; son auteur peut toujours le corriger. */
    public function test_an_act_recorded_by_a_physician_stays_intact_for_the_midwives(): void
    {
        $midwife = $this->midwife();
        $doctor = $this->doctor();
        [, $orientation] = $this->inProgress($midwife);
        $byDoctor = $this->recordAct($doctor, $orientation, 'MAT-BABY-CARE', 'Soins bébé');
        $byMidwife = $this->recordAct($midwife, $orientation, 'MAT-IEC', 'IEC');
        $this->assertSame('MEDICINE', $byDoctor->performed_by_role);
        $this->assertSame('NURSE', $byMidwife->performed_by_role);

        // La sage-femme ne peut ni corriger ni retirer l'acte du médecin, même en forçant l'adresse.
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$byDoctor->uuid}", ['quantity' => 5])
            ->assertSessionHasErrors('procedure');
        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/procedures/{$byDoctor->uuid}")
            ->assertSessionHasErrors('procedure');
        $this->assertSame('1.00', $byDoctor->fresh()->quantity);
        $this->assertNull($byDoctor->fresh()->deleted_at);

        // Ce que l'écran montre : le sien est modifiable, celui du médecin est verrouillé.
        $procedures = collect($this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->viewData('page')['props']['record']['procedures'])->keyBy('procedure_code');
        $this->assertTrue($procedures['MAT-IEC']['can_modify']);
        $this->assertFalse($procedures['MAT-BABY-CARE']['can_modify']);
        $this->assertTrue($procedures['MAT-BABY-CARE']['locked_by_physician']);

        // Le médecin, lui, garde la main sur ce qu'il a enregistré.
        $this->actingAs($doctor)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$byDoctor->uuid}", ['quantity' => 2])
            ->assertSessionHasNoErrors();
        $this->assertSame('2.00', $byDoctor->fresh()->quantity);

        // Et la sage-femme corrige librement l'acte d'une collègue.
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$byMidwife->uuid}", ['quantity' => 2])
            ->assertSessionHasNoErrors();
    }

    /** Le verrou lit le rôle d'alors : changer de rôle ne déverrouille rien. */
    public function test_the_lock_follows_the_role_at_recording_not_the_current_one(): void
    {
        $midwife = $this->midwife();
        $doctor = $this->doctor();
        [, $orientation] = $this->inProgress($midwife);
        $byDoctor = $this->recordAct($doctor, $orientation, 'MAT-BABY-CARE', 'Soins bébé');

        $doctor->forceFill(['role_id' => Role::query()->where('code', 'NURSE')->value('id')])->save();

        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/procedures/{$byDoctor->uuid}")
            ->assertSessionHasErrors('procedure');
    }

    public function test_an_act_cannot_be_changed_once_the_care_is_over_or_from_another_episode(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        [, $other] = $this->inProgress($midwife);
        $procedure = $this->recordAct($midwife, $orientation, 'MAT-BABY-CARE', 'Soins bébé');

        // L'acte d'un autre passage n'est pas celui-ci, quel que soit l'identifiant envoyé.
        $this->actingAs($midwife)->delete("/maternity/orientations/{$other->uuid}/procedures/{$procedure->uuid}")->assertNotFound();

        $orientation->forceFill(['status' => EpisodeOrientationStatus::Completed])->save();
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}", ['quantity' => 4])
            ->assertSessionHasErrors('procedure');
        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page->where('record.procedures.0.can_modify', false));
        $this->assertSame('1.00', $procedure->fresh()->quantity);
    }

    /** ADR-139 : avec des jumeaux, chaque bébé a ses propres soins ; les soins de la mère restent uniques. */
    public function test_each_newborn_keeps_its_own_care_notes_while_the_mother_keeps_one(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'pregnancy_choice' => 'CREATE',
            'maternal_care_notes' => 'Surveillance des saignements',
            'newborn_data' => ['newborns' => [
                ['sex' => 'F', 'birth_weight_g' => 2400, 'care_notes' => 'Photothérapie'],
                ['sex' => 'M', 'birth_weight_g' => 2900, 'care_notes' => 'Allaitement, aucun soin particulier'],
            ]],
        ])->assertSessionHasNoErrors();

        $record = MaternityRecord::query()->where('episode_id', $episode->id)->sole();
        $this->assertSame('Surveillance des saignements', $record->maternal_care_notes);
        $this->assertSame('Photothérapie', $record->newborn_data['newborns'][0]['care_notes']);
        $this->assertSame('Allaitement, aucun soin particulier', $record->newborn_data['newborns'][1]['care_notes']);

        // Le dossier est resservi tel quel : chaque bébé retrouve ses soins.
        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('record.newborn_data.newborns.0.care_notes', 'Photothérapie')
                ->where('record.newborn_data.newborns.1.care_notes', 'Allaitement, aucun soin particulier'));
    }

    public function test_a_legacy_shared_baby_care_note_is_kept_untouched(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        MaternityRecord::query()->create([
            'episode_id' => $episode->id, 'episode_orientation_id' => $orientation->id,
            'baby_care_notes' => 'Ancienne note commune', 'created_by' => $midwife->id, 'updated_by' => $midwife->id,
        ]);

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'pregnancy_choice' => 'CREATE',
            'newborn_data' => ['newborns' => [['care_notes' => 'Soins du premier bébé']]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ancienne note commune', MaternityRecord::query()->sole()->baby_care_notes);
    }

    /** ADR-138 : un panier d'actes s'enregistre d'un seul geste. */
    public function test_a_basket_of_acts_is_recorded_in_one_go(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $baby = $this->catalogItem($midwife, 'MAT-BABY-CARE', 'Soins bébé');
        $iec = $this->catalogItem($midwife, 'MAT-IEC', 'IEC');

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/procedures/batch", [
            'procedures' => [
                ['catalog_item_uuid' => $baby->uuid, 'quantity' => 2, 'notes' => 'Bain'],
                ['catalog_item_uuid' => $iec->uuid, 'quantity' => 1, 'notes' => null],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', '2 actes Maternité enregistrés.');

        $this->assertSame(['MAT-BABY-CARE', 'MAT-IEC'], MaternityProcedure::query()->orderBy('id')->pluck('procedure_code')->all());
        $this->assertSame('2.00', MaternityProcedure::query()->where('procedure_code', 'MAT-BABY-CARE')->value('quantity'));
        $this->assertSame($midwife->id, MaternityProcedure::query()->first()->performed_by);
    }

    /** Une seule ligne refusée : rien n'est enregistré, et le refus nomme la ligne. */
    public function test_one_refused_line_records_nothing_and_names_the_line(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $baby = $this->catalogItem($midwife, 'MAT-BABY-CARE', 'Soins bébé');
        $other = $this->catalogItem($midwife, MaternityActProfile::OTHER_CODE, 'Autres');

        $response = $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/procedures/batch", [
            'procedures' => [
                ['catalog_item_uuid' => $baby->uuid, 'quantity' => 1],
                ['catalog_item_uuid' => $other->uuid, 'quantity' => 1, 'notes' => ' '],
            ],
        ]);

        $response->assertSessionHasErrors('procedures.1.notes');
        $this->assertStringContainsString('Autres', session('errors')->first('procedures.1.notes'));
        $this->assertSame(0, MaternityProcedure::query()->count(), 'la première ligne, valide, ne reste pas seule');
    }

    public function test_a_basket_refuses_an_empty_list_a_duplicate_act_and_a_cesarean(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $baby = $this->catalogItem($midwife, 'MAT-BABY-CARE', 'Soins bébé');
        $cesarean = $this->catalogItem($midwife, 'MAT-CESAREAN-SIMPLE', 'Opération Césarienne Simple');
        $url = "/maternity/orientations/{$orientation->uuid}/procedures/batch";

        $this->actingAs($midwife)->post($url, ['procedures' => []])->assertSessionHasErrors('procedures');
        $this->actingAs($midwife)->post($url, ['procedures' => [
            ['catalog_item_uuid' => $baby->uuid, 'quantity' => 1],
            ['catalog_item_uuid' => $baby->uuid, 'quantity' => 1],
        ]])->assertSessionHasErrors();
        // Une césarienne part à Chirurgie (ADR-067) : elle ne s'enregistre jamais comme acte.
        $this->actingAs($midwife)->post($url, ['procedures' => [
            ['catalog_item_uuid' => $cesarean->uuid, 'quantity' => 1],
        ]])->assertSessionHasErrors('procedures.0.catalog_item_uuid');

        $this->assertSame(0, MaternityProcedure::query()->count());
    }

    public function test_recording_the_basket_forgets_only_its_own_draft_section(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $baby = $this->catalogItem($midwife, 'MAT-BABY-CARE', 'Soins bébé');

        $this->actingAs($midwife)->putJson("/maternity/orientations/{$orientation->uuid}/draft", ['payload' => [
            'record' => ['observations' => 'À garder'],
            'basket' => ['lines' => [['catalog_item_uuid' => $baby->uuid, 'quantity' => 1, 'notes' => '']]],
        ]])->assertOk();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/procedures/batch", [
            'procedures' => [['catalog_item_uuid' => $baby->uuid, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        // Le panier enregistré ne ressort pas ; le dossier encore en saisie est intact.
        $draft = MaternityRecordDraft::query()->sole();
        $this->assertArrayNotHasKey('basket', $draft->payload);
        $this->assertSame('À garder', $draft->payload['record']['observations']);

        // Un brouillon réduit à rien est supprimé.
        MaternityRecordDraft::forgetSection($orientation->id, $midwife->id, 'record');
        $this->assertSame(0, MaternityRecordDraft::query()->count());
    }

    /** ADR-137 : les repères viennent du serveur, jamais recopiés dans l'écran. */
    public function test_the_page_serves_the_reference_the_screen_reads_its_hints_from(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('maternityReference.birth_weight.min', MaternityReference::BIRTH_WEIGHT_MIN_G)
                ->where('maternityReference.birth_weight.max', MaternityReference::BIRTH_WEIGHT_MAX_G)
                ->where('maternityReference.birth_weight.low', 2500)
                ->where('maternityReference.apgar.max', 10)
                ->where('maternityReference.pregnancy.term_days', 280)
                ->has('maternityReference.fetal_heart_rate'));
    }

    /** Un repère affiché à l'écran et une borne refusée à l'enregistrement sont le même chiffre. */
    public function test_the_bounds_the_screen_warns_about_are_the_ones_the_server_refuses(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $url = "/maternity/orientations/{$orientation->uuid}/record";

        foreach ([MaternityReference::BIRTH_WEIGHT_MIN_G - 1, MaternityReference::BIRTH_WEIGHT_MAX_G + 1] as $grams) {
            $this->actingAs($midwife)->put($url, ['newborn_data' => ['newborns' => [['birth_weight_g' => $grams]]]])
                ->assertSessionHasErrors('newborn_data.newborns.0.birth_weight_g');
        }

        // Un poids de naissance atypique mais possible n'est jamais refusé : le message est une aide.
        $this->actingAs($midwife)->put($url, ['pregnancy_choice' => 'CREATE', 'newborn_data' => ['newborns' => [['birth_weight_g' => 900, 'apgar' => 2]]]])
            ->assertSessionHasNoErrors();
    }

    /** Un acte enregistré par l'endpoint réel, sous l'identité donnée. */
    private function recordAct(User $actor, EpisodeOrientation $orientation, string $code, string $name, ?string $notes = null): MaternityProcedure
    {
        $item = CatalogItem::query()->where('code', $code)->first() ?? $this->catalogItem($actor, $code, $name);
        $actor->permissions()->syncWithoutDetaching(Permission::query()
            ->whereIn('name', ['maternity.view', 'maternity.create', 'maternity.update', 'maternity.procedures.manage'])
            ->get()->mapWithKeys(fn (Permission $permission) => [$permission->id => ['effect' => 'allow']])->all());

        $this->actingAs($actor->fresh())->post("/maternity/orientations/{$orientation->uuid}/procedures", [
            'catalog_item_uuid' => $item->uuid, 'quantity' => 1, 'notes' => $notes,
        ])->assertSessionHasNoErrors();

        return MaternityProcedure::query()->where('procedure_code', $code)->latest('id')->firstOrFail();
    }

    private function doctor(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', 'MEDICINE')->value('id')]);
    }

    /** @return array{Episode, EpisodeOrientation} */
    private function inProgress(User $midwife): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'birth_date' => '1996-05-12', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $midwife);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Reception, CatalogModule::Maternity, $midwife, 'Suivi obstétrical',
        );
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept");

        return [$episode, $orientation->fresh()];
    }

    private function plan(Episode $episode, User $actor, CatalogItem $item, float $quantity = 1): EpisodeServiceRequest
    {
        return EpisodeServiceRequest::query()->create([
            'episode_id' => $episode->id,
            'catalog_item_id' => $item->id,
            'catalog_item_uuid' => $item->uuid,
            'catalog_code' => $item->code,
            'designation' => $item->name,
            'module' => CatalogModule::Maternity,
            'routing_mode' => ReceptionRoutingMode::MaternityDirect,
            'unit' => 'acte',
            'unit_price' => 0,
            'quantity' => $quantity,
            'created_by' => $actor->id,
        ]);
    }

    private function catalogItem(User $actor, string $code, string $name): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code, 'name' => $name, 'type' => CatalogItemType::Service,
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'reception_selectable' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function midwife(): User
    {
        $profile = ProfessionalProfile::query()->where('code', 'MIDWIFE')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
            'professional_profile_id' => $profile->id,
        ]);
        $user->permissions()->syncWithoutDetaching($profile->recommendedPermissions->mapWithKeys(
            fn (Permission $permission) => [$permission->id => ['effect' => 'allow']],
        )->all());

        return $user->fresh(['role', 'professionalProfile']);
    }
}
