<?php

namespace Tests\Feature\Surgery;

use App\Actions\Episode\CreateEpisodeAction;
use App\Enums\AnesthesiaCaseStage;
use App\Enums\SurgicalRequestStatus;
use App\Models\AnesthesiaRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-135 — la file Anesthésie suit le dossier de l'évaluation au bloc puis à
 * la fin. Les étapes sont lues sur des faits existants, exclusives et
 * complètes : leur somme est le nombre de dossiers non annulés.
 */
class AnesthesiaQueueStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_every_case_falls_in_exactly_one_stage_and_the_counts_add_up(): void
    {
        $anesthetist = $this->anesthetist();

        $noRecord = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Scheduled);
        $draft = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Pending, record: []);
        $cleared = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::PreoperativeValidated, record: ['assessment_validated_at' => now()]);
        $intra = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::InProgress, record: ['assessment_validated_at' => now()]);
        $closedRecord = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::InProgress, record: ['assessment_validated_at' => now(), 'validated_at' => now()]);
        $completed = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Completed);
        $discharged = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Discharged, record: ['assessment_validated_at' => now()]);
        $cancelled = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Cancelled);

        $expected = [
            [$noRecord, AnesthesiaCaseStage::Assess],
            [$draft, AnesthesiaCaseStage::Assess],
            [$cleared, AnesthesiaCaseStage::Cleared],
            [$intra, AnesthesiaCaseStage::Intra],
            [$closedRecord, AnesthesiaCaseStage::Done],
            [$completed, AnesthesiaCaseStage::Done],
            [$discharged, AnesthesiaCaseStage::Done],
        ];

        foreach ($expected as [$request, $stage]) {
            // La ligne et la contrainte SQL disent la même chose.
            $this->assertSame($stage, AnesthesiaCaseStage::of($request->fresh(['anesthesiaRecord'])), "ligne {$request->id}");
            $this->assertTrue($stage->constrain(SurgicalRequest::query())->whereKey($request->id)->exists(), "SQL {$request->id}");

            foreach (AnesthesiaCaseStage::cases() as $other) {
                if ($other !== $stage) {
                    $this->assertFalse($other->constrain(SurgicalRequest::query())->whereKey($request->id)->exists(), "{$request->id} ne doit pas être aussi en {$other->value}");
                }
            }
        }

        // Une demande annulée n'a jamais eu de travail d'anesthésie.
        foreach (AnesthesiaCaseStage::cases() as $stage) {
            $this->assertFalse($stage->constrain(SurgicalRequest::query())->whereKey($cancelled->id)->exists());
        }

        $counts = $this->actingAs($anesthetist)->get('/anesthesia')->viewData('page')['props']['counts'];
        $this->assertSame(['assess' => 2, 'cleared' => 1, 'intra' => 1, 'done' => 3], $counts);
        $this->assertSame(7, array_sum($counts));
    }

    public function test_the_page_lists_only_the_chosen_stage_and_defaults_to_the_work_to_do(): void
    {
        $anesthetist = $this->anesthetist();
        $todo = $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Scheduled, procedure: 'Hernie inguinale');
        $this->surgicalRequest($anesthetist, SurgicalRequestStatus::PreoperativeValidated, record: ['assessment_validated_at' => now()], procedure: 'Cholécystectomie');

        $props = $this->actingAs($anesthetist)->get('/anesthesia')->viewData('page')['props'];
        $this->assertSame('assess', $props['stage']);
        $this->assertSame([$todo->uuid], collect($props['surgicalRequests']['data'])->pluck('uuid')->all());
        $this->assertSame('assess', $props['surgicalRequests']['data'][0]['stage']);
        $this->assertSame('Évaluation à faire', $props['surgicalRequests']['data'][0]['stage_label']);

        $cleared = $this->actingAs($anesthetist)->get('/anesthesia?stage=cleared')->viewData('page')['props'];
        $this->assertSame('cleared', $cleared['stage']);
        $this->assertSame('Cholécystectomie', $cleared['surgicalRequests']['data'][0]['procedure_name']);
        $this->assertSame('Prêt pour Chirurgie', $cleared['surgicalRequests']['data'][0]['stage_label']);
    }

    public function test_an_unknown_stage_falls_back_to_the_work_to_do(): void
    {
        $this->assertSame(AnesthesiaCaseStage::Assess, AnesthesiaCaseStage::fromQuery('archives'));
        $this->assertSame(AnesthesiaCaseStage::Assess, AnesthesiaCaseStage::fromQuery(null));
        $this->assertSame(AnesthesiaCaseStage::Done, AnesthesiaCaseStage::fromQuery('done'));
    }

    /** Les cartes disent combien de dossiers existent : elles ne bougent pas à chaque frappe. */
    public function test_counts_do_not_follow_the_search(): void
    {
        $anesthetist = $this->anesthetist();
        $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Scheduled, procedure: 'Hernie inguinale');
        $this->surgicalRequest($anesthetist, SurgicalRequestStatus::Scheduled, procedure: 'Appendicectomie');

        $props = $this->actingAs($anesthetist)->get('/anesthesia?q=Hernie')->viewData('page')['props'];

        $this->assertCount(1, $props['surgicalRequests']['data']);
        $this->assertSame(2, $props['counts']['assess']);
    }

    /** @param  array<string, mixed>|null  $record  null = aucun dossier d'anesthésie */
    private function surgicalRequest(User $actor, SurgicalRequestStatus $status, ?array $record = null, string $procedure = 'Intervention'): SurgicalRequest
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'birth_date' => '1990-01-01', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $actor);
        $request = SurgicalRequest::query()->create([
            'episode_id' => $episode->id,
            'requested_by' => $actor->id,
            'procedure_name' => $procedure,
            'created_by' => $actor->id,
        ]);
        $request->forceFill(['status' => $status])->save();

        if ($record !== null) {
            AnesthesiaRecord::query()->create(['surgical_request_id' => $request->id, 'anesthetist_id' => $actor->id] + $record);
        }

        return $request->fresh();
    }

    private function anesthetist(): User
    {
        $profile = ProfessionalProfile::query()->where('code', 'ANESTHETIST')->firstOrFail();
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
