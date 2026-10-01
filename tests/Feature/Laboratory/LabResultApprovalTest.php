<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/**
 * ADR-216, amendement quater — le laboratoire termine et envoie, le médecin relit
 * et valide ; la Réception ne voit un résultat, et son compte rendu, qu'une fois
 * validé.
 */
class LabResultApprovalTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
    }

    /** @return array{0: LabRequest, 1: LabRequestItem, 2: User} une analyse envoyée au médecin */
    private function sentTo(User $doctor, bool $toNobody = false): array
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $toNobody ? $this->userWithRole('RECEPTION') : $doctor);
        $item = $this->requestItem($request, $this->prestation($technician), [
            'status' => LabItemStatus::Completed, 'resulted_at' => now(), 'resulted_by' => $technician->id, 'result_value' => 'Hb 13,2 g/dL',
        ]);

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", $toNobody
            ? ['items' => [$item->uuid], 'to_nobody' => true]
            : ['items' => [$item->uuid], 'recipient_uuids' => [$doctor->uuid]])
            ->assertSessionHasNoErrors();

        return [$request, $item->fresh(), $technician];
    }

    public function test_the_doctor_sees_a_sent_result_as_to_validate_then_validates_it(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item] = $this->sentTo($doctor);

        $this->assertTrue($item->awaitsApproval());
        $this->assertNull($item->approved_at);

        $this->actingAs($doctor)->get('/medicine/demandes-examens')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Medicine/Requests')
                ->where('filters.filter', 'to_validate')
                ->where('counts.to_validate', 1)
                ->where('counts.active', 0)
                ->where('counts.recent', 0)
                ->where('requests.0.approval.awaiting', 1)
                ->where('requests.0.can_approve', true)
                ->where('requests.0.items.0.approval.state', 'AWAITING'));

        $this->actingAs($doctor)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.approve', true)
                ->where('items.0.approval.state', 'AWAITING'));

        $this->actingAs($doctor)->post("/resultats-analyses/{$request->uuid}/valider")->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertTrue($item->isApproved());
        $this->assertSame($doctor->id, $item->approved_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.results.approve']);

        $this->actingAs($doctor)->get('/medicine/demandes-examens')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.filter', 'active')
                ->where('counts.to_validate', 0)
                ->where('counts.recent', 1));

        // Une seconde validation n'a plus rien à valider.
        $this->actingAs($doctor)->post("/resultats-analyses/{$request->uuid}/valider")->assertSessionHasErrors('items');
    }

    public function test_a_colleague_must_open_first_and_an_account_without_the_right_is_refused(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request] = $this->sentTo($doctor);

        $colleague = $this->userWithRole('MEDICINE');
        $this->actingAs($colleague)->post("/resultats-analyses/{$request->uuid}/valider")->assertForbidden();

        $nurse = $this->userWithRole('NURSE');
        $this->actingAs($nurse)->post("/resultats-analyses/{$request->uuid}/valider")->assertForbidden();
    }

    public function test_a_result_sent_to_nobody_is_validated_at_sending(): void
    {
        [$request, $item] = $this->sentTo($this->userWithRole('MEDICINE'), toNobody: true);

        $this->assertTrue($item->isApproved());

        $receptionist = $this->userWithRole('RECEPTION');
        $this->actingAs($receptionist)->get('/reception/resultats-analyses')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reception/LabResults/Index')
                ->where('counts.tous', 1)
                ->where('requests.data.0.uuid', $request->uuid));
    }

    public function test_reception_sees_only_validated_results_and_their_report(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->sentTo($doctor);
        $receptionist = $this->userWithRole('RECEPTION');

        $this->actingAs($receptionist)->get('/reception/resultats-analyses')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.tous', 0)->where('requests.data', []));
        $this->actingAs($receptionist)->get("/reception/resultats-analyses/{$request->uuid}/pdf")->assertNotFound();

        $this->actingAs($doctor)->post("/resultats-analyses/{$request->uuid}/valider")->assertSessionHasNoErrors();

        $this->actingAs($receptionist)->get('/reception/resultats-analyses')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.tous', 1)
                ->where('counts.complets', 1)
                ->where('requests.data.0.complete', true)
                ->where('requests.data.0.approved_by.0', $doctor->name));

        $this->actingAs($receptionist)->get("/reception/resultats-analyses/{$request->uuid}/pdf")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Le détail du passage mène au compte rendu.
        $this->actingAs($receptionist)->get("/passages/{$request->episode->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('labResults.0.uuid', $request->uuid));

        // Sans le droit, rien.
        $this->actingAs($technician)->get('/reception/resultats-analyses')->assertForbidden();
    }

    public function test_a_returned_result_loses_its_validation_and_is_validated_again_after_the_correction(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->sentTo($doctor);
        $this->actingAs($doctor)->post("/resultats-analyses/{$request->uuid}/valider")->assertSessionHasNoErrors();

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Valeur transcrite à tort'])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->approved_at);
        $this->assertFalse($item->fresh()->isApproved());

        $this->actingAs($this->userWithRole('RECEPTION'))->get('/reception/resultats-analyses')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.tous', 0));

        // Refaite, terminée, renvoyée : elle attend de nouveau le médecin.
        $item->update(['status' => LabItemStatus::Completed]);
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuids' => [$doctor->uuid],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->awaitsApproval());
    }

    public function test_the_permissions_are_granted_to_the_expected_roles(): void
    {
        $this->assertTrue($this->userWithRole('MEDICINE')->can('laboratory_results.approve'));
        $this->assertFalse($this->userWithRole('RECEPTION')->can('laboratory_results.approve'));
        $this->assertTrue($this->userWithRole('RECEPTION')->can('laboratory_results.validated_view'));
        $this->assertFalse($this->userWithRole('LABORATORY')->can('laboratory_results.validated_view'));
    }
}
