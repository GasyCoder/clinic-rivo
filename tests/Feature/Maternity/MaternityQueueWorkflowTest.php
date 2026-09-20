<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\Patient;
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
 * ADR-135 — la file Maternité suit le parcours réel d'un passage : à prendre
 * ou en cours, orientée vers Médecine, terminée. Les vues sont exclusives et
 * la fin de prise en charge dit ce qui vient ensuite.
 */
class MaternityQueueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_four_views_are_exclusive_and_their_counts_add_up(): void
    {
        $midwife = $this->midwife();
        $doctor = $this->doctor();

        [, $waiting] = $this->maternityOrientation($midwife);
        [, $inProgress] = $this->maternityOrientation($midwife);
        $inProgress->accept($midwife);

        [, $withDoctor] = $this->maternityOrientation($midwife);
        $this->finish($withDoctor, $midwife, toMedicine: true);

        [, $finished] = $this->maternityOrientation($midwife);
        $this->finish($finished, $midwife, toMedicine: false);

        $counts = $this->actingAs($midwife)->get('/maternity')->viewData('page')['props']['counts'];

        // « À prendre » et « En cours » sont deux états, pas un.
        $this->assertSame(1, $counts['waiting']);
        $this->assertSame(1, $counts['active']);
        $this->assertSame(1, $counts['doctor']);
        $this->assertSame(1, $counts['completed']);

        // Une patiente n'est que dans une vue : la somme est le nombre de dossiers.
        $this->assertSame(4, $counts['waiting'] + $counts['active'] + $counts['doctor'] + $counts['completed']);

        $this->assertSame([$waiting->uuid], $this->rows($midwife, 'waiting'));
        $this->assertSame([$inProgress->uuid], $this->rows($midwife, 'active'));
    }

    public function test_completing_with_medicine_opens_a_medicine_orientation_and_keeps_the_episode_in_care(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->maternityOrientation($midwife);

        $this->finish($orientation, $midwife, toMedicine: true, note: 'Céphalées persistantes, tension à contrôler.')
            ->assertRedirect('/maternity?filter=doctor');

        $medicine = EpisodeOrientation::query()
            ->where('episode_id', $episode->id)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();

        $this->assertSame(CatalogModule::Maternity, $medicine->source_module);
        $this->assertSame(EpisodeOrientationStatus::Pending, $medicine->status);
        $this->assertStringContainsString('Céphalées persistantes', $medicine->reason);
        // Le médecin a encore la patiente : elle n'attend pas la Réception.
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $rows = $this->actingAs($midwife)->get('/maternity?filter=doctor')->viewData('page')['props']['orientations']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('En attente du médecin', $rows[0]['follow_up']['medicine']['label']);
        $this->assertCount(0, $this->actingAs($midwife)->get('/maternity')->viewData('page')['props']['orientations']['data']);
    }

    public function test_a_patient_leaves_the_doctor_view_once_the_doctor_has_finished(): void
    {
        $midwife = $this->midwife();
        $doctor = $this->doctor();
        [, $orientation] = $this->maternityOrientation($midwife);
        $this->finish($orientation, $midwife, toMedicine: true);

        $medicine = EpisodeOrientation::query()->where('destination_module', 'MEDICINE')->sole();
        $medicine->accept($doctor);

        $this->assertSame(1, $this->counts($midwife)['doctor']);
        $inProgress = $this->actingAs($midwife)->get('/maternity?filter=doctor')->viewData('page')['props']['orientations']['data'];
        $this->assertSame('Vue par le médecin', $inProgress[0]['follow_up']['medicine']['label']);

        $medicine->fresh()->complete($doctor);

        // Personne ne l'attend plus : elle est simplement terminée, dossier consultable.
        $this->assertSame(0, $this->counts($midwife)['doctor']);
        $this->assertSame(1, $this->counts($midwife)['completed']);
    }

    public function test_completing_without_a_follow_up_hands_the_episode_to_reception(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->maternityOrientation($midwife);

        $this->finish($orientation, $midwife, toMedicine: false)->assertRedirect('/maternity');

        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->fresh()->administrative_status);
        $this->assertSame(0, EpisodeOrientation::query()->where('destination_module', 'MEDICINE')->count());
    }

    /** Une césarienne demandée à Chirurgie retient la patiente : rien n'attend la Réception. */
    public function test_an_open_cesarean_request_keeps_the_episode_out_of_settlement(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->maternityOrientation($midwife);
        $this->catalogItem($midwife, 'MAT-CESAREAN-SIMPLE', 'Césarienne simple', CatalogModule::Maternity);
        $this->catalogItem($midwife, 'SURG-CESARIENNE', 'Opération césarienne', CatalogModule::Surgery);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept");
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/cesarean", [
            'type' => 'SIMPLE', 'indication' => 'Présentation transverse.',
        ])->assertSessionHasNoErrors();

        $row = $this->actingAs($midwife)->get('/maternity?filter=active')->viewData('page')['props']['orientations']['data'][0];
        $this->assertSame('PENDING', $row['follow_up']['cesarean']['status']);
        $this->assertSame('Césarienne demandée', $row['follow_up']['cesarean']['label']);

        $this->record($orientation, $midwife);
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();

        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
    }

    public function test_an_already_active_medicine_orientation_is_reused_not_duplicated(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->maternityOrientation($midwife);
        app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Reception, CatalogModule::Medicine, $midwife, 'Urgence ouverte en parallèle.',
        );

        $this->finish($orientation, $midwife, toMedicine: true);

        $this->assertSame(1, EpisodeOrientation::query()
            ->where('episode_id', $episode->id)->where('destination_module', 'MEDICINE')->count());
    }

    public function test_an_unknown_filter_falls_back_to_the_work_to_do(): void
    {
        $midwife = $this->midwife();

        $this->actingAs($midwife)->get('/maternity?filter=archives')
            ->assertInertia(fn ($page) => $page->where('filter', 'waiting'));
    }

    public function test_completing_needs_the_complete_permission_even_to_orient_to_medicine(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->maternityOrientation($midwife);
        $orientation->accept($midwife);
        $this->record($orientation, $midwife);
        $midwife->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'maternity.complete')->value('id') => ['effect' => 'deny'],
        ]);

        $this->actingAs($midwife->fresh())
            ->post("/maternity/orientations/{$orientation->uuid}/complete", ['orient_to_medicine' => true])
            ->assertForbidden();

        $this->assertSame(0, EpisodeOrientation::query()->where('destination_module', 'MEDICINE')->count());
    }

    private function finish(EpisodeOrientation $orientation, User $midwife, bool $toMedicine, ?string $note = null)
    {
        if ($orientation->fresh()->status === EpisodeOrientationStatus::Pending) {
            $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept");
        }

        $this->record($orientation, $midwife);

        return $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete", array_filter([
            'orient_to_medicine' => $toMedicine ?: null,
            'medicine_note' => $note,
        ], fn ($value) => $value !== null));
    }

    private function record(EpisodeOrientation $orientation, User $midwife): MaternityRecord
    {
        return MaternityRecord::query()->firstOrCreate(
            ['episode_id' => $orientation->episode_id],
            ['episode_orientation_id' => $orientation->id, 'created_by' => $midwife->id, 'updated_by' => $midwife->id],
        );
    }

    /** @return list<string> les UUID des orientations de la vue, dans l'ordre servi */
    private function rows(User $user, string $filter): array
    {
        return collect($this->actingAs($user)->get("/maternity?filter={$filter}")
            ->viewData('page')['props']['orientations']['data'])->pluck('uuid')->all();
    }

    /** @return array<string, int> */
    private function counts(User $user): array
    {
        return $this->actingAs($user)->get('/maternity')->viewData('page')['props']['counts'];
    }

    /** @return array{Episode, EpisodeOrientation} */
    private function maternityOrientation(User $actor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'birth_date' => '1996-05-12', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $actor);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Medicine, CatalogModule::Maternity, $actor, 'Suivi obstétrical',
        );

        return [$episode, $orientation];
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

    private function doctor(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', 'MEDICINE')->value('id')]);
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
