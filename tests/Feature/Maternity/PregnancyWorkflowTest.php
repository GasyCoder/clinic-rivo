<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\PregnancyStatus;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Pregnancy;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Maternity\PregnancyDatingService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PregnancyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_three_passages_explicitly_continue_one_pregnancy_and_keep_distinct_snapshots(): void
    {
        $midwife = $this->midwife();
        $patient = $this->patient();
        [, $first] = $this->consultation($patient, $midwife, '2026-03-03 09:00:00');

        $this->actingAs($midwife)->put("/maternity/orientations/{$first->uuid}/record", [
            'pregnancy_choice' => 'CREATE',
            'pregnancy_data' => [
                'last_menstrual_period' => '2025-12-22',
                // Une valeur navigateur différente ne devient jamais la source de vérité.
                'estimated_due_date' => '2026-10-10',
                'gravidity' => 2,
                'parity' => 1,
                'risk_factors' => 'Antécédent déclaré',
            ],
            'prenatal_data' => ['gestational_age_weeks' => 40, 'fundal_height_cm' => 11, 'fetal_heart_rate' => 142],
        ])->assertSessionHasNoErrors();

        $pregnancy = Pregnancy::query()->sole();
        $firstRecord = MaternityRecord::query()->sole();
        $firstSnapshot = [$firstRecord->gestational_age_weeks, $firstRecord->gestational_age_days];
        $expected = app(PregnancyDatingService::class)->gestationalAge($pregnancy, '2026-03-03');

        $this->assertSame('2026-09-28', $pregnancy->estimated_due_date->toDateString());
        $this->assertSame([$expected['weeks'], $expected['days']], $firstSnapshot);
        $this->assertSame($expected['weeks'], $firstRecord->prenatal_data['gestational_age_weeks']);

        [, $second] = $this->consultation($patient, $midwife, '2026-04-04 10:00:00');
        $this->actingAs($midwife)->put("/maternity/orientations/{$second->uuid}/record", [
            'pregnancy_choice' => 'CONTINUE',
            'pregnancy_uuid' => $pregnancy->uuid,
            'prenatal_data' => ['fundal_height_cm' => 15, 'fetal_heart_rate' => 140],
        ])->assertSessionHasNoErrors();

        [, $third] = $this->consultation($patient, $midwife, '2026-05-05 11:00:00');
        $this->actingAs($midwife)->put("/maternity/orientations/{$third->uuid}/record", [
            'pregnancy_choice' => 'CONTINUE',
            'pregnancy_uuid' => $pregnancy->uuid,
            'prenatal_data' => ['fundal_height_cm' => 19, 'fetal_heart_rate' => 144],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Pregnancy::query()->count());
        $this->assertSame(3, MaternityRecord::query()->count());
        $this->assertSame([$pregnancy->id], MaternityRecord::query()->distinct()->pluck('pregnancy_id')->all());

        $this->actingAs($midwife)->get("/maternity/orientations/{$third->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pregnancy.uuid', $pregnancy->uuid)
                ->where('pregnancy.consultations_count', 3)
                ->has('pregnancyHistory', 3)
                ->where('pregnancyHistory.0.episode_number', $first->episode->episode_number)
                ->where('pregnancyHistory.2.is_current', true)
                ->has('prenatalComparison.fields'));

        // Une correction explicite de la DPA ne réécrit aucun snapshot ancien.
        $this->actingAs($midwife)->put("/maternity/orientations/{$third->uuid}/pregnancy/dating", [
            'dating_method' => 'MANUAL_CORRECTION',
            'estimated_due_date' => '2026-10-02',
            'reason' => 'Datation échographique confirmée.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-02', $pregnancy->fresh()->estimated_due_date->toDateString());
        $this->assertSame($firstSnapshot, [
            $firstRecord->fresh()->gestational_age_weeks,
            $firstRecord->fresh()->gestational_age_days,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'maternity.pregnancy.dating.correct',
            'entity_uuid' => $pregnancy->uuid,
            'reason' => 'Datation échographique confirmée.',
        ]);
    }

    public function test_delivery_uses_the_clinical_time_closes_the_pregnancy_and_future_care_creates_another(): void
    {
        $midwife = $this->midwife();
        $patient = $this->patient();
        [, $delivery] = $this->consultation($patient, $midwife, '2026-09-20 04:00:00');

        $this->actingAs($midwife)->put("/maternity/orientations/{$delivery->uuid}/record", [
            'pregnancy_choice' => 'CREATE',
            'pregnancy_data' => ['last_menstrual_period' => '2025-12-22', 'gravidity' => 2, 'parity' => 1],
            'delivery_data' => ['occurred_at' => '2026-09-20T06:40', 'mode' => 'VAGINAL'],
            'newborn_data' => ['newborns' => [
                ['sex' => 'F', 'birth_weight_g' => 2800],
                ['sex' => 'M', 'birth_weight_g' => 3000],
            ]],
        ])->assertSessionHasNoErrors();

        $firstPregnancy = Pregnancy::query()->sole();
        $this->actingAs($midwife)->post("/maternity/orientations/{$delivery->uuid}/complete")
            ->assertSessionHasNoErrors();

        $firstPregnancy->refresh();
        $this->assertSame(PregnancyStatus::Delivered, $firstPregnancy->status);
        $this->assertSame('2026-09-20 06:40:00', $firstPregnancy->delivered_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, Pregnancy::query()->count(), 'Des jumeaux restent une seule grossesse.');

        [, $future] = $this->consultation($patient, $midwife, '2026-09-25 09:00:00');
        $this->actingAs($midwife)->get("/maternity/orientations/{$future->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activePregnancies', 0)
                ->where('pregnancySelectionRequired', true)
                ->has('previousPregnancies', 1));

        $this->actingAs($midwife)->put("/maternity/orientations/{$future->uuid}/record", [
            'pregnancy_choice' => 'CREATE',
            'pregnancy_data' => ['last_menstrual_period' => '2026-09-21', 'gravidity' => 3, 'parity' => 2],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Pregnancy::query()->count());
        $this->assertNotSame(
            $firstPregnancy->id,
            MaternityRecord::query()->where('episode_orientation_id', $future->id)->value('pregnancy_id'),
        );
    }

    public function test_pregnancy_choice_is_required_and_repeated_create_submission_does_not_duplicate(): void
    {
        $midwife = $this->midwife();
        $patient = $this->patient();
        [, $orientation] = $this->consultation($patient, $midwife, '2026-09-24 08:00:00');

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'obstetric_context' => 'Première consultation.',
        ])->assertSessionHasErrors('pregnancy_choice');
        $this->assertSame(0, Pregnancy::query()->count());

        $payload = [
            'pregnancy_choice' => 'CREATE',
            'pregnancy_data' => ['last_menstrual_period' => '2026-01-01'],
            'obstetric_context' => 'Première consultation.',
        ];
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", $payload)->assertSessionHasNoErrors();
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Pregnancy::query()->count());
        $this->assertSame(1, MaternityRecord::query()->count());

        // Un autre passage ne peut pas créer silencieusement une deuxième active.
        [, $other] = $this->consultation($patient, $midwife, '2026-09-25 08:00:00');
        $this->actingAs($midwife)->put("/maternity/orientations/{$other->uuid}/record", $payload)
            ->assertSessionHasErrors('pregnancy_choice');
        $this->assertSame(1, Pregnancy::query()->count());
    }

    public function test_history_is_permission_guarded_and_legacy_unlinked_records_remain_readable(): void
    {
        $midwife = $this->midwife();
        $patient = $this->patient();
        [$episode, $orientation] = $this->consultation($patient, $midwife, '2026-09-24 08:00:00');
        MaternityRecord::query()->create([
            'episode_id' => $episode->id,
            'episode_orientation_id' => $orientation->id,
            'pregnancy_data' => ['gravidity' => 1, 'parity' => 0],
            'created_by' => $midwife->id,
            'updated_by' => $midwife->id,
        ]);

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('record.pregnancy_id', null)
                ->where('pregnancySelectionRequired', true));

        $unauthorized = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
        ]);
        $this->actingAs($unauthorized)->get("/maternity/orientations/{$orientation->uuid}")->assertForbidden();
    }

    /** @return array{Episode, EpisodeOrientation} */
    private function consultation(Patient $patient, User $midwife, string $startedAt): array
    {
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $midwife);
        $episode->forceFill(['started_at' => $startedAt])->saveQuietly();
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode,
            CatalogModule::Reception,
            CatalogModule::Maternity,
            $midwife,
            'Suivi obstétrical',
        );
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept")->assertRedirect();

        return [$episode->fresh(), $orientation->fresh(['episode'])];
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Marie',
            'last_name' => 'Rakoto',
            'birth_date' => '1994-06-10',
            'sex' => 'F',
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
