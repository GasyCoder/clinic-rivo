<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeStatus;
use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\PatientNewbornLink;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-144, ADR-146 — un nouveau-né consigné en Maternité vit dans le dossier de sa mère, puis devient un
 * patient relié à elle quand la Réception l'accueille.
 *
 * Numéro dérivé de celui de la mère, naissance jamais devinée, jumeaux acceptés (même nom, même date),
 * geste idempotent, aucun passage ouvert, et les soins du bébé restent sur le compte de la mère.
 */
class NewbornPatientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_newborn_gets_a_patient_whose_number_derives_from_the_mothers(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, $orientation] = $this->twinsRecord($midwife);

        $this->createDossier($midwife, $orientation, 0, 'Rasoa', 'Faly')->assertOk();

        $baby = Patient::query()->where('patient_number', "{$mother->patient_number}-B1")->firstOrFail();
        $this->assertSame('Rasoa', $baby->last_name);
        $this->assertSame('Faly', $baby->first_name);
        // La naissance est celle de l'accouchement consigné, jamais « aujourd'hui ».
        $this->assertSame('2026-09-20', $baby->birth_date->toDateString());
        $this->assertFalse($baby->birth_date_is_approximate);
        $this->assertSame('F', $baby->sex->value);

        $link = PatientNewbornLink::query()->sole();
        $this->assertSame($baby->id, $link->patient_id);
        $this->assertSame($mother->id, $link->mother_patient_id);
        $this->assertSame(1, $link->birth_rank);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maternity.newborn.patient.create']);
    }

    /** Aucun passage : un dossier n'entre dans aucune file tant que le bébé n'a besoin d'aucun service. */
    public function test_no_episode_is_opened_and_the_babys_care_stays_on_the_mothers_account(): void
    {
        $midwife = $this->midwife();
        [, $episode, $orientation] = $this->twinsRecord($midwife);
        $before = BillableItem::query()->count();

        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();

        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $this->assertSame(0, Episode::query()->where('patient_id', $baby->id)->count());
        // Rien n'est facturé au nom du bébé : ses soins restent sur le compte de sa mère.
        $this->assertSame($before, BillableItem::query()->count());
        $this->assertSame(0, BillableItem::query()->whereHas('episode', fn ($q) => $q->where('patient_id', $baby->id))->count());
    }

    /** Avec des jumeaux, même nom et même date de naissance : la détection de doublons ne doit rien bloquer. */
    public function test_twins_each_get_their_own_patient_in_birth_order(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);

        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $this->createDossier($midwife, $orientation, 1, 'Rasoa')->assertOk();

        $numbers = Patient::query()->whereHas('newbornLink')->orderBy('patient_number')->pluck('patient_number')->all();
        $this->assertSame(["{$mother->patient_number}-B1", "{$mother->patient_number}-B2"], $numbers);
        $this->assertSame(2, PatientNewbornLink::query()->count());
    }

    public function test_the_second_twin_keeps_its_birth_rank_even_when_its_dossier_is_created_first(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);

        $this->createDossier($midwife, $orientation, 1, 'Rasoa')->assertOk();

        $this->assertDatabaseHas('patients', ['patient_number' => "{$mother->patient_number}-B2"]);
    }

    public function test_a_number_already_taken_is_never_reused_even_by_an_archived_patient(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $taken = Patient::query()->create([
            'patient_number' => "{$mother->patient_number}-B1", 'first_name' => 'Ancien', 'last_name' => 'Dossier',
            'birth_date' => '2020-01-01', 'sex' => 'M',
        ]);
        $taken->delete();

        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();

        $this->assertDatabaseHas('patients', ['patient_number' => "{$mother->patient_number}-B2"]);
    }

    public function test_a_double_click_never_creates_a_second_patient_for_the_same_baby(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife);

        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();

        $this->assertSame(1, PatientNewbornLink::query()->count());
        $this->assertSame(1, Patient::query()->whereHas('newbornLink')->count());
    }

    public function test_the_baby_may_have_no_first_name_and_its_family_name_is_the_midwifes_choice(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);

        $this->createDossier($midwife, $orientation, 0, 'Andrianina')->assertOk();

        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $this->assertNull($baby->first_name);
        $this->assertNotSame($mother->last_name, $baby->last_name);
    }

    public function test_the_babys_first_passage_is_numbered_from_its_own_number(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();

        $episode = app(CreateEpisodeAction::class)->execute($baby, actor: $midwife);

        $this->assertSame("{$mother->patient_number}-B1-01", $episode->episode_number);
    }

    // ── Refus ────────────────────────────────────────────────────────────

    public function test_the_birth_is_never_guessed_so_an_unrecorded_delivery_is_refused(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife, delivery: []);

        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertJsonValidationErrors('newborn');

        $this->assertSame(0, Patient::query()->whereHas('newbornLink')->count());
    }

    /** Le dossier patient n'a pas d'état « indéterminé » : il faut le dire plutôt que d'en inventer un. */
    public function test_an_undetermined_sex_is_refused_with_a_clear_message(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife, newborns: [['sex' => 'UNDETERMINED', 'birth_weight_g' => 3000]]);

        $response = $this->createDossier($midwife, $orientation, 0, 'Rasoa');

        $response->assertJsonValidationErrors('sex');
        $this->assertStringContainsString('sexe', $response->json('errors.sex.0'));
        $this->assertSame(0, PatientNewbornLink::query()->count());
    }

    /**
     * La fiche n'a pas toujours le sexe : la Réception le donne en accueillant le bébé, et il se retrouve sur la
     * fiche — le dossier du bébé et celui de sa mère ne se contredisent jamais.
     */
    public function test_the_sex_can_be_chosen_at_the_reception_and_is_written_on_the_fiche(): void
    {
        $midwife = $this->midwife();
        [, , $orientation, $record] = $this->twinsRecord($midwife, newborns: [['sex' => '', 'birth_weight_g' => 3000]]);

        $this->createDossier($midwife, $orientation, 0, 'Rasoa', sex: 'F')->assertOk();

        $this->assertSame('F', Patient::query()->whereHas('newbornLink')->sole()->sex->value);
        $this->assertSame('F', $record->fresh()->newborn_data['newborns'][0]['sex']);
    }

    public function test_the_fiche_sex_wins_over_a_submitted_one(): void
    {
        $midwife = $this->midwife();
        [, , $orientation, $record] = $this->twinsRecord($midwife);

        $this->createDossier($midwife, $orientation, 0, 'Rasoa', sex: 'M')->assertOk();

        // La fiche disait Féminin : un navigateur ne la contredit pas.
        $this->assertSame('F', Patient::query()->whereHas('newbornLink')->sole()->sex->value);
        $this->assertSame('F', $record->fresh()->newborn_data['newborns'][0]['sex']);
    }

    /** Le dossier Maternité est en lecture seule après la fin de prise en charge : la création reste possible. */
    public function test_a_dossier_can_be_created_after_the_maternity_care_is_over(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife);
        $orientation->forceFill(['status' => \App\Enums\EpisodeOrientationStatus::Completed])->save();

        $this->createDossier($midwife, $orientation, 1, 'Rasoa')->assertOk();

        $this->assertSame(1, PatientNewbornLink::query()->count());
    }

    public function test_an_unknown_baby_a_missing_sex_and_an_empty_fiche_are_refused(): void
    {
        $midwife = $this->midwife();
        [, , $orientation, $record] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 3000],
            ['sex' => '', 'birth_weight_g' => 3200],
            ['sex' => '', 'birth_weight_g' => '', 'apgar' => '', 'condition' => '', 'care_notes' => ''],
        ]);

        // Un bébé consigné sans sexe : c'est le sexe qui manque.
        $this->createDossier($midwife, $orientation, 1, 'Rasoa')->assertJsonValidationErrors('sex');
        // Une fiche jamais remplie n'est pas un bébé consigné : elle n'a pas d'identité.
        $this->assertArrayNotHasKey('uuid', $record->fresh()->newborn_data['newborns'][2]);
        // Un bébé qui n'est pas dans ce dossier.
        $this->createDossier($midwife, $orientation, 5, 'Rasoa')->assertJsonValidationErrors('newborn');
        $this->assertSame(0, Patient::query()->whereHas('newbornLink')->count());
    }

    /** Le nom de la fiche, à défaut celui de la mère : la Réception n'a rien à saisir pour ouvrir le dossier. */
    public function test_the_name_falls_back_from_the_reception_to_the_fiche_then_to_the_mother(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900, 'first_name' => 'Faly', 'last_name' => 'Andrianina'],
            ['sex' => 'M', 'birth_weight_g' => 3100],
        ]);

        $this->createDossier($midwife, $orientation, 0)->assertOk();
        $this->createDossier($midwife, $orientation, 1)->assertOk();

        $first = Patient::query()->where('patient_number', "{$mother->patient_number}-B1")->firstOrFail();
        $second = Patient::query()->where('patient_number', "{$mother->patient_number}-B2")->firstOrFail();
        $this->assertSame(['Andrianina', 'Faly'], [$first->last_name, $first->first_name]);
        $this->assertSame([$mother->last_name, null], [$second->last_name, $second->first_name]);
    }

    public function test_making_the_baby_a_patient_needs_the_reception_and_patient_creation_rights(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife);

        // La sage-femme n'ouvre plus de dossier patient : c'est l'accueil (ADR-146).
        $this->createDossier($midwife, $orientation, 0, 'Rasoa', as: $midwife)->assertForbidden();
        $this->createDossier($midwife, $orientation, 0, 'Rasoa', as: $this->userWithPermissions(['episodes.create']))->assertForbidden();
        $this->createDossier($midwife, $orientation, 0, 'Rasoa', as: $this->userWithPermissions(['patients.create']))->assertForbidden();

        $this->assertSame(0, PatientNewbornLink::query()->count());
    }

    /** Le bébé revient des jours après : le passage de sa mère est clos, et cela n'empêche rien. */
    public function test_a_closed_passage_of_the_mother_does_not_prevent_the_baby_from_coming_back(): void
    {
        $midwife = $this->midwife();
        [, $episode, $orientation] = $this->twinsRecord($midwife);
        $episode->forceFill(['status' => EpisodeStatus::Closed])->save();

        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();

        $this->assertSame(1, PatientNewbornLink::query()->count());
        // Rien n'est touché au passage de la mère.
        $this->assertSame(EpisodeStatus::Closed, $episode->fresh()->status);
    }

    // ── Garde à l'enregistrement du dossier Maternité ────────────────────

    public function test_a_baby_with_a_dossier_cannot_be_removed_from_the_maternity_record(): void
    {
        $midwife = $this->midwife();
        [, , $orientation, $record] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $stored = $record->fresh()->newborn_data['newborns'];

        // Le navigateur renvoie uniquement le second bébé : le premier a pourtant son dossier.
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'newborn_data' => ['newborns' => [$stored[1]]],
        ])->assertSessionHasErrors('newborn_data');

        $this->assertCount(2, $record->fresh()->newborn_data['newborns']);
    }

    public function test_the_identity_is_given_at_save_travels_with_the_fiche_and_a_browser_cannot_invent_one(): void
    {
        $midwife = $this->midwife();
        [, , $orientation, $record] = $this->twinsRecord($midwife);
        $stored = $record->fresh()->newborn_data['newborns'];
        // Chaque bébé consigné a son identité dès l'enregistrement — avant tout dossier patient (ADR-146).
        $this->assertNotEmpty($stored[0]['uuid']);
        $this->assertNotEmpty($stored[1]['uuid']);
        $this->assertNotSame($stored[0]['uuid'], $stored[1]['uuid']);

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'newborn_data' => ['newborns' => [
                [...$stored[0], 'condition' => 'Corrigé'],
                // Une identité jamais donnée par le serveur : remplacée, jamais reprise.
                [...$stored[1], 'uuid' => '11111111-1111-4111-8111-111111111111'],
            ]],
        ])->assertSessionHasNoErrors();

        $saved = $record->fresh()->newborn_data['newborns'];
        $this->assertSame($stored[0]['uuid'], $saved[0]['uuid']);
        $this->assertSame('Corrigé', $saved[0]['condition']);
        $this->assertNotSame('11111111-1111-4111-8111-111111111111', $saved[1]['uuid']);
        $this->assertNotEmpty($saved[1]['uuid']);
    }

    /** Une fiche ouverte d'office pour des jumeaux n'est pas un bébé consigné : elle n'a pas d'identité. */
    public function test_an_empty_fiche_has_no_identity_until_something_is_recorded(): void
    {
        $midwife = $this->midwife();
        [, , $orientation, $record] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900],
            ['sex' => '', 'birth_weight_g' => '', 'first_name' => '', 'last_name' => ''],
        ]);
        $stored = $record->fresh()->newborn_data['newborns'];

        $this->assertNotEmpty($stored[0]['uuid']);
        $this->assertArrayNotHasKey('uuid', $stored[1]);

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'newborn_data' => ['newborns' => [$stored[0], [...$stored[1], 'first_name' => 'Faly']]],
        ])->assertSessionHasNoErrors();

        $this->assertNotEmpty($record->fresh()->newborn_data['newborns'][1]['uuid']);
    }

    // ── Ce que les écrans lisent ─────────────────────────────────────────

    public function test_the_maternity_page_serves_the_babies_with_their_own_dossier_before_they_are_patients(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $uuid = PatientNewbornLink::query()->sole()->newborn_uuid;

        $props = $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")->viewData('page')['props'];

        $this->assertSame("{$mother->patient_number}-B1", $props['newbornPatients'][$uuid]['patient_number']);
        // La même projection que le détail du passage : un seul composant l'affiche partout (ADR-145).
        $babies = $props['babies']['newborns'];
        $this->assertSame("{$mother->patient_number}-B1", $babies[0]['patient_number']);
        // Le second n'est pas patient : son dossier s'ouvre pourtant, depuis sa fiche (ADR-146).
        $this->assertNull($babies[1]['patient_number']);
        $this->assertSame("/passages/{$episode->uuid}/nouveau-nes/{$babies[1]['uuid']}/dossier-medical", $babies[1]['medical_record_url']);
        // Plus aucun geste de création à la Maternité : le patient se crée à l'accueil.
        $this->assertArrayNotHasKey('can_create', $props['babies']);
        $this->assertArrayNotHasKey('create_url_base', $props['babies']);
    }

    public function test_the_patient_page_reads_the_link_in_both_directions_without_any_clinical_data(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view']);

        $forBaby = $this->actingAs($reader)->get("/patients/{$baby->uuid}")->viewData('page')['props']['family'];
        $forMother = $this->actingAs($reader)->get("/patients/{$mother->uuid}")->viewData('page')['props']['family'];

        $this->assertSame($mother->patient_number, $forBaby['mother']['patient_number']);
        $this->assertSame([], $forBaby['children']);
        $this->assertNull($forMother['mother']);
        $this->assertSame($baby->patient_number, $forMother['children'][0]['patient_number']);
        $this->assertStringNotContainsString('gravidity', json_encode($forBaby));
    }

    public function test_the_babys_medical_record_shows_its_birth_circumstances_but_not_the_mothers_obstetric_file(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $episode = app(CreateEpisodeAction::class)->execute($baby, actor: $midwife);
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $props = $this->actingAs($reader)->get("/passages/{$episode->uuid}/dossier-medical")->viewData('page')['props'];

        $this->assertNull($props['maternity']);
        $birth = $props['birth'];
        $this->assertFalse($birth['restricted']);
        $this->assertSame(2900, (int) $birth['birth_weight_g']);
        $this->assertSame('Féminin', $birth['sex']);
        $this->assertSame(1, $birth['rank']);
        // Ses circonstances de naissance sont les siennes : elles sont servies (ADR-145)…
        $this->assertSame('Voie basse', $birth['delivery_mode']);
        $this->assertSame('Aucune', $birth['delivery_complications']);
        $this->assertArrayHasKey('gestational_age_weeks', $birth);
        $this->assertSame(2, $birth['births_count']);
        $this->assertSame($mother->last_name.' '.$mother->first_name, $birth['mother']['name']);
        // … mais ni les antécédents obstétricaux, ni les facteurs de risque, ni le travail, ni la délivrance :
        // c'est le dossier de la mère.
        $json = json_encode($birth);
        foreach (['gravidity', 'parity', 'pregnancy', 'risk_factors', 'labor', 'placenta', 'maternal'] as $motherOnly) {
            $this->assertStringNotContainsString($motherOnly, $json);
        }
    }

    public function test_without_the_newborn_record_right_the_babys_birth_sheet_is_restricted(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $episode = app(CreateEpisodeAction::class)->execute($baby, actor: $midwife);
        $reader = $this->userWithPermissions(['patients.view']);

        $birth = $this->actingAs($reader)->get("/passages/{$episode->uuid}/dossier-medical")->viewData('page')['props']['birth'];

        $this->assertTrue($birth['restricted']);
        $this->assertArrayNotHasKey('birth_weight_g', $birth);
    }

    public function test_the_mothers_sheet_names_each_babys_dossier(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $reader = $this->userWithPermissions(['patients.view', 'maternity.view', 'newborns.view', 'newborns.medical_record.view']);

        $newborns = $this->actingAs($reader)->get("/passages/{$episode->uuid}/dossier-medical")
            ->viewData('page')['props']['maternity']['newborns'];

        $this->assertSame("{$mother->patient_number}-B1", $newborns[0]['patient_number']);
        $this->assertNull($newborns[1]['patient_number']);
    }

    public function test_the_passage_page_lists_the_babies_and_leads_to_their_dossier(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, $orientation] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900, 'first_name' => 'Faly'],
            ['sex' => 'M', 'birth_weight_g' => 3100],
            ['sex' => '', 'birth_weight_g' => '', 'apgar' => '', 'condition' => '', 'care_notes' => ''],
        ]);
        $this->createDossier($midwife, $orientation, 0)->assertOk();

        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $response = $this->actingAs($reader)->get("/passages/{$episode->uuid}");
        $babies = $response->viewData('page')['props']['maternityBabies'];

        $this->assertSame("/maternity/orientations/{$orientation->uuid}", $babies['maternity_url']);
        $this->assertCount(3, $babies['newborns']);
        $this->assertTrue($babies['newborns'][0]['filled']);
        $this->assertSame("{$mother->last_name} Faly", $babies['newborns'][0]['name']);
        $this->assertSame("{$mother->patient_number}-B1", $babies['newborns'][0]['patient_number']);
        $this->assertNotNull($babies['newborns'][0]['patient_url']);
        $this->assertStringEndsWith('/dossier-medical', $babies['newborns'][0]['medical_record_url']);
        // Pas encore patient : nommé par sa mère, et son dossier s'ouvre depuis sa fiche.
        $this->assertSame("Bébé 2 de {$mother->last_name}", $babies['newborns'][1]['name']);
        $this->assertNull($babies['newborns'][1]['patient_number']);
        $this->assertNull($babies['newborns'][1]['patient_url']);
        $this->assertStringContainsString('/nouveau-nes/', $babies['newborns'][1]['medical_record_url']);
        // Une fiche jamais remplie se dit telle quelle : elle n'a ni identité ni dossier.
        $this->assertFalse($babies['newborns'][2]['filled']);
        $this->assertNull($babies['newborns'][2]['medical_record_url']);
    }

    public function test_the_passage_page_serves_no_babies_without_newborns_view_or_without_a_maternity_record(): void
    {
        $midwife = $this->midwife();
        [, $episode] = $this->twinsRecord($midwife);
        $reader = $this->userWithPermissions(['patients.view']);

        $this->assertNull($this->actingAs($reader)->get("/passages/{$episode->uuid}")->viewData('page')['props']['maternityBabies']);

        $plain = Episode::query()->create([
            'patient_id' => Patient::query()->create([
                'patient_number' => 'A-26-9999', 'first_name' => 'Sans', 'last_name' => 'Maternité', 'birth_date' => '1990-01-01', 'sex' => 'F',
            ])->id,
            'episode_number' => 'A-26-9999-01', 'status' => 'OPEN', 'started_at' => now(), 'created_by' => $midwife->id,
        ]);
        $withRight = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);
        $this->assertNull($this->actingAs($withRight)->get("/passages/{$plain->uuid}")->viewData('page')['props']['maternityBabies']);
    }

    /** ADR-145 — un nouveau-né sans passage a pourtant son dossier médical, au même modèle que sa mère. */
    public function test_a_baby_without_any_passage_has_its_own_medical_record(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa', 'Faly')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $props = $this->actingAs($reader)->get("/patients/{$baby->uuid}/dossier-medical")
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($props['episode']);
        $this->assertSame($baby->patient_number, $props['patient']['patient_number']);
        $this->assertSame("/patients/{$baby->uuid}", $props['back']['href']);
        $this->assertSame(2900, (int) $props['birth']['birth_weight_g']);
        $this->assertSame($mother->patient_number, $props['birth']['mother']['patient_number']);
    }

    public function test_a_patient_medical_record_reads_the_latest_passage_when_there_is_one(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $episode = app(CreateEpisodeAction::class)->execute($baby, actor: $midwife);
        $reader = $this->userWithPermissions(['patients.view']);

        $props = $this->actingAs($reader)->get("/patients/{$baby->uuid}/dossier-medical")->viewData('page')['props'];

        $this->assertSame($episode->uuid, $props['episode']['uuid']);
    }

    public function test_the_medical_record_of_a_patient_needs_patients_view(): void
    {
        $midwife = $this->midwife();
        [$mother] = $this->twinsRecord($midwife);
        $outsider = $this->userWithPermissions(['newborns.medical_record.view']);

        $this->actingAs($outsider)->get("/patients/{$mother->uuid}/dossier-medical")->assertForbidden();
    }

    public function test_the_mother_and_her_babies_are_reachable_from_each_others_medical_record(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $secondUuid = MaternityRecord::query()->firstOrFail()->newborn_data['newborns'][1]['uuid'];
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $fromMother = $this->actingAs($reader)->get("/passages/{$episode->uuid}/dossier-medical")->viewData('page')['props']['dossiers'];
        $fromBaby = $this->actingAs($reader)->get("/patients/{$baby->uuid}/dossier-medical")->viewData('page')['props']['dossiers'];
        $fromVirtual = $this->actingAs($reader)->get("/passages/{$episode->uuid}/nouveau-nes/{$secondUuid}/dossier-medical")->viewData('page')['props']['dossiers'];

        foreach ([$fromMother, $fromBaby, $fromVirtual] as $tabs) {
            $this->assertSame(['mother', 'baby-1', 'baby-2'], collect($tabs)->pluck('key')->all());
            $this->assertSame("/passages/{$episode->uuid}/dossier-medical", $tabs[0]['href']);
            $this->assertSame("/patients/{$baby->uuid}/dossier-medical", $tabs[1]['href']);
            // Le second bébé n'est pas encore patient, et son dossier s'ouvre pourtant (ADR-146).
            $this->assertSame("/passages/{$episode->uuid}/nouveau-nes/{$secondUuid}/dossier-medical", $tabs[2]['href']);
            $this->assertSame('Pas encore patient', $tabs[2]['sub']);
        }

        $this->assertTrue($fromMother[0]['current']);
        $this->assertFalse($fromMother[1]['current']);
        $this->assertFalse($fromBaby[0]['current']);
        $this->assertTrue($fromBaby[1]['current']);
        $this->assertTrue($fromVirtual[2]['current']);
        $this->assertFalse($fromVirtual[0]['current']);
    }

    public function test_without_the_newborn_record_right_only_the_dossiers_really_created_are_listed(): void
    {
        $midwife = $this->midwife();
        [, $episode, $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $reader = $this->userWithPermissions(['patients.view']);

        $tabs = $this->actingAs($reader)->get("/passages/{$episode->uuid}/dossier-medical")->viewData('page')['props']['dossiers'];

        $this->assertSame(['mother', 'baby-1'], collect($tabs)->pluck('key')->all());
    }

    public function test_a_patient_who_is_neither_mother_nor_baby_has_no_family_tabs(): void
    {
        $midwife = $this->midwife();
        $patient = Patient::query()->create([
            'patient_number' => 'A-26-9998', 'first_name' => 'Seul', 'last_name' => 'Patient', 'birth_date' => '1980-01-01', 'sex' => 'M',
        ]);
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $props = $this->actingAs($reader)->get("/patients/{$patient->uuid}/dossier-medical")->viewData('page')['props'];

        $this->assertNull($props['dossiers']);
        $this->assertNull($props['birth']);
    }

    // ── ADR-146 : la Réception retrouve le bébé chez sa mère ──────────────

    public function test_the_reception_lists_the_babies_of_a_mother_without_any_clinical_data(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900, 'apgar' => 8, 'first_name' => 'Faly'],
            ['sex' => '', 'birth_weight_g' => 3100],
            ['sex' => '', 'birth_weight_g' => '', 'apgar' => ''],
        ]);
        $this->createDossier($midwife, $orientation, 0)->assertOk();

        $response = $this->actingAs($this->receptionist())->getJson("/reception/newborns?mother={$mother->uuid}")->assertOk();
        $rows = $response->json('data');

        // Deux bébés consignés ; la fiche vide n'est pas listée.
        $this->assertCount(2, $rows);
        $this->assertSame("{$mother->last_name} Faly", $rows[0]['name']);
        $this->assertSame("{$mother->patient_number}-B1", $rows[0]['patient']['patient_number']);
        $this->assertSame("Bébé 2 de {$mother->last_name}", $rows[1]['name']);
        $this->assertNull($rows[1]['patient']);
        $this->assertNull($rows[1]['sex_code']);
        $this->assertSame('2026-09-20T06:40', $rows[1]['born_at']);
        // Un poste de Réception n'a pas maternity.view : ni poids, ni Apgar, ni soins ne sortent.
        $json = json_encode($response->json());
        foreach (['birth_weight', 'apgar', 'condition', 'care_notes', 'gravidity'] as $clinical) {
            $this->assertStringNotContainsString($clinical, $json);
        }
    }

    public function test_only_the_babies_of_that_mother_are_listed(): void
    {
        $midwife = $this->midwife();
        [$mother] = $this->twinsRecord($midwife);
        [$other] = $this->twinsRecord($midwife, newborns: [['sex' => 'M', 'birth_weight_g' => 3300]]);

        $rows = $this->actingAs($this->receptionist())->getJson("/reception/newborns?mother={$other->uuid}")->json('data');

        $this->assertCount(1, $rows);
        $this->assertNotSame($mother->id, $other->id);
    }

    public function test_a_baby_without_a_recorded_delivery_date_is_listed_as_blocked(): void
    {
        $midwife = $this->midwife();
        [$mother] = $this->twinsRecord($midwife, delivery: []);

        $rows = $this->actingAs($this->receptionist())->getJson("/reception/newborns?mother={$mother->uuid}")->json('data');

        $this->assertNotNull($rows[0]['blocked']);
    }

    public function test_the_reception_list_needs_the_reception_right(): void
    {
        $midwife = $this->midwife();
        [$mother] = $this->twinsRecord($midwife);

        $this->actingAs($this->userWithPermissions(['patients.view']))->getJson("/reception/newborns?mother={$mother->uuid}")->assertForbidden();
    }

    /** Un clic, et le bébé repart dans le parcours d'arrivée comme n'importe quel patient existant. */
    public function test_choosing_the_baby_returns_the_patient_the_arrival_screen_selects(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);

        $patient = $this->createDossier($midwife, $orientation, 0)->assertOk()->json('patient');

        $this->assertSame("{$mother->patient_number}-B1", $patient['patient_number']);
        $this->assertArrayHasKey('uuid', $patient);
        $this->assertSame('F', $patient['sex']);
        // Le dossier créé est bien celui de la recherche patient : l'écran ne distingue pas d'où il vient.
        $this->actingAs($this->receptionist())->getJson('/reception/patients/search?q='.urlencode($patient['patient_number']))
            ->assertOk()->assertJsonPath('data.0.uuid', $patient['uuid']);
    }

    // ── ADR-146 : le dossier d'un bébé qui n'est pas encore patient ──────────

    public function test_a_baby_who_is_not_a_patient_yet_has_its_medical_record_read_from_its_fiche(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, , $record] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'M', 'birth_weight_g' => 3100, 'apgar' => 9, 'first_name' => 'Tojo', 'last_name' => 'Andrianina'],
        ]);
        $uuid = $record->fresh()->newborn_data['newborns'][0]['uuid'];
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $props = $this->actingAs($reader)->get("/passages/{$episode->uuid}/nouveau-nes/{$uuid}/dossier-medical")
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($props['episode']);
        $this->assertNull($props['patient']['patient_number']);
        $this->assertSame('Andrianina Tojo', $props['patient']['name']);
        $this->assertSame('2026-09-20', $props['patient']['birth_date']);
        $this->assertSame('M', $props['patient']['sex']);
        $this->assertSame('Voie basse', $props['birth']['delivery_mode']);
        $this->assertSame(3100, (int) $props['birth']['birth_weight_g']);
        $this->assertSame($mother->patient_number, $props['birth']['mother']['patient_number']);
        $this->assertSame("/passages/{$episode->uuid}", $props['back']['href']);
        // Rien de l'histoire obstétricale de la mère.
        $this->assertStringNotContainsString('gravidity', json_encode($props['birth']));
        $this->assertSame(0, Patient::query()->whereHas('newbornLink')->count());
    }

    public function test_the_virtual_record_gives_way_to_the_patient_record_once_the_baby_is_a_patient(): void
    {
        $midwife = $this->midwife();
        [, $episode, $orientation, $record] = $this->twinsRecord($midwife);
        $uuid = $record->fresh()->newborn_data['newborns'][0]['uuid'];
        $this->createDossier($midwife, $orientation, 0)->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $this->actingAs($reader)->get("/passages/{$episode->uuid}/nouveau-nes/{$uuid}/dossier-medical")
            ->assertRedirect("/patients/{$baby->uuid}/dossier-medical");
    }

    public function test_the_virtual_record_needs_the_newborn_record_right_and_a_known_baby(): void
    {
        $midwife = $this->midwife();
        [, $episode, , $record] = $this->twinsRecord($midwife);
        $uuid = $record->fresh()->newborn_data['newborns'][0]['uuid'];

        $this->actingAs($this->userWithPermissions(['patients.view']))
            ->get("/passages/{$episode->uuid}/nouveau-nes/{$uuid}/dossier-medical")->assertForbidden();
        $this->actingAs($this->userWithPermissions(['newborns.medical_record.view']))
            ->get("/passages/{$episode->uuid}/nouveau-nes/{$uuid}/dossier-medical")->assertForbidden();
        $this->actingAs($this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']))
            ->get("/passages/{$episode->uuid}/nouveau-nes/11111111-1111-4111-8111-111111111111/dossier-medical")->assertNotFound();
    }

    /** Les dossiers déjà enregistrés avant l'ADR-146 n'avaient d'identité que pour un bébé devenu patient. */
    public function test_the_migration_gives_an_identity_to_the_babies_already_recorded(): void
    {
        $midwife = $this->midwife();
        [, , , $record] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900],
            ['sex' => '', 'birth_weight_g' => ''],
            ['sex' => 'M', 'birth_weight_g' => 3100],
        ]);
        $keep = $record->fresh()->newborn_data['newborns'][2]['uuid'];
        $data = $record->fresh()->newborn_data;
        unset($data['newborns'][0]['uuid']);
        \DB::table('maternity_records')->where('id', $record->id)->update(['newborn_data' => json_encode($data)]);

        (require database_path('migrations/2026_10_07_090000_assign_uuids_to_newborn_fiches.php'))->up();

        $after = $record->fresh()->newborn_data['newborns'];
        $this->assertNotEmpty($after[0]['uuid']);
        $this->assertArrayNotHasKey('uuid', $after[1]);
        $this->assertSame($keep, $after[2]['uuid']);
    }

    // ── ADR-146 : les dossiers ouverts à l'accouchement retournent à la fiche ──

    private function revertMigration(): void
    {
        (require database_path('migrations/2026_10_08_090000_return_unused_newborn_patients_to_their_mother.php'))->up();
    }

    public function test_a_newborn_dossier_that_never_served_goes_back_to_its_mothers_fiche(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation, $record] = $this->twinsRecord($midwife, newborns: [['sex' => 'F', 'birth_weight_g' => 2900]]);
        $this->createDossier($midwife, $orientation, 0, 'Andrianina', 'Faly')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();

        $this->revertMigration();

        // Le dossier patient disparaît, et son numéro redevient libre pour le jour de l'accueil.
        $this->assertSame(0, Patient::query()->withTrashed()->where('id', $baby->id)->count());
        $this->assertSame(0, PatientNewbornLink::query()->count());
        // Le nom, que l'ancien geste n'écrivait que sur le patient, rejoint la fiche : sinon il disparaîtrait.
        $fiche = $record->fresh()->newborn_data['newborns'][0];
        $this->assertSame(['Andrianina', 'Faly'], [$fiche['last_name'], $fiche['first_name']]);
        $this->assertNotEmpty($fiche['uuid']);
        // Sa mère n'est pas touchée.
        $this->assertNotNull($mother->fresh());
        $this->assertDatabaseHas('audit_logs', ['action' => 'maternity.newborn.patient.revert']);
    }

    /** Le nom saisi par la sage-femme dans la fiche fait foi : le retour ne le réécrit pas. */
    public function test_the_fiche_keeps_the_name_the_midwife_had_written(): void
    {
        $midwife = $this->midwife();
        [, , $orientation, $record] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900, 'first_name' => 'Faly', 'last_name' => 'Andrianina'],
        ]);
        $this->createDossier($midwife, $orientation, 0, 'Autre', 'Nom')->assertOk();

        $this->revertMigration();

        $fiche = $record->fresh()->newborn_data['newborns'][0];
        $this->assertSame(['Andrianina', 'Faly'], [$fiche['last_name'], $fiche['first_name']]);
    }

    /** Un dossier qui a servi n'est jamais détruit : on ne défait pas un historique (ADR-010). */
    public function test_a_newborn_who_already_has_a_passage_stays_a_patient(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        app(CreateEpisodeAction::class)->execute($baby, actor: $midwife);

        $this->revertMigration();

        $this->assertNotNull($baby->fresh());
        $this->assertSame(1, PatientNewbornLink::query()->count());
    }

    public function test_a_newborn_with_an_allergy_of_its_own_stays_a_patient(): void
    {
        $midwife = $this->midwife();
        [, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $baby->allergies()->create(['substance' => 'Pénicilline', 'recorded_by' => $midwife->id]);

        $this->revertMigration();

        $this->assertNotNull($baby->fresh());
    }

    /** Une fois accueilli, le bébé est un patient comme un autre — sa ligne dit seulement de qui il est l'enfant. */
    public function test_the_directory_lists_a_newborn_patient_and_names_its_mother(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view']);

        $rows = collect($this->actingAs($reader)->get('/patients')->viewData('page')['props']['patients']['data']);

        $row = $rows->firstWhere('uuid', $baby->uuid);
        $this->assertNotNull($row, 'Le bébé accueilli reste un patient du répertoire.');
        $this->assertSame($mother->patient_number, $row['newborn_of']['patient_number']);
        $this->assertSame(trim("{$mother->last_name} {$mother->first_name}"), $row['newborn_of']['name']);
        // Sa mère, elle, n'est l'enfant de personne.
        $this->assertNull($rows->firstWhere('uuid', $mother->uuid)['newborn_of']);
    }

    // ── ADR-146 : le dossier de la mère montre ses bébés, patients ou non ──

    public function test_the_mothers_dossier_lists_her_babies_even_before_they_are_patients(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, $orientation, $record] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900, 'first_name' => 'Faly'],
            ['sex' => 'M', 'birth_weight_g' => 3100],
            ['sex' => '', 'birth_weight_g' => '', 'apgar' => '', 'condition' => '', 'care_notes' => ''],
        ]);
        $this->createDossier($midwife, $orientation, 0)->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $secondUuid = $record->fresh()->newborn_data['newborns'][1]['uuid'];
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view', 'newborns.medical_record.view']);

        $children = $this->actingAs($reader)->get("/patients/{$mother->uuid}")->viewData('page')['props']['family']['children'];

        // Les deux bébés consignés, jamais la fiche restée vide.
        $this->assertCount(2, $children);
        $this->assertTrue($children[0]['is_patient']);
        $this->assertSame($baby->patient_number, $children[0]['patient_number']);
        $this->assertSame("/patients/{$baby->uuid}/dossier-medical", $children[0]['medical_record_url']);
        // Le second n'est pas patient : il est nommé par sa mère, et son dossier s'ouvre depuis sa fiche.
        $this->assertFalse($children[1]['is_patient']);
        $this->assertNull($children[1]['patient_number']);
        $this->assertSame("Bébé 2 de {$mother->last_name}", $children[1]['name']);
        $this->assertSame("/passages/{$episode->uuid}/nouveau-nes/{$secondUuid}/dossier-medical", $children[1]['medical_record_url']);
    }

    /** Sans `newborns.medical_record.view`, le bébé est nommé — son identité n'est pas clinique — mais son dossier n'est pas proposé. */
    public function test_without_the_newborn_record_right_a_baby_who_is_not_a_patient_is_named_but_has_no_record_link(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view']);

        $children = $this->actingAs($reader)->get("/patients/{$mother->uuid}")->viewData('page')['props']['family']['children'];

        $this->assertCount(2, $children);
        // Le bébé patient garde le sien : c'est un dossier patient comme un autre.
        $this->assertNotNull($children[0]['medical_record_url']);
        $this->assertNull($children[1]['medical_record_url']);
        $this->assertSame("Bébé 2 de {$mother->last_name}", $children[1]['name']);
    }

    /** Le répertoire dit quelles patientes ont accouché ici : c'est ce que la Réception cherche. */
    public function test_the_directory_marks_a_mother_whose_baby_was_born_here(): void
    {
        $midwife = $this->midwife();
        [$mother] = $this->twinsRecord($midwife, newborns: [
            ['sex' => 'F', 'birth_weight_g' => 2900],
            ['sex' => 'M', 'birth_weight_g' => 3100],
            ['sex' => '', 'birth_weight_g' => '', 'apgar' => '', 'condition' => '', 'care_notes' => ''],
        ]);
        $other = Patient::query()->create([
            'patient_number' => 'A-26-8888', 'first_name' => 'Sans', 'last_name' => 'Bébé',
            'birth_date' => '1990-01-01', 'sex' => 'F',
        ]);
        $reader = $this->userWithPermissions(['patients.view', 'newborns.view']);

        $rows = collect($this->actingAs($reader)->get('/patients')->viewData('page')['props']['patients']['data']);

        // Deux bébés consignés, la fiche vide n'en est pas un.
        $this->assertSame(2, $rows->firstWhere('uuid', $mother->uuid)['newborn_children']);
        $this->assertSame(0, $rows->firstWhere('uuid', $other->uuid)['newborn_children']);
    }

    // ── ADR-146 amendement : les droits propres au nouveau-né ────────────

    /**
     * Le défaut signalé : un poste de Réception voyait le bébé chez sa mère sans jamais pouvoir ouvrir son
     * dossier, parce que la feuille exigeait `maternity.view` — le droit de l'espace Maternité, qu'aucun rôle
     * ne porte et que seul le profil sage-femme reçoit (ADR-067). Un droit à lui le débloque, sans lui donner
     * l'espace Maternité ni le dossier obstétrical de sa mère.
     */
    public function test_the_reception_role_sees_the_baby_and_opens_its_record_once_the_right_is_granted(): void
    {
        $midwife = $this->midwife();
        [$mother, $episode, , $record] = $this->twinsRecord($midwife);
        $uuid = $record->fresh()->newborn_data['newborns'][0]['uuid'];

        $agent = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);
        $url = "/passages/{$episode->uuid}/nouveau-nes/{$uuid}/dossier-medical";

        // Le socle du rôle nomme l'enfant — son identité n'est pas clinique — sans ouvrir son dossier.
        $children = $this->actingAs($agent)->get("/patients/{$mother->uuid}")->viewData('page')['props']['family']['children'];
        $this->assertCount(2, $children);
        $this->assertNull($children[0]['medical_record_url']);
        $this->actingAs($agent)->get($url)->assertForbidden();

        // Un clic du Super Administrateur dans « Rôles & permissions » suffit (ADR-064).
        $agent->permissions()->attach(
            Permission::query()->where('name', 'newborns.medical_record.view')->value('id'),
            ['effect' => 'allow'],
        );

        $birth = $this->actingAs($agent->fresh())->get($url)->viewData('page')['props']['birth'];
        $this->assertFalse($birth['restricted']);
        $this->assertSame('2900', (string) $birth['birth_weight_g']);
    }

    /** Sans `newborns.view`, rien de la filiation n'est servi : ni la carte, ni les repères du répertoire. */
    public function test_without_newborns_view_no_filiation_is_served_at_all(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation] = $this->twinsRecord($midwife);
        $this->createDossier($midwife, $orientation, 0, 'Rasoa')->assertOk();
        $baby = Patient::query()->whereHas('newbornLink')->sole();
        $reader = $this->userWithPermissions(['patients.view']);

        $family = $this->actingAs($reader)->get("/patients/{$baby->uuid}")->viewData('page')['props']['family'];
        $this->assertNull($family['mother']);
        $this->assertSame([], $family['children']);

        $rows = collect($this->actingAs($reader)->get('/patients')->viewData('page')['props']['patients']['data']);
        $this->assertNull($rows->firstWhere('uuid', $baby->uuid)['newborn_of']);
        $this->assertSame(0, $rows->firstWhere('uuid', $mother->uuid)['newborn_children']);
    }

    /** L'arborescence de l'accueil et la création du dossier ont chacune leur droit. */
    public function test_the_reception_arborescence_and_the_dossier_creation_have_their_own_rights(): void
    {
        $midwife = $this->midwife();
        [$mother, , $orientation, $record] = $this->twinsRecord($midwife);
        $uuid = $record->fresh()->newborn_data['newborns'][0]['uuid'];

        $this->actingAs($this->userWithPermissions(['episodes.create']))
            ->getJson("/reception/newborns?mother={$mother->uuid}")->assertForbidden();

        $this->createDossier($midwife, $orientation, 0, 'Rasoa', as: $this->userWithPermissions([
            'episodes.create', 'patients.create', 'patients.view', 'newborns.view',
        ]))->assertForbidden();

        $this->assertDatabaseCount('patient_newborn_links', 0);
        $this->assertNotNull($uuid);
    }

    // ── Aides ────────────────────────────────────────────────────────────

    /**
     * Ce que fait la Réception : choisir le bébé dans l'arborescence de sa mère (ADR-146). Le premier argument
     * est conservé pour la lisibilité des scénarios — c'est un poste de Réception qui agit, pas la sage-femme.
     */
    private function createDossier(User $unused, EpisodeOrientation $orientation, int $index, string $lastName = '', ?string $firstName = null, ?string $sex = null, ?User $as = null)
    {
        $record = MaternityRecord::query()->where('episode_id', $orientation->episode_id)->firstOrFail();
        $uuid = $record->newborn_data['newborns'][$index]['uuid'] ?? '00000000-0000-4000-8000-000000000000';

        return $this->actingAs($as ?? $this->receptionist())->postJson(
            "/reception/newborns/{$record->uuid}/{$uuid}/patient",
            array_filter(['last_name' => $lastName, 'first_name' => $firstName, 'sex' => $sex], fn ($value) => $value !== null && $value !== ''),
        );
    }

    private function receptionist(): User
    {
        return $this->userWithPermissions([
            'episodes.create', 'patients.create', 'patients.view',
            'newborns.view', 'newborns.patient.create',
        ]);
    }

    /**
     * Un dossier Maternité de jumeaux enregistré comme l'écran le fait : par le PUT du dossier.
     *
     * @param  array<string, mixed>|null  $delivery
     * @param  list<array<string, mixed>>|null  $newborns
     * @return array{Patient, Episode, EpisodeOrientation, MaternityRecord}
     */
    private function twinsRecord(User $midwife, ?array $delivery = null, ?array $newborns = null): array
    {
        $mother = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Vola', 'last_name' => 'Rakoto', 'birth_date' => '1996-05-12', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($mother, actor: $midwife);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Reception, CatalogModule::Maternity, $midwife, 'Suivi obstétrical',
        );
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept");

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'pregnancy_data' => ['gravidity' => 2, 'parity' => 1],
            'delivery_data' => $delivery ?? ['occurred_at' => '2026-09-20T06:40', 'mode' => 'VAGINAL', 'complications' => 'Aucune'],
            'newborn_data' => ['newborns' => $newborns ?? [
                ['sex' => 'F', 'birth_weight_g' => 2900, 'apgar' => 8, 'condition' => 'Bon', 'care_notes' => 'Photothérapie'],
                ['sex' => 'M', 'birth_weight_g' => 3100, 'apgar' => 9, 'condition' => 'Bon', 'care_notes' => ''],
            ]],
        ])->assertSessionHasNoErrors();

        return [$mother, $episode->fresh(), $orientation->fresh(), MaternityRecord::query()->where('episode_id', $episode->id)->firstOrFail()];
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

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'R-'.uniqid(), 'name' => 'Test']);

        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name]));
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
