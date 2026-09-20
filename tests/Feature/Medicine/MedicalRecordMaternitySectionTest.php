<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-143 — la Maternité est une section du dossier médical, pas un second dossier.
 *
 * Elle relit `maternity_records` sans rien dupliquer, chaque bébé y a son bloc, une
 * case vide reste vide, et le droit `maternity.view` la garde.
 */
class MedicalRecordMaternitySectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_passage_without_maternity_has_no_maternity_section(): void
    {
        $doctor = $this->user(['patients.view', 'maternity.view']);
        [$episode] = $this->episode($doctor);

        $this->assertNull($this->sheet($doctor, $episode)['maternity']);
    }

    public function test_the_section_reads_pregnancy_labor_delivery_and_procedures_from_the_maternity_record(): void
    {
        $doctor = $this->user(['patients.view', 'maternity.view']);
        [$episode] = $this->episode($doctor);
        $record = $this->record($episode, $doctor, [
            'obstetric_context' => 'Grossesse suivie, 3e enfant.',
            'pregnancy_data' => ['gravidity' => 3, 'parity' => 2, 'estimated_due_date' => '2026-10-01', 'risk_factors' => 'HTA'],
            'prenatal_data' => ['gestational_age_weeks' => 39, 'fetal_heart_rate' => 140],
            'labor_data' => ['membranes_status' => 'RUPTURED', 'cervical_dilation_cm' => 10],
            'delivery_data' => ['mode' => 'VAGINAL', 'complications' => 'Aucune hémorragie.'],
            'maternal_care_notes' => 'Surveillance du globe utérin.',
        ]);
        $this->procedure($record, $doctor, 'Accouchement simple', 1);

        $maternity = $this->sheet($doctor, $episode)['maternity'];

        $this->assertFalse($maternity['restricted']);
        $this->assertSame('Grossesse suivie, 3e enfant.', $maternity['context']);
        $this->assertSame(3, $maternity['pregnancy']['gravidity']);
        $this->assertSame('HTA', $maternity['pregnancy']['risk_factors']);
        $this->assertSame(39, $maternity['prenatal']['gestational_age_weeks']);
        // Les libellés sont ceux de l'écran Maternité, jamais le code stocké.
        $this->assertSame('Rompues', $maternity['labor']['membranes']);
        $this->assertSame('Voie basse', $maternity['delivery']['mode']);
        $this->assertSame('Surveillance du globe utérin.', $maternity['maternal_care_notes']);
        $this->assertSame('Accouchement simple', $maternity['procedures'][0]['name']);
        $this->assertSame('1', $maternity['procedures'][0]['quantity']);
    }

    /** Avec des jumeaux, chacun a son état et ses soins — jamais fondus en une note. */
    public function test_each_newborn_has_its_own_block_and_an_unfilled_one_is_not_listed(): void
    {
        $doctor = $this->user(['patients.view', 'maternity.view']);
        [$episode] = $this->episode($doctor);
        $this->record($episode, $doctor, ['newborn_data' => ['newborns' => [
            ['sex' => 'F', 'birth_weight_g' => 2900, 'apgar' => 0, 'condition' => 'Réanimé', 'care_notes' => 'Photothérapie'],
            ['sex' => 'M', 'birth_weight_g' => 3100, 'apgar' => 9, 'condition' => '', 'care_notes' => ''],
            // Une fiche ouverte d'office pour des jumeaux (ADR-136) et jamais remplie.
            ['sex' => '', 'birth_weight_g' => '', 'apgar' => '', 'condition' => '', 'care_notes' => ''],
        ]]]);

        $newborns = $this->sheet($doctor, $episode)['maternity']['newborns'];

        $this->assertCount(2, $newborns);
        $this->assertSame(1, $newborns[0]['rank']);
        $this->assertSame('Féminin', $newborns[0]['sex']);
        $this->assertSame('Photothérapie', $newborns[0]['care_notes']);
        // Apgar 0 est une valeur, pas une absence.
        $this->assertSame(0, $newborns[0]['apgar']);
        $this->assertSame(2, $newborns[1]['rank']);
        $this->assertSame('Masculin', $newborns[1]['sex']);
        $this->assertNull($newborns[1]['condition']);
        $this->assertNull($newborns[1]['care_notes']);
    }

    public function test_a_box_nobody_filled_stays_empty_never_normal_or_no(): void
    {
        $doctor = $this->user(['patients.view', 'maternity.view']);
        [$episode] = $this->episode($doctor);
        $this->record($episode, $doctor, []);

        $maternity = $this->sheet($doctor, $episode)['maternity'];

        $this->assertNull($maternity['pregnancy']['gravidity']);
        $this->assertNull($maternity['labor']['membranes']);
        $this->assertNull($maternity['delivery']['mode']);
        $this->assertSame([], $maternity['newborns']);
        $this->assertSame([], $maternity['procedures']);
    }

    public function test_an_old_shared_baby_care_note_is_kept_when_it_exists(): void
    {
        $doctor = $this->user(['patients.view', 'maternity.view']);
        [$episode] = $this->episode($doctor);
        $this->record($episode, $doctor, ['baby_care_notes' => 'Ancienne note commune.']);

        $this->assertSame('Ancienne note commune.', $this->sheet($doctor, $episode)['maternity']['baby_care_notes_legacy']);
    }

    /** Une feuille imprimée n'est pas un moyen de contourner `maternity.view` (ADR-054). */
    public function test_without_maternity_view_the_section_is_restricted_and_leaks_nothing(): void
    {
        $doctor = $this->user(['patients.view']);
        [$episode] = $this->episode($doctor);
        $this->record($episode, $doctor, [
            'obstetric_context' => 'Détail confidentiel de la grossesse.',
            'newborn_data' => ['newborns' => [['sex' => 'F', 'birth_weight_g' => 3000]]],
        ]);

        $maternity = $this->sheet($doctor, $episode)['maternity'];

        $this->assertSame(['restricted' => true], $maternity);
        $this->assertStringNotContainsString('confidentiel', json_encode($maternity));
    }

    public function test_the_page_renders_for_a_passage_with_maternity(): void
    {
        $doctor = $this->user(['patients.view', 'maternity.view']);
        [$episode] = $this->episode($doctor);
        $this->record($episode, $doctor, ['newborn_data' => ['newborns' => [['sex' => 'M', 'birth_weight_g' => 3200]]]]);

        $this->actingAs($doctor)->get("/passages/{$episode->uuid}/dossier-medical")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Medicine/MedicalRecordPrint')
                ->where('maternity.newborns.0.birth_weight_g', 3200));
    }

    /** @return array<string, mixed> */
    private function sheet(User $user, Episode $episode): array
    {
        return $this->actingAs($user)->get("/passages/{$episode->uuid}/dossier-medical")
            ->assertOk()->viewData('page')['props'];
    }

    /** @return array{Episode} */
    private function episode(User $actor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->bothify('A-26-####'),
            'first_name' => 'Vola', 'last_name' => 'Rasoa', 'birth_date' => '1996-05-12', 'sex' => 'F',
        ]);
        $episode = Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'started_at' => now()->subHours(3),
            'created_by' => $actor->id,
        ]);

        return [$episode];
    }

    /** @param array<string, mixed> $attributes */
    private function record(Episode $episode, User $actor, array $attributes): MaternityRecord
    {
        // Un dossier Maternité naît toujours d'une orientation Maternité sur le passage.
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Reception, CatalogModule::Maternity, $actor, 'Suivi obstétrical',
        );

        return MaternityRecord::query()->create($attributes + [
            'episode_id' => $episode->id, 'episode_orientation_id' => $orientation->id,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function procedure(MaternityRecord $record, User $actor, string $name, int $quantity): void
    {
        $item = CatalogItem::query()->create([
            'code' => 'MAT-'.fake()->unique()->numerify('###'), 'name' => $name, 'type' => CatalogItemType::Service,
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        $record->procedures()->create([
            'catalog_item_id' => $item->id, 'catalog_item_uuid' => $item->uuid, 'procedure_code' => $item->code,
            'procedure_name' => $name, 'quantity' => $quantity, 'performed_by' => $actor->id, 'performed_at' => now(),
        ]);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'R-'.uniqid(), 'name' => 'Test']);

        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name]));
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
