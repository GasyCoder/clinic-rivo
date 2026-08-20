<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\AssignSurgicalTeamMemberAction;
use App\Actions\Surgery\CreateAnesthesiaRecordAction;
use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Actions\Surgery\CreateSurgicalReportAction;
use App\Actions\Surgery\CreateSurgicalRequestAction;
use App\Actions\Surgery\DischargeSurgicalRequestAction;
use App\Actions\Surgery\RecordSurgicalCareNoteAction;
use App\Actions\Surgery\RecordSurgicalComplicationAction;
use App\Actions\Surgery\RecordSurgicalConsumableAction;
use App\Actions\Surgery\RemoveSurgicalConsumableAction;
use App\Actions\Surgery\RemoveSurgicalTeamMemberAction;
use App\Actions\Surgery\ScheduleSurgicalRequestAction;
use App\Actions\Surgery\UpdateSurgicalPreparationAction;
use App\Actions\Surgery\UpdateSurgicalRequestAction;
use App\Actions\Surgery\ValidateAnesthesiaRecordAction;
use App\Actions\Surgery\ValidatePreoperativeAssessmentAction;
use App\Actions\Surgery\ValidateSurgicalReportAction;
use App\Enums\SurgicalCarePhase;
use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTeamFunction;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\SurgicalConsumable;
use App\Models\SurgicalTeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurgicalActionsTest extends TestCase
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

    public function test_create_surgical_request_action_records_the_requester(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $episode = $this->makeEpisode();

        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($episode, [
            'procedure_name' => 'Appendicectomie',
        ]);

        $this->assertSame($user->id, $request->requested_by);
        $this->assertSame(SurgicalRequestStatus::Pending, $request->status);
    }

    public function test_update_action_records_preoperative_assessment(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $episode = $this->makeEpisode();
        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($episode, ['procedure_name' => 'Appendicectomie']);

        $updated = $this->app->make(UpdateSurgicalRequestAction::class)->execute($request, [
            'preoperative_notes' => 'ASA I, à jeun depuis 8h',
        ]);

        $this->assertSame('ASA I, à jeun depuis 8h', $updated->preoperative_notes);
        $this->assertSame($user->id, $updated->preoperative_assessed_by);
        $this->assertNotNull($updated->preoperative_assessed_at);
    }

    public function test_full_workflow_through_actions_reaches_discharged_and_locks_the_report(): void
    {
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $episode = $this->makeEpisode();

        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($episode, ['procedure_name' => 'Appendicectomie']);
        $this->app->make(UpdateSurgicalPreparationAction::class)->execute($request, 'Bloc 1', 'Matériel vérifié');
        $this->app->make(ScheduleSurgicalRequestAction::class)->execute($request, $doctor, '2026-09-01 08:00:00');
        $this->app->make(AssignSurgicalTeamMemberAction::class)->execute($request, $doctor, SurgicalTeamFunction::Surgeon);
        $this->app->make(UpdateSurgicalRequestAction::class)->execute($request, ['preoperative_notes' => 'ASA I']);
        $this->app->make(ValidatePreoperativeAssessmentAction::class)->execute($request, $doctor);

        $anesthesia = $this->app->make(CreateAnesthesiaRecordAction::class)->execute($request, ['notes' => 'AG standard']);
        $this->app->make(ValidateAnesthesiaRecordAction::class)->execute($anesthesia);

        $intervention = $this->app->make(CreateSurgicalInterventionAction::class)->execute($request, []);
        $this->assertSame(SurgicalRequestStatus::InProgress, $request->fresh()->status);

        $this->app->make(RecordSurgicalConsumableAction::class)->execute($request, 'Compresses stériles', 10, 'unités');
        $this->app->make(RecordSurgicalComplicationAction::class)->execute($request, 'Saignement mineur maîtrisé');
        $this->app->make(RecordSurgicalCareNoteAction::class)->execute($request, SurgicalCarePhase::Postoperative, 'Surveillance post-opératoire normale');

        $report = $this->app->make(CreateSurgicalReportAction::class)->execute($request, 'Intervention sans incident.');
        $this->app->make(ValidateSurgicalReportAction::class)->execute($report);

        $this->assertSame(SurgicalRequestStatus::Completed, $request->fresh()->status);
        $this->assertNotNull($report->fresh()->validated_at);

        // ValidateSurgicalReportAction completes the request through
        // $report->surgicalRequest — a separately loaded instance — so
        // $request's in-memory status is now stale; re-fetch before the
        // next transition, exactly as a fresh HTTP request would.
        $request = $request->fresh();
        $this->app->make(DischargeSurgicalRequestAction::class)->execute($request, $doctor, 'RAS');
        $this->assertSame(SurgicalRequestStatus::Discharged, $request->fresh()->status);

        $this->assertSame(1, $request->consumables()->count());
        $this->assertSame(1, $request->complications()->count());
        $this->assertSame(1, $request->careNotes()->count());
        $this->assertNotNull($intervention->id);
    }

    public function test_remove_team_member_action_deletes_and_audits_the_removal(): void
    {
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $episode = $this->makeEpisode();
        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($episode, ['procedure_name' => 'Appendicectomie']);
        $member = $this->app->make(AssignSurgicalTeamMemberAction::class)->execute($request, $doctor, SurgicalTeamFunction::Surgeon);

        $this->app->make(RemoveSurgicalTeamMemberAction::class)->execute($member);

        $this->assertSame(0, SurgicalTeamMember::query()->whereKey($member->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'delete')
            ->where('entity_type', SurgicalTeamMember::class)
            ->where('entity_id', $member->id)
            ->count());
    }

    public function test_remove_consumable_action_deletes_and_audits_the_removal(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $episode = $this->makeEpisode();
        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($episode, ['procedure_name' => 'Appendicectomie']);
        $consumable = $this->app->make(RecordSurgicalConsumableAction::class)
            ->execute($request, 'Compresses stériles', 10, 'unités');

        $this->app->make(RemoveSurgicalConsumableAction::class)->execute($consumable);

        $this->assertSame(0, SurgicalConsumable::query()->whereKey($consumable->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'delete')
            ->where('entity_type', SurgicalConsumable::class)
            ->where('entity_id', $consumable->id)
            ->count());
    }
}
