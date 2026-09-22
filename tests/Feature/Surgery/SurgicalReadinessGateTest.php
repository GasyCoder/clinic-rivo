<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\CompleteSurgicalCaseAction;
use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Actions\Surgery\CreateSurgicalReportAction;
use App\Actions\Surgery\DecideAnesthesiaClearanceAction;
use App\Actions\Surgery\ResolveAnesthesiaClearanceConditionAction;
use App\Actions\Surgery\SaveSurgicalChecklistAction;
use App\Actions\Surgery\UpdateAnesthesiaRecordAction;
use App\Actions\Surgery\ValidateAnesthesiaRecordAction;
use App\Actions\Surgery\ValidateSurgicalReportAction;
use App\Enums\AnesthesiaClearanceConditionStatus;
use App\Enums\AnesthesiaClearanceStatus;
use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalChecklistRole;
use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalIntervention;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Surgery\SurgicalReadinessGate;
use App\Support\SurgicalSafetyChecklistItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Surgery\Concerns\BuildsSurgicalCases;
use Tests\TestCase;

/**
 * ADR-170 — ce qui retient l'incision, et ce qui ne la retient pas.
 *
 * Chaque test retire **un** élément d'un dossier par ailleurs complet : c'est
 * ce qui rend lisible quel checkpoint est réellement opposable.
 */
