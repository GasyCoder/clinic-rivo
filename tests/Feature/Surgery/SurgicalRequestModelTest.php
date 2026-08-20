<?php

namespace Tests\Feature\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Exceptions\InvalidSurgicalRequestTransitionException;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurgicalRequestModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeEpisode(): Episode
    {
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
        ]);
    }

    private function makeSurgicalRequest(array $overrides = []): SurgicalRequest
    {
        $episode = $overrides['episode'] ?? $this->makeEpisode();
        unset($overrides['episode']);

        return $episode->surgicalRequests()->create([
            'status' => SurgicalRequestStatus::Pending,
            'procedure_name' => 'Appendicectomie',
            ...$overrides,
        ]);
    }

    public function test_status_and_datetimes_are_cast(): void
    {
        $request = $this->makeSurgicalRequest();

        $this->assertSame(SurgicalRequestStatus::Pending, $request->status);
    }

    public function test_belongs_to_an_episode(): void
    {
        $episode = $this->makeEpisode();
        $request = $this->makeSurgicalRequest(['episode' => $episode]);

        $this->assertTrue($request->episode->is($episode));
    }

    public function test_creating_a_surgical_request_is_audited_under_the_surgery_module(): void
    {
        $request = $this->makeSurgicalRequest();

        $log = AuditLog::where('action', 'create')
            ->where('entity_type', SurgicalRequest::class)
            ->where('entity_id', $request->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('surgery', $log->module);
    }

    public function test_creating_a_surgical_request_assigns_a_uuid(): void
    {
        $request = $this->makeSurgicalRequest();

        $this->assertNotNull($request->uuid);
    }

    public function test_schedule_moves_pending_to_scheduled(): void
    {
        $request = $this->makeSurgicalRequest();
        $surgeon = User::factory()->create();

        $request->schedule($surgeon, '2026-09-01 08:00:00');

        $this->assertSame(SurgicalRequestStatus::Scheduled, $request->fresh()->status);
        $this->assertSame($surgeon->id, $request->fresh()->surgeon_id);
    }

    public function test_schedule_called_again_corrects_surgeon_and_date_without_changing_status(): void
    {
        $request = $this->makeSurgicalRequest();
        $surgeon = User::factory()->create();
        $correctedSurgeon = User::factory()->create();
        $request->schedule($surgeon, '2026-09-01 08:00:00');

        $request->schedule($correctedSurgeon, '2026-09-02 09:00:00');

        $this->assertSame(SurgicalRequestStatus::Scheduled, $request->fresh()->status);
        $this->assertSame($correctedSurgeon->id, $request->fresh()->surgeon_id);
    }

    public function test_schedule_still_works_as_a_correction_once_preoperative_validated(): void
    {
        $request = $this->makeSurgicalRequest();
        $surgeon = User::factory()->create();
        $correctedSurgeon = User::factory()->create();
        $request->schedule($surgeon, '2026-09-01 08:00:00');
        $request->validatePreoperative($surgeon);

        $request->schedule($correctedSurgeon, '2026-09-02 09:00:00');

        $this->assertSame(SurgicalRequestStatus::PreoperativeValidated, $request->fresh()->status);
        $this->assertSame($correctedSurgeon->id, $request->fresh()->surgeon_id);
    }

    public function test_schedule_is_rejected_once_the_intervention_has_started(): void
    {
        $request = $this->makeSurgicalRequest();
        $surgeon = User::factory()->create();
        $request->schedule($surgeon, '2026-09-01 08:00:00');
        $request->validatePreoperative($surgeon);
        $request->startIntervention();

        $this->expectException(InvalidSurgicalRequestTransitionException::class);

        $request->schedule($surgeon, '2026-09-02 08:00:00');
    }

    public function test_validate_preoperative_requires_scheduled_status(): void
    {
        $request = $this->makeSurgicalRequest();
        $validator = User::factory()->create();

        $this->expectException(InvalidSurgicalRequestTransitionException::class);

        $request->validatePreoperative($validator);
    }

    public function test_full_lifecycle_reaches_discharged(): void
    {
        $request = $this->makeSurgicalRequest();
        $user = User::factory()->create();

        $request->schedule($user, '2026-09-01 08:00:00');
        $request->validatePreoperative($user);
        $request->startIntervention();
        $this->assertSame(SurgicalRequestStatus::InProgress, $request->fresh()->status);

        $request->complete();
        $this->assertSame(SurgicalRequestStatus::Completed, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->completed_at);

        $request->discharge($user, 'Sortie sans complication');
        $this->assertSame(SurgicalRequestStatus::Discharged, $request->fresh()->status);
        $this->assertSame($user->id, $request->fresh()->discharged_by);
    }

    public function test_discharge_requires_completed_status(): void
    {
        $request = $this->makeSurgicalRequest();
        $user = User::factory()->create();

        $this->expectException(InvalidSurgicalRequestTransitionException::class);

        $request->discharge($user, null);
    }
}
