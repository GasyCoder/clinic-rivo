<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\AnesthesiaRecord;
use App\Models\AuditLog;
use App\Models\CareConsumableRequest;
use App\Models\SurgicalRequest;
use App\Models\SurgicalRequestReset;
use App\Models\SurgicalSafetyChecklist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Surgery\Concerns\BuildsSurgicalCases;
use Tests\TestCase;

/**
 * ADR-171 — un dossier du bloc saisi à tort se remet à zéro : tout est archivé
 * avant d'être retiré, la demande repart « À programmer », motif obligatoire.
 */
class ResetSurgicalRequestTest extends TestCase
{
    use BuildsSurgicalCases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic']);
    }

    /** @return array{0: SurgicalRequest, 1: User} */
    private function caseInTheatre(): array
    {
        $surgeon = $this->surgeonUser();
        $this->grant($surgeon, ['surgery.reset']);
        $case = $this->caseReadyForIncision($surgeon, $this->anesthetistUser());
        $this->app->make(CreateSurgicalInterventionAction::class)->execute($case->fresh(), [], $surgeon);

        return [$case->fresh(), $surgeon->fresh()];
    }

    private function consumableRequest(SurgicalRequest $case, CareConsumableRequestStatus $status): CareConsumableRequest
    {
        return CareConsumableRequest::query()->create([
            'request_number' => 'DC-'.Str::upper(Str::random(6)),
            'source_module' => 'SURGERY',
            'surgical_request_id' => $case->id,
            'episode_id' => $case->episode_id,
            'status' => $status,
            'requested_at' => now(),
        ]);
    }

    public function test_a_case_in_theatre_is_archived_then_reset_to_be_scheduled(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $procedure = $case->procedure_name;

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/reset", ['reason' => 'Intervention démarrée sur le mauvais dossier'])
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame(SurgicalRequestStatus::Pending, $case->status);
        $this->assertNull($case->surgeon_id);
        $this->assertNull($case->scheduled_at);
        $this->assertNull($case->preoperative_validated_at);
        // La demande elle-même est gardée.
        $this->assertSame($procedure, $case->procedure_name);

        $this->assertNull($case->intervention()->first());
        $this->assertSame(0, $case->teamMembers()->count());
        $this->assertSame(0, SurgicalSafetyChecklist::query()->where('surgical_request_id', $case->id)->count());
        $this->assertSame(0, AnesthesiaRecord::query()->where('surgical_request_id', $case->id)->count());

        // Rien n'est perdu : l'archive porte ce qui a été retiré.
        $archive = SurgicalRequestReset::query()->where('surgical_request_id', $case->id)->sole();
        $this->assertSame('IN_PROGRESS', $archive->previous_status);
        $this->assertSame('Intervention démarrée sur le mauvais dossier', $archive->reason);
        $this->assertSame($surgeon->id, (int) $archive->reset_by);
        $this->assertNotNull($archive->snapshot['intervention']);
        $this->assertNotNull($archive->snapshot['anesthesia_record']);
        $this->assertNotEmpty($archive->snapshot['team_members']);
        $this->assertNotEmpty($archive->snapshot['safety_checklists']);

        $audit = AuditLog::query()->where('action', 'surgery.request.reset')->sole();
        $this->assertSame('Intervention démarrée sur le mauvais dossier', $audit->reason);
    }

    public function test_the_archive_is_never_rewritten_nor_deleted(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $this->actingAs($surgeon)->post("/surgery/{$case->uuid}/reset", ['reason' => 'Erreur de saisie'])->assertSessionHasNoErrors();
        $archive = SurgicalRequestReset::query()->sole();

        $this->expectException(\LogicException::class);
        $archive->update(['reason' => 'autre']);
    }

    public function test_a_reason_is_required(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/reset", ['reason' => ' '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SurgicalRequestStatus::InProgress, $case->fresh()->status);
    }

    public function test_the_dedicated_right_is_required(): void
    {
        $surgeon = $this->surgeonUser();
        $case = $this->caseReadyForIncision($surgeon, $this->anesthetistUser());

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/reset", ['reason' => 'Erreur'])
            ->assertForbidden();

        $this->assertSame(0, SurgicalRequestReset::query()->count());
    }

    public function test_material_already_served_by_the_pharmacy_blocks_the_reset(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $this->consumableRequest($case, CareConsumableRequestStatus::Served);

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/reset", ['reason' => 'Erreur'])
            ->assertSessionHasErrors('reset');

        $this->assertSame(SurgicalRequestStatus::InProgress, $case->fresh()->status);
        $this->assertNotNull($case->fresh()->intervention);
    }

    public function test_material_not_yet_served_is_cancelled_with_the_reset(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $request = $this->consumableRequest($case, CareConsumableRequestStatus::Pending);

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/reset", ['reason' => 'Saisie en double'])
            ->assertSessionHasNoErrors();

        $this->assertSame(CareConsumableRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertStringContainsString('Saisie en double', $request->fresh()->cancellation_reason);
    }

    public function test_a_case_with_nothing_entered_has_nothing_to_reset(): void
    {
        $surgeon = $this->surgeonUser();
        $this->grant($surgeon, ['surgery.reset']);
        $episode = $this->makeSurgicalEpisode();
        $case = SurgicalRequest::query()->create([
            'episode_id' => $episode->id,
            'requested_by' => $surgeon->id,
            'created_by' => $surgeon->id,
            'status' => SurgicalRequestStatus::Pending,
            'procedure_name' => 'Appendicectomie',
        ]);

        $this->actingAs($surgeon->fresh())
            ->post("/surgery/{$case->uuid}/reset", ['reason' => 'Erreur'])
            ->assertSessionHasErrors('reset');
    }

    public function test_a_cancelled_request_is_not_reset(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $case->forceFill(['status' => SurgicalRequestStatus::Cancelled])->save();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/reset", ['reason' => 'Erreur'])
            ->assertSessionHasErrors('reset');
    }
}