class SurgicalReadinessGateTest extends TestCase
{
    use BuildsSurgicalCases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic']);
    }

    private function gate(): SurgicalReadinessGate
    {
        return $this->app->make(SurgicalReadinessGate::class);
    }

    /** @return array<int, string> */
    private function blockerKeys(SurgicalRequest $case): array
    {
        return array_column($this->gate()->blockersForIncision($case->fresh()), 'key');
    }

    private function start(SurgicalRequest $case, User $actor, array $data = []): SurgicalIntervention
    {
        return $this->app->make(CreateSurgicalInterventionAction::class)->execute($case->fresh(), $data, $actor);
    }

    // ── A — aucun anesthésiste affecté ──────────────────────────────────

    public function test_a_case_without_an_anesthetist_cannot_start(): void
    {
        $surgeon = $this->surgeonUser();
        $case = $this->preparedCase($surgeon);

        $this->assertContains('anesthetist_missing', $this->blockerKeys($case));
        $this->assertFalse($this->gate()->canStartIntervention($case->fresh()));
    }

    // ── B — aucune décision prononcée ───────────────────────────────────

    public function test_an_assessment_validated_without_a_decision_does_not_authorize_the_block(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);

        // Évaluation validée, mais personne n'a prononcé l'autorisation :
        // une absence n'est jamais une décision.
        $this->anesthesiaCleared($case, $anesthetist, AnesthesiaClearanceStatus::Draft);
        $this->completeChecklist($case, SurgicalChecklistPhase::SignIn, $surgeon, $anesthetist);
        $this->completeChecklist($case, SurgicalChecklistPhase::TimeOut, $surgeon, $anesthetist);

        $this->assertContains('clearance_draft', $this->blockerKeys($case));
    }

    // ── C / D — refus et report ─────────────────────────────────────────

    public function test_a_refused_clearance_blocks_and_shows_its_reason(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $this->anesthesiaCleared($case, $anesthetist, AnesthesiaClearanceStatus::NotCleared);
        $this->completeChecklist($case, SurgicalChecklistPhase::SignIn, $surgeon, $anesthetist);
        $this->completeChecklist($case, SurgicalChecklistPhase::TimeOut, $surgeon, $anesthetist);

        $blockers = $this->gate()->blockersForIncision($case->fresh());
        $refusal = collect($blockers)->firstWhere('key', 'clearance_not_cleared');

        $this->assertNotNull($refusal);
        $this->assertSame(SurgicalReadinessGate::OWNER_ANESTHESIA, $refusal['owner']);
        $this->assertStringContainsString('Motif : Motif consigné pour le test.', (string) $refusal['hint']);
    }

    public function test_a_deferred_clearance_blocks(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $this->anesthesiaCleared($case, $anesthetist, AnesthesiaClearanceStatus::Deferred);

        $this->assertContains('clearance_deferred', $this->blockerKeys($case));
    }

    // ── E — conditions ouvertes ─────────────────────────────────────────

    public function test_an_open_condition_blocks_until_it_is_resolved(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);
        $record = $case->anesthesiaRecord;

        $this->app->make(DecideAnesthesiaClearanceAction::class)->execute($record, [
            'status' => AnesthesiaClearanceStatus::ClearedWithConditions->value,
            'conditions' => ['Bilan de coagulation à recontrôler'],
        ], $anesthetist);

        $this->assertContains('clearance_conditions_open', $this->blockerKeys($case));

        $condition = $record->fresh()->clearanceConditions()->firstOrFail();
        $this->app->make(ResolveAnesthesiaClearanceConditionAction::class)
            ->execute($condition, 'Recontrôlé, normal.', $anesthetist);

        $this->assertSame(AnesthesiaClearanceConditionStatus::Resolved, $condition->fresh()->status);
        $this->assertTrue($this->gate()->canStartIntervention($case->fresh()));
    }

    public function test_a_clearance_with_conditions_requires_at_least_one_condition(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $record = $this->anesthesiaCleared($case, $anesthetist);

        $this->expectException(ValidationException::class);
        $this->app->make(DecideAnesthesiaClearanceAction::class)->execute($record, [
            'status' => AnesthesiaClearanceStatus::ClearedWithConditions->value,
            'conditions' => [],
        ], $anesthetist);
    }

    public function test_a_refusal_must_say_why(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $record = $this->anesthesiaCleared($case, $anesthetist);

        try {
            $this->app->make(DecideAnesthesiaClearanceAction::class)->execute($record, [
                'status' => AnesthesiaClearanceStatus::NotCleared->value,
            ], $anesthetist);
            $this->fail('Un refus sans motif aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
    }

    // ── F / G — checklist ───────────────────────────────────────────────

    public function test_a_missing_sign_in_blocks(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $this->anesthesiaCleared($case, $anesthetist);
        $this->completeChecklist($case, SurgicalChecklistPhase::TimeOut, $surgeon, $anesthetist);

        $this->assertContains('checklist_missing_sign_in', $this->blockerKeys($case));
    }

    public function test_a_time_out_without_every_confirmation_blocks(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        $timeOut = $case->safetyChecklist(SurgicalChecklistPhase::TimeOut);
        $timeOut->confirmations()->where('role', SurgicalChecklistRole::Anesthesia->value)->delete();
        $timeOut->update(['completed_at' => null]);

        $this->assertContains(
            'checklist_confirm_time_out_anesthesia',
            $this->blockerKeys($case->fresh()),
        );
    }

    public function test_each_role_confirms_its_own_part(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $this->anesthesiaCleared($case, $anesthetist);

        // Le chirurgien ne signe pas la part de l'anesthésiste.
        try {
            $this->app->make(SaveSurgicalChecklistAction::class)->execute(
                $case,
                SurgicalChecklistPhase::SignIn,
                ['confirm_as' => SurgicalChecklistRole::Anesthesia->value],
                $surgeon,
            );
            $this->fail('Le chirurgien ne devrait pas confirmer pour l’anesthésie.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_as', $exception->errors());
        }

        $this->assertSame(0, $case->safetyChecklists()->count()
            ? $case->safetyChecklist(SurgicalChecklistPhase::SignIn)->confirmations()->count()
            : 0);
    }

    public function test_omitting_an_item_does_not_uncheck_it(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $this->anesthesiaCleared($case, $anesthetist);

        $action = $this->app->make(SaveSurgicalChecklistAction::class);
        $keys = SurgicalSafetyChecklistItems::requiredKeys(SurgicalChecklistPhase::SignIn);

        $action->execute($case, SurgicalChecklistPhase::SignIn, ['items' => [$keys[0] => true]], $anesthetist);
        $action->execute($case, SurgicalChecklistPhase::SignIn, ['items' => [$keys[1] => true]], $anesthetist);

        $checked = $case->fresh()->safetyChecklist(SurgicalChecklistPhase::SignIn)->checked_items;

        $this->assertTrue($checked[$keys[0]]);
        $this->assertTrue($checked[$keys[1]]);
    }

    // ── H — tous les feux au vert ───────────────────────────────────────

    public function test_a_complete_case_starts(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        $this->assertSame([], $this->gate()->blockersForIncision($case));

        $intervention = $this->start($case, $surgeon);

        $this->assertSame($surgeon->id, $intervention->performed_by);
        $this->assertSame(SurgicalRequestStatus::InProgress, $case->fresh()->status);
    }

    // ── O — un avertissement ne bloque jamais ───────────────────────────

    public function test_a_missing_block_entry_warns_but_never_blocks(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        $warnings = array_column($this->gate()->warningsForIncision($case), 'key');

        $this->assertContains('block_entry_missing', $warnings);
        $this->assertTrue($this->gate()->canStartIntervention($case));
    }

    // ── I — l'écran n'est jamais la seule protection ────────────────────

    public function test_a_direct_http_call_is_refused_like_the_screen(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $this->anesthesiaCleared($case, $anesthetist, AnesthesiaClearanceStatus::NotCleared);

        $response = $this->actingAs($surgeon->fresh())
            ->from("/surgery/{$case->uuid}")
            ->post("/surgery/{$case->uuid}/intervention", []);

        $response->assertSessionHasErrors('intervention');
        $this->assertSame(SurgicalRequestStatus::PreoperativeValidated, $case->fresh()->status);
        $this->assertNull($case->fresh()->intervention);
    }

    // ── J — un anesthésiste non affecté n'écrit pas ─────────────────────

    public function test_an_unassigned_anesthetist_cannot_write_the_record(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $stranger = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $record = $this->anesthesiaCleared($case, $anesthetist);

        $this->assertFalse($stranger->can('update', $record));
        $this->assertFalse($stranger->can('decideClearance', $record));

        $this->expectException(ValidationException::class);
        $this->app->make(DecideAnesthesiaClearanceAction::class)->execute($record, [
            'status' => AnesthesiaClearanceStatus::Cleared->value,
        ], $stranger);
    }

    public function test_the_surgeon_can_never_decide_the_anesthesia_clearance(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $record = $this->anesthesiaCleared($case, $anesthetist, AnesthesiaClearanceStatus::NotCleared);

        $this->assertFalse($surgeon->can('decideClearance', $record));

        $this->actingAs($surgeon->fresh())
            ->post("/surgery/{$case->uuid}/anesthesia/{$record->id}/clearance", [
                'status' => AnesthesiaClearanceStatus::Cleared->value,
            ])
            ->assertForbidden();

        $this->assertSame(AnesthesiaClearanceStatus::NotCleared, $record->fresh()->clearance_status);
    }

    // ── K — l'opérateur est un chirurgien de ce dossier ─────────────────

    public function test_performed_by_must_be_a_surgeon_of_this_case(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $stranger = $this->otherSurgeonUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        try {
            $this->start($case, $surgeon, ['performed_by' => $stranger->id]);
            $this->fail('Un compte étranger au dossier ne devrait pas être l’opérateur.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('performed_by', $exception->errors());
        }

        $this->assertNull($case->fresh()->intervention);
        $this->assertSame(SurgicalRequestStatus::PreoperativeValidated, $case->fresh()->status);
    }

    public function test_a_surgeon_of_another_case_cannot_start_this_one(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $stranger = $this->otherSurgeonUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        $this->expectException(ValidationException::class);
        $this->start($case, $stranger);
    }

    // ── L — valider le compte rendu ne clôt plus le dossier ─────────────

    public function test_validating_the_report_no_longer_completes_the_case(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);
        $this->actingAs($surgeon);
        $this->start($case, $surgeon);

        $report = $this->app->make(CreateSurgicalReportAction::class)->execute($case->fresh(), 'Sans incident.');
        $this->app->make(ValidateSurgicalReportAction::class)->execute($report);

        $this->assertNotNull($report->fresh()->validated_at);
        $this->assertSame(SurgicalRequestStatus::InProgress, $case->fresh()->status);

        $blockers = array_column($this->app->make(SurgicalReadinessGate::class)
            ->blockersForCompletion($case->fresh()), 'key');

        $this->assertContains('checklist_missing_sign_out', $blockers);
        $this->assertContains('block_exit_missing', $blockers);
        $this->assertContains('anesthesia_not_validated', $blockers);
    }

    public function test_the_case_completes_once_every_end_of_block_step_is_documented(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);
        $this->actingAs($surgeon);

        $intervention = $this->start($case, $surgeon);
        $intervention->update(['ended_at' => now()]);

        $report = $this->app->make(CreateSurgicalReportAction::class)->execute($case->fresh(), 'Sans incident.');
        $this->app->make(ValidateSurgicalReportAction::class)->execute($report);

        $this->completeChecklist($case->fresh(), SurgicalChecklistPhase::SignOut, $surgeon, $anesthetist);
        $case->blockExit()->create(['recorded_by' => $surgeon->id, 'left_at' => now()]);
        $this->app->make(ValidateAnesthesiaRecordAction::class)
            ->execute($case->fresh()->anesthesiaRecord, $anesthetist);

        $completed = $this->app->make(CompleteSurgicalCaseAction::class)->execute($case->fresh(), $surgeon);

        $this->assertSame(SurgicalRequestStatus::Completed, $completed->status);

        // Idempotent : un second clic ne rejoue pas la transition.
        $again = $this->app->make(CompleteSurgicalCaseAction::class)->execute($case->fresh(), $surgeon);
        $this->assertSame(SurgicalRequestStatus::Completed, $again->status);
    }

    // ── M — pas de doublon ──────────────────────────────────────────────

    public function test_starting_twice_never_creates_two_interventions(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        $this->start($case, $surgeon);

        try {
            $this->start($case, $surgeon);
            $this->fail('Le second démarrage aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('intervention', $exception->errors());
        }

        $this->assertSame(1, SurgicalIntervention::query()->where('surgical_request_id', $case->id)->count());
    }

    // ── N — un dossier annulé ne démarre jamais ─────────────────────────

    public function test_a_cancelled_case_can_never_start(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);
        $case->update(['status' => SurgicalRequestStatus::Cancelled]);

        $this->assertSame(['case_cancelled'], $this->blockerKeys($case));

        $this->expectException(ValidationException::class);
        $this->start($case, $surgeon);
    }

    // ── Anesthésie : ordre des gestes ───────────────────────────────────

    public function test_the_anesthesia_record_is_not_locked_before_the_incision(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        try {
            $this->app->make(ValidateAnesthesiaRecordAction::class)
                ->execute($case->anesthesiaRecord, $anesthetist);
            $this->fail('La fiche ne devrait pas se fermer avant l’intervention.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('anesthesia', $exception->errors());
        }

        $this->assertNull($case->fresh()->anesthesiaRecord->validated_at);
    }

    public function test_the_clearance_is_not_decided_before_the_assessment_is_validated(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);

        $record = $case->anesthesiaRecord()->create([
            'anesthetist_id' => $anesthetist->id,
            'notes' => 'En cours',
        ]);

        $this->expectException(ValidationException::class);
        $this->app->make(DecideAnesthesiaClearanceAction::class)->execute($record, [
            'status' => AnesthesiaClearanceStatus::Cleared->value,
        ], $anesthetist);
    }

    public function test_the_clearance_is_no_longer_reprononced_once_at_the_block(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);
        $this->start($case, $surgeon);

        $this->expectException(ValidationException::class);
        $this->app->make(DecideAnesthesiaClearanceAction::class)->execute($case->fresh()->anesthesiaRecord, [
            'status' => AnesthesiaClearanceStatus::NotCleared->value,
            'reason' => 'Trop tard.',
        ], $anesthetist);
    }

    public function test_an_unassigned_anesthetist_cannot_update_the_record_through_the_action(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $stranger = $this->anesthetistUser();
        $case = $this->preparedCase($surgeon);
        $record = $this->anesthesiaCleared($case, $anesthetist);

        $this->actingAs($stranger->fresh())
            ->put("/surgery/{$case->uuid}/anesthesia/{$record->id}", ['notes' => 'Écrit par un tiers'])
            ->assertForbidden();

        $this->assertSame('AG standard', $record->fresh()->notes);
    }

    /** Une réserve levée par le bloc lui rendrait l'autorisation qu'on lui a refusée. */
    public function test_a_condition_is_not_resolved_by_the_surgical_team(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);
        $record = $case->anesthesiaRecord;

        $this->app->make(DecideAnesthesiaClearanceAction::class)->execute($record, [
            'status' => AnesthesiaClearanceStatus::ClearedWithConditions->value,
            'conditions' => ['Culot globulaire disponible'],
        ], $anesthetist);

        $condition = $record->fresh()->clearanceConditions()->firstOrFail();

        $this->assertFalse($surgeon->can('resolve', $condition));

        $this->expectException(ValidationException::class);
        $this->app->make(ResolveAnesthesiaClearanceConditionAction::class)
            ->execute($condition, null, $surgeon);
    }

    public function test_a_validated_anesthesia_record_no_longer_accepts_writes(): void
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);
        $this->start($case, $surgeon);

        $this->app->make(ValidateAnesthesiaRecordAction::class)
            ->execute($case->fresh()->anesthesiaRecord, $anesthetist);

        $this->expectException(ValidationException::class);
        $this->app->make(UpdateAnesthesiaRecordAction::class)
            ->execute($case->fresh()->anesthesiaRecord, ['notes' => 'Après coup']);
    }
}
