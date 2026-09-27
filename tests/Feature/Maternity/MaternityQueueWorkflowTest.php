<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\SetEpisodeReceptionNextStepsAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\ReceptionNextStep;
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
 * ADR-135, ADR-177 — la Maternité lit le tableau des passages partagé avec les
 * Soins et Médecine : ses vues « orientée · à prendre / en cours / terminée »
 * suivent les vraies orientations, et chaque ligne dit ce qui a suivi la
 * Maternité — le médecin, la césarienne. La fin de prise en charge dit ce qui
 * vient ensuite.
 */
class MaternityQueueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_state_views_follow_the_real_orientations_and_do_not_overlap(): void
    {
        $midwife = $this->midwife();

        [$waitingEpisode] = $this->maternityOrientation($midwife);
        [$inProgressEpisode, $inProgress] = $this->maternityOrientation($midwife);
        $inProgress->accept($midwife);

        [$withDoctorEpisode, $withDoctor] = $this->maternityOrientation($midwife);
        $this->finish($withDoctor, $midwife, toMedicine: true);

        [$finishedEpisode, $finished] = $this->maternityOrientation($midwife);
        $this->finish($finished, $midwife, toMedicine: false);

        $counts = $this->counts($midwife);

        // « En attente » et « En cours » sont deux états, pas un.
        $this->assertSame(1, $counts['waiting']);
        $this->assertSame(1, $counts['in_progress']);
        $this->assertSame(2, $counts['completed']);
        // Les trois blocs ne se chevauchent pas : un passage n'est que dans l'un d'eux.
        $this->assertSame(4, $counts['waiting'] + $counts['in_progress'] + $counts['completed']);

        $this->assertSame([$waitingEpisode->uuid], $this->rows($midwife, 'waiting'));
        $this->assertSame([$inProgressEpisode->uuid], $this->rows($midwife, 'in_progress'));
        $this->assertEqualsCanonicalizing([$withDoctorEpisode->uuid, $finishedEpisode->uuid], $this->rows($midwife, 'completed'));
    }

    public function test_completing_with_medicine_opens_a_medicine_orientation_and_keeps_the_episode_in_care(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->maternityOrientation($midwife);

        $this->finish($orientation, $midwife, toMedicine: true, note: 'Céphalées persistantes, tension à contrôler.')
            ->assertRedirect('/maternity?view=completed');

        $medicine = EpisodeOrientation::query()
            ->where('episode_id', $episode->id)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();

        $this->assertSame(CatalogModule::Maternity, $medicine->source_module);
        $this->assertSame(EpisodeOrientationStatus::Pending, $medicine->status);
        $this->assertStringContainsString('Céphalées persistantes', $medicine->reason);
        // Le médecin a encore la patiente : elle n'attend pas la Réception.
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $this->assertSame([$episode->uuid], $this->rows($midwife, 'completed'));
        $this->assertSame('En attente du médecin', $this->followUp($midwife, $episode, 'completed')['medicine']['label']);
        $this->assertSame([], $this->rows($midwife, 'in_progress'));
    }

    public function test_the_doctor_follow_up_is_read_on_the_completed_row(): void
    {
        $midwife = $this->midwife();
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->maternityOrientation($midwife);
        $this->finish($orientation, $midwife, toMedicine: true);

        $medicine = EpisodeOrientation::query()->where('destination_module', 'MEDICINE')->sole();
        $medicine->accept($doctor);

        $this->assertSame('Vue par le médecin', $this->followUp($midwife, $episode, 'completed')['medicine']['label']);

        $medicine->fresh()->complete($doctor);

        // Personne ne l'attend plus : la ligne reste terminée, dossier consultable.
        $this->assertSame('Consultation terminée', $this->followUp($midwife, $episode, 'completed')['medicine']['label']);
        $this->assertSame(1, $this->counts($midwife)['completed']);
    }

    public function test_completing_without_a_follow_up_hands_the_episode_to_reception(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->maternityOrientation($midwife);

        $this->finish($orientation, $midwife, toMedicine: false)->assertRedirect('/maternity?view=completed');

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

        $followUp = $this->followUp($midwife, $episode, 'in_progress');
        $this->assertSame('PENDING', $followUp['cesarean']['status']);
        $this->assertSame('Césarienne demandée', $followUp['cesarean']['label']);

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

    public function test_an_unknown_view_falls_back_to_the_waiting_queue(): void
    {
        $midwife = $this->midwife();

        $this->actingAs($midwife)->get('/maternity?view=archives')
            ->assertInertia(fn ($page) => $page->where('view', 'waiting'));
    }

    /**
     * ADR-177 — une suggestion « Maternité » de la Réception n'ouvre aucune file :
     * la patiente est visible, suggérée, et la sage-femme la prend en charge par
     * un vrai geste. Tant que personne ne l'a fait, rien n'est créé.
     */
    public function test_a_passage_suggested_for_maternity_is_taken_in_charge_for_real(): void
    {
        $midwife = $this->midwife();
        $patient = Patient::query()->create([
            'patient_number' => 'A-26-4242', 'first_name' => 'Hanta', 'last_name' => 'Rabe', 'birth_date' => '1995-02-01', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $midwife);
        $episode->forceFill(['service_plan_finalized_at' => now()])->save();
        app(SetEpisodeReceptionNextStepsAction::class)->execute($episode, [ReceptionNextStep::Maternity->value], $midwife);

        $this->assertSame([$episode->uuid], $this->rows($midwife, 'suggested'));
        $this->assertSame(0, EpisodeOrientation::query()->count());
        $this->assertSame(0, MaternityRecord::query()->count());

        $this->actingAs($midwife)->post(route('maternity.passages.take-charge', $episode))->assertRedirect();

        $orientation = EpisodeOrientation::query()->sole();
        $this->assertSame(CatalogModule::Maternity, $orientation->destination_module);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->status);
        $this->assertSame($midwife->id, $orientation->accepted_by);
        // Prise en charge : la suggestion est lue, elle quitte la vue « suggérés ».
        $this->assertSame([], $this->rows($midwife, 'suggested'));
        $this->assertSame([$episode->uuid], $this->rows($midwife, 'in_progress'));
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

    /** @return list<string> les UUID des passages de la vue, dans l'ordre servi */
    private function rows(User $user, string $view): array
    {
        return collect($this->actingAs($user)->get("/maternity?view={$view}")
            ->viewData('page')['props']['passages']['data'])->pluck('uuid')->all();
    }

    /** @return array{medicine: ?array<string, mixed>, cesarean: ?array<string, mixed>} */
    private function followUp(User $user, Episode $episode, string $view): array
    {
        return $this->actingAs($user)->get("/maternity?view={$view}")
            ->viewData('page')['props']['followUps'][$episode->uuid];
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
