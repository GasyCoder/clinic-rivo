<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\CreateAnesthesiaRecordAction;
use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Actions\Surgery\CreateSurgicalReportAction;
use App\Actions\Surgery\RecordSurgicalPostoperativeObservationAction;
use App\Actions\Surgery\RecordSurgicalTreatmentItemAction;
use App\Actions\Surgery\UpdateAnesthesiaRecordAction;
use App\Actions\Surgery\UpdateSurgicalBlockEntryAction;
use App\Actions\Surgery\UpdateSurgicalBlockExitAction;
use App\Actions\Surgery\UpdateSurgicalInterventionAction;
use App\Actions\Surgery\UpdateSurgicalReportAction;
use App\Actions\Surgery\UpdateSurgicalRequestAction;
use App\Actions\Surgery\ValidateAnesthesiaRecordAction;
use App\Actions\Surgery\ValidateSurgicalReportAction;
use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTreatmentCategory;
use App\Enums\SurgicalTreatmentPhase;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SurgicalBlockFormsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function actor(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', 'SURGERY')->value('id'),
        ]);
    }

    private function surgicalRequest(?User $actor = null): SurgicalRequest
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        $episode = Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
        ]);

        return $episode->surgicalRequests()->create([
            'requested_by' => $actor?->id,
            'created_by' => $actor?->id,
            'status' => SurgicalRequestStatus::Pending,
            'procedure_name' => 'Appendicectomie',
        ]);
    }

    private function startIntervention(SurgicalRequest $request, User $actor): void
    {
        $request->schedule($actor, now()->addDay()->toDateTimeString());
        $request->validatePreoperative($actor);
        $this->app->make(CreateSurgicalInterventionAction::class)->execute($request->fresh(), []);
    }

    public function test_entry_form_is_structured_and_audited(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $request = $this->surgicalRequest($actor);

        $entry = $this->app->make(UpdateSurgicalBlockEntryAction::class)->execute($request, [
            'height_cm' => 172,
            'weight_kg' => 68.5,
            'temperature_celsius' => 36.8,
            'blood_pressure_systolic' => 120,
            'blood_pressure_diastolic' => 80,
            'heart_rate' => 72,
            'oxygen_saturation' => 98,
            'full_bath_completed' => true,
            'peripheral_iv_count' => 1,
            'urinary_catheter_placed' => false,
        ], $actor);

        $this->assertSame('172.00', $entry->height_cm);
        $this->assertSame(120, $entry->blood_pressure_systolic);
        $this->assertTrue($entry->full_bath_completed);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'surgery',
            'action' => 'create',
            'entity_id' => $entry->id,
        ]);
    }

    public function test_exit_and_postoperative_data_follow_the_surgical_lifecycle(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $request = $this->surgicalRequest($actor);

        try {
            $this->app->make(UpdateSurgicalBlockExitAction::class)->execute($request, [], $actor);
            $this->fail('The exit form must not open before intervention starts.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('surgical_block_exits', 0);
        }

        $this->startIntervention($request, $actor);
        $request = $request->fresh();

        $exit = $this->app->make(UpdateSurgicalBlockExitAction::class)->execute($request, [
            'block_entered_at' => now()->subHour(),
            'block_exited_at' => now(),
            'entry_oxygen_saturation' => 98,
            'exit_oxygen_saturation' => 97,
            'awakening_status' => 'PERFECTLY_AWAKE',
            'awakening_score' => '10',
        ], $actor);

        $observation = $this->app->make(RecordSurgicalPostoperativeObservationAction::class)->execute($request, [
            'observed_at' => now()->addHour(),
            'temperature_celsius' => 37.1,
            'heart_rate' => 78,
            'oxygen_saturation' => 99,
        ], $actor);

        $treatment = $this->app->make(RecordSurgicalTreatmentItemAction::class)->execute(
            $request,
            SurgicalTreatmentPhase::Postoperative,
            SurgicalTreatmentCategory::Antibiotic,
            ['label' => 'Antibiotique prescrit', 'quantity' => 1, 'unit' => 'dose'],
            $actor,
        );

        $this->assertSame('PERFECTLY_AWAKE', $exit->awakening_status->value);
        $this->assertSame(78, $observation->heart_rate);
        $this->assertSame(SurgicalTreatmentPhase::Postoperative, $treatment->phase);
    }

    public function test_validated_clinical_records_are_immutable_in_backend_actions(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $request = $this->surgicalRequest($actor);
        $request->schedule($actor, now()->addDay()->toDateTimeString());
        $this->app->make(UpdateSurgicalRequestAction::class)->execute($request, ['preoperative_notes' => 'Bilan confirmé']);
        $request->validatePreoperative($actor);

        $this->expectException(ValidationException::class);
        $this->app->make(UpdateSurgicalRequestAction::class)->execute($request->fresh(), ['preoperative_notes' => 'Altération']);
    }

    public function test_validated_anesthesia_and_report_cannot_be_modified(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $request = $this->surgicalRequest($actor);
        $request->schedule($actor, now()->addDay()->toDateTimeString());
        $request->validatePreoperative($actor);

        $anesthesia = $this->app->make(CreateAnesthesiaRecordAction::class)->execute($request, ['notes' => 'Initial']);
        $this->app->make(ValidateAnesthesiaRecordAction::class)->execute($anesthesia);

        try {
            $this->app->make(UpdateAnesthesiaRecordAction::class)->execute($anesthesia->fresh(), ['notes' => 'Altération']);
            $this->fail('Validated anesthesia must be immutable.');
        } catch (ValidationException) {
            $this->assertSame('Initial', $anesthesia->fresh()->notes);
        }

        $this->app->make(CreateSurgicalInterventionAction::class)->execute($request->fresh(), []);
        $report = $this->app->make(CreateSurgicalReportAction::class)->execute($request, 'Compte rendu initial');
        $this->app->make(ValidateSurgicalReportAction::class)->execute($report);

        try {
            $this->app->make(UpdateSurgicalReportAction::class)->execute($report->fresh(), 'Altération');
            $this->fail('Validated report must be immutable.');
        } catch (ValidationException) {
            $this->assertSame('Compte rendu initial', $report->fresh()->content);
        }

        $this->expectException(ValidationException::class);
        $this->app->make(UpdateSurgicalInterventionAction::class)->execute(
            $request->fresh()->intervention,
            ['notes' => 'Altération après clôture'],
        );
    }

    public function test_nested_anesthesia_record_from_another_case_returns_not_found(): void
    {
        $actor = $this->actor();
        $actor->permissions()->attach(
            Permission::query()->where('name', 'anesthesia.update')->value('id'),
            ['effect' => 'allow'],
        );
        $this->actingAs($actor);
        $first = $this->surgicalRequest($actor);
        $second = $this->surgicalRequest($actor);
        $record = $this->app->make(CreateAnesthesiaRecordAction::class)->execute($second, ['notes' => 'Second dossier']);

        $this->put("/surgery/{$first->uuid}/anesthesia/{$record->id}", [
            'notes' => 'Tentative croisée',
        ])->assertNotFound();

        $this->assertSame('Second dossier', $record->fresh()->notes);
    }

    public function test_inactive_users_are_not_selectable_or_accepted_for_scheduling(): void
    {
        $actor = $this->actor();
        $inactive = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SURGERY')->value('id'),
            'active' => false,
            'deactivated_at' => now(),
        ]);
        $request = $this->surgicalRequest($actor);

        $this->actingAs($actor)
            ->get("/surgery/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Surgery/Show')
                ->where('users', fn ($users) => collect($users)->pluck('id')->contains($inactive->id) === false));

        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/schedule", [
                'surgeon_id' => $inactive->id,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('surgeon_id');
    }
}
