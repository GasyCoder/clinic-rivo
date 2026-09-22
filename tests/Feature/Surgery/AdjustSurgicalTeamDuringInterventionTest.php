<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Enums\SurgicalTeamFunction;
use App\Models\AuditLog;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Surgery\Concerns\BuildsSurgicalCases;
use Tests\TestCase;

/**
 * Amendement de l'ADR-168 — au bloc, les aides et l'opérateur réel
 * s'ajustent avec un motif ; la date et le chirurgien principal restent figés.
 */
class AdjustSurgicalTeamDuringInterventionTest extends TestCase
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
        $case = $this->caseReadyForIncision($surgeon, $this->anesthetistUser());
        $this->app->make(CreateSurgicalInterventionAction::class)->execute($case->fresh(), [], $surgeon);

        return [$case->fresh(), $surgeon];
    }

    private function assistantIds(SurgicalRequest $case): array
    {
        return $case->fresh()->teamMembers()
            ->where('function', SurgicalTeamFunction::Surgeon->value)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_an_assistant_arriving_during_surgery_can_be_added_and_can_be_named_operator(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $assistant = $this->otherSurgeonUser();
        $scheduledAt = $case->scheduled_at?->toIso8601String();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'assistant_surgeon_ids' => [$assistant->id],
                'performed_by' => $assistant->id,
                'reason' => 'Dr arrivé en renfort',
            ])
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame([$assistant->id], $this->assistantIds($case));
        $this->assertSame($assistant->id, (int) $case->intervention->performed_by);
        // Figés : le principal et la date programmée.
        $this->assertSame($surgeon->id, (int) $case->surgeon_id);
        $this->assertSame($scheduledAt, $case->scheduled_at?->toIso8601String());

        $audit = AuditLog::query()->where('action', 'surgery.team.adjust')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Dr arrivé en renfort', $audit->reason);
    }

    public function test_the_scheduled_date_can_be_corrected_in_theatre_with_a_reason(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $assistant = $this->otherSurgeonUser();
        $this->actingAs($surgeon)->post("/surgery/{$case->uuid}/team-adjustment", [
            'assistant_surgeon_ids' => [$assistant->id], 'reason' => 'Renfort',
        ])->assertSessionHasNoErrors();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'scheduled_at' => '2026-09-16T13:30',
                'reason' => 'Heure avancée par l’anesthésie',
            ])
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('2026-09-16 13:30', $case->scheduled_at->format('Y-m-d H:i'));
        // Un champ omis reste tel quel : les aides ne bougent pas.
        $this->assertSame([$assistant->id], $this->assistantIds($case));
        $this->assertSame('Heure avancée par l’anesthésie', AuditLog::query()->where('action', 'surgery.team.adjust')->latest('id')->value('reason'));
    }

    public function test_the_principal_can_be_changed_in_theatre_and_leaves_the_assistants(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $other = $this->otherSurgeonUser();
        $this->actingAs($surgeon)->post("/surgery/{$case->uuid}/team-adjustment", [
            'assistant_surgeon_ids' => [$other->id], 'reason' => 'Renfort',
        ])->assertSessionHasNoErrors();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'surgeon_id' => $other->id,
                'reason' => 'Le Dr a pris la direction de l’intervention',
            ])
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame($other->id, (int) $case->surgeon_id);
        // Devenu principal, il n'est plus compté parmi les aides ; l'ancien principal,
        // opérateur enregistré, y reste — qui a opéré n'est pas réécrit.
        $this->assertSame([$surgeon->id], $this->assistantIds($case));
        $this->assertSame($surgeon->id, (int) $case->intervention->performed_by);
    }

    public function test_an_operator_cannot_be_named_outside_the_new_team(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $other = $this->otherSurgeonUser();
        $outsider = $this->otherSurgeonUser();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'surgeon_id' => $other->id,
                'performed_by' => $outsider->id,
                'reason' => 'Changement',
            ])
            ->assertSessionHasErrors('performed_by');

        $this->assertSame($surgeon->id, (int) $case->fresh()->surgeon_id);
    }

    public function test_a_principal_without_the_surgeon_profile_is_refused(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'surgeon_id' => $this->orNurseUser()->id,
                'reason' => 'Erreur',
            ])
            ->assertSessionHasErrors('surgeon_id');
    }

    public function test_a_reason_is_required(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'assistant_surgeon_ids' => [$this->otherSurgeonUser()->id],
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_the_operator_must_be_the_principal_or_an_assistant(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $outsider = $this->otherSurgeonUser();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'assistant_surgeon_ids' => [],
                'performed_by' => $outsider->id,
                'reason' => 'Correction',
            ])
            ->assertSessionHasErrors('performed_by');
    }

    public function test_an_assistant_without_the_surgeon_profile_is_refused(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'assistant_surgeon_ids' => [$this->orNurseUser()->id],
                'reason' => 'Renfort',
            ])
            ->assertSessionHasErrors('assistant_surgeon_ids.0');
    }

    public function test_the_current_operator_cannot_be_removed_without_naming_another(): void
    {
        [$case, $surgeon] = $this->caseInTheatre();
        $assistant = $this->otherSurgeonUser();
        $this->actingAs($surgeon)->post("/surgery/{$case->uuid}/team-adjustment", [
            'assistant_surgeon_ids' => [$assistant->id], 'performed_by' => $assistant->id, 'reason' => 'Renfort',
        ])->assertSessionHasNoErrors();

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'assistant_surgeon_ids' => [],
                'reason' => 'Retrait',
            ])
            ->assertSessionHasErrors('performed_by');
    }

    public function test_nothing_can_be_adjusted_before_the_intervention_starts(): void
    {
        $surgeon = $this->surgeonUser();
        $case = $this->preparedCase($surgeon);

        $this->actingAs($surgeon)
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'assistant_surgeon_ids' => [$this->otherSurgeonUser()->id],
                'reason' => 'Renfort',
            ])
            ->assertSessionHasErrors('team');
    }

    public function test_the_permission_is_required(): void
    {
        [$case] = $this->caseInTheatre();

        $this->actingAs($this->otherSurgeonUser())
            ->post("/surgery/{$case->uuid}/team-adjustment", [
                'assistant_surgeon_ids' => [],
                'reason' => 'Renfort',
            ])
            ->assertForbidden();
    }
}
