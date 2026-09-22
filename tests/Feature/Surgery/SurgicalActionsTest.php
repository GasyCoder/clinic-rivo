<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\AssignSurgicalTeamMemberAction;
use App\Actions\Surgery\CompleteSurgicalCaseAction;
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
use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTeamFunction;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SurgicalConsumable;
use App\Models\SurgicalTeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Surgery\Concerns\BuildsSurgicalCases;
use Tests\TestCase;

class SurgicalActionsTest extends TestCase
{
    use BuildsSurgicalCases, RefreshDatabase;

    /** ADR-168 — seul un compte au profil Chirurgien se programme comme chirurgien. */
    private function surgeon(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'SURGERY'], ['name' => 'Chirurgie']);
        $profile = ProfessionalProfile::query()->firstOrCreate(
            ['code' => 'SURGEON'],
            ['role_id' => $role->id, 'name' => 'Chirurgien / Chirurgienne', 'active' => true],
        );

        return User::factory()->create(['role_id' => $role->id, 'professional_profile_id' => $profile->id]);
    }

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
        $doctor = $this->surgeon();
        $this->actingAs($doctor);
        $episode = $this->makeEpisode();

        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($episode, ['procedure_name' => 'Appendicectomie']);
        $this->app->make(UpdateSurgicalPreparationAction::class)->execute($request, 'Bloc 1', 'Matériel vérifié');
        $this->app->make(ScheduleSurgicalRequestAction::class)->execute($request, $doctor, '2026-09-01 08:00:00');
        $this->app->make(AssignSurgicalTeamMemberAction::class)->execute($request, $doctor, SurgicalTeamFunction::Surgeon);
        $this->app->make(UpdateSurgicalRequestAction::class)->execute($request, ['preoperative_notes' => 'ASA I']);
        $this->app->make(ValidatePreoperativeAssessmentAction::class)->execute($request, $doctor);

        // ADR-170 — l'incision exige un anesthésiste affecté, une autorisation
        // prononcée et les deux premiers temps de la checklist confirmés.
        $this->grant($doctor, ['anesthesia.create', 'anesthesia.update', 'anesthesia.validate']);
        $anesthesia = $this->anesthesiaCleared($request->fresh(), $doctor);
        $this->completeChecklist($request->fresh(), SurgicalChecklistPhase::SignIn, $doctor, $doctor);
        $this->completeChecklist($request->fresh(), SurgicalChecklistPhase::TimeOut, $doctor, $doctor);

        $intervention = $this->app->make(CreateSurgicalInterventionAction::class)
            ->execute($request->fresh(), [], $doctor->fresh());
        $this->assertSame(SurgicalRequestStatus::InProgress, $request->fresh()->status);
        $intervention->update(['ended_at' => now()]);

        $this->app->make(ValidateAnesthesiaRecordAction::class)->execute($anesthesia->fresh(), $doctor->fresh());

        $this->app->make(RecordSurgicalConsumableAction::class)->execute($request, 'Compresses stériles', 10, 'unités');
        $this->app->make(RecordSurgicalComplicationAction::class)->execute($request, 'Saignement mineur maîtrisé');
        $this->app->make(RecordSurgicalCareNoteAction::class)->execute($request, SurgicalCarePhase::Postoperative, 'Surveillance post-opératoire normale');

        $report = $this->app->make(CreateSurgicalReportAction::class)->execute($request, 'Intervention sans incident.');
        $this->app->make(ValidateSurgicalReportAction::class)->execute($report);

        // ADR-170 — valider le compte rendu ne clôt plus le dossier : il reste
        // au bloc tant que le SIGN OUT et la sortie du bloc manquent.
        $this->assertSame(SurgicalRequestStatus::InProgress, $request->fresh()->status);
        $this->assertNotNull($report->fresh()->validated_at);

        $this->completeChecklist($request->fresh(), SurgicalChecklistPhase::SignOut, $doctor, $doctor);
        $request->blockExit()->create(['recorded_by' => $doctor->id, 'left_at' => now()]);
        $this->app->make(CompleteSurgicalCaseAction::class)->execute($request->fresh(), $doctor->fresh());

        $this->assertSame(SurgicalRequestStatus::Completed, $request->fresh()->status);

        $request = $request->fresh();
        $this->app->make(DischargeSurgicalRequestAction::class)->execute($request, $doctor, 'RAS');
        $this->assertSame(SurgicalRequestStatus::Discharged, $request->fresh()->status);

        $this->assertSame(1, $request->consumables()->count());
        $this->assertSame(1, $request->complications()->count());
        $this->assertSame(1, $request->careNotes()->count());
        $this->assertNotNull($intervention->id);
    }

    public function test_validating_a_report_before_the_intervention_leaves_nothing_half_validated(): void
    {
        // Avant : le compte rendu était enregistré « validé », puis la clôture de
        // l'intervention échouait (erreur 500) — un compte rendu que plus personne
        // ne pouvait corriger, sur un dossier que plus personne ne pouvait clore.
        $doctor = $this->surgeon();
        $this->actingAs($doctor);
        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($this->makeEpisode(), ['procedure_name' => 'Appendicectomie']);
        $this->app->make(ScheduleSurgicalRequestAction::class)->execute($request, $doctor, '2026-09-01 08:00:00');
        $report = $this->app->make(CreateSurgicalReportAction::class)->execute($request, 'Rédigé trop tôt.');

        try {
            $this->app->make(ValidateSurgicalReportAction::class)->execute($report);
            $this->fail('La validation aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('report', $exception->errors());
        }

        $this->assertNull($report->fresh()->validated_at);
        $this->assertSame(SurgicalRequestStatus::Scheduled, $request->fresh()->status);
    }

    public function test_a_validated_report_is_not_validated_twice(): void
    {
        $doctor = $this->surgeon();
        $this->actingAs($doctor);
        $request = $this->app->make(CreateSurgicalRequestAction::class)->execute($this->makeEpisode(), ['procedure_name' => 'Appendicectomie']);
        $this->app->make(ScheduleSurgicalRequestAction::class)->execute($request, $doctor, '2026-09-01 08:00:00');
        $this->app->make(UpdateSurgicalRequestAction::class)->execute($request, ['preoperative_notes' => 'ASA I']);
        $this->app->make(ValidatePreoperativeAssessmentAction::class)->execute($request, $doctor);
        $this->grant($doctor, ['anesthesia.create', 'anesthesia.update', 'anesthesia.validate']);
        $this->anesthesiaCleared($request->fresh(), $doctor);
        $this->completeChecklist($request->fresh(), SurgicalChecklistPhase::SignIn, $doctor, $doctor);
        $this->completeChecklist($request->fresh(), SurgicalChecklistPhase::TimeOut, $doctor, $doctor);
        $this->app->make(CreateSurgicalInterventionAction::class)->execute($request->fresh(), [], $doctor->fresh());
        $report = $this->app->make(CreateSurgicalReportAction::class)->execute($request, 'Sans incident.');
        $validated = $this->app->make(ValidateSurgicalReportAction::class)->execute($report);

        // ADR-170 — le dossier reste au bloc : la clôture est un geste à part.
        $this->assertSame(SurgicalRequestStatus::InProgress, $validated->surgicalRequest->status);

        $this->expectException(ValidationException::class);
        $this->app->make(ValidateSurgicalReportAction::class)->execute($report);
    }

    public function test_remove_team_member_action_deletes_and_audits_the_removal(): void
    {
        $doctor = $this->surgeon();
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
