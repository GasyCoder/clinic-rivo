<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\AuditLog;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Notifications\LabResultReturned;
use App\Notifications\LabResultsAddressed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/**
 * ADR-216 — le technicien envoie les résultats au médecin, et cet envoi les
 * valide. Le destinataire les lit librement ; un confrère les voit exister et
 * les ouvre après confirmation, tracée.
 */
class LabResultsDeliveryTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
    }

    /** @return array{0: LabRequest, 1: LabRequestItem, 2: User} une analyse rendue, pas encore envoyée */
    private function renderedRequest(User $requester): array
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $requester);
        $item = $this->requestItem($request, $this->prestation($technician), [
            'status' => LabItemStatus::Completed, 'resulted_at' => now(), 'resulted_by' => $technician->id, 'result_value' => 'Hb 13,2 g/dL',
        ]);

        return [$request, $item, $technician];
    }

    public function test_the_prescriber_is_proposed_and_an_account_that_cannot_prescribe_is_refused(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($doctor);

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Show', false)
                ->where('recipient.proposed_uuid', $doctor->uuid)
                ->where('recipients.0.uuid', $doctor->uuid)
                ->where('recipients.0.prescriber', true));

        $receptionist = $this->userWithRole('RECEPTION');
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $receptionist->uuid,
        ])->assertSessionHasErrors('recipient_uuid');

        // Sans décision sur le destinataire, rien ne part.
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", ['items' => [$item->uuid]])
            ->assertSessionHasErrors('recipient_uuid');

        $this->assertNull($item->fresh()->sent_at);
    }

    public function test_a_reception_request_proposes_nobody_and_may_go_to_no_doctor(): void
    {
        Notification::fake();
        $receptionist = $this->userWithRole('RECEPTION');
        [$request, $item, $technician] = $this->renderedRequest($receptionist);

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('recipient.proposed_uuid', null));

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'to_nobody' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(LabItemStatus::Validated, $item->fresh()->currentStatus());
        $this->assertNull($request->fresh()->results_recipient_id);
        $this->assertNotNull($request->fresh()->results_addressed_at);
        Notification::assertNothingSent();
    }

    public function test_the_recipient_is_notified_and_told_when_a_sent_result_goes_back_to_the_bench(): void
    {
        Notification::fake();
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($doctor);

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $doctor->uuid,
        ])->assertSessionHasNoErrors();
        Notification::assertSentTo($doctor, LabResultsAddressed::class);

        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.results.send']);

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Valeur transcrite à tort'])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($doctor, LabResultReturned::class);

        // Envoyée une fois, elle reste « livrée » : le médecin garde la valeur envoyée, marquée en correction.
        $this->assertSame(LabItemStatus::ToRedo, $item->fresh()->currentStatus());
        $this->assertNotNull($item->fresh()->sent_at);
    }

    public function test_a_colleague_sees_the_result_sealed_until_he_confirms_and_the_opening_is_traced(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        $colleague = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($doctor);
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $doctor->uuid,
        ])->assertSessionHasNoErrors();

        // Le destinataire lit librement.
        $this->actingAs($doctor)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/ResultsPrint', false)
                ->where('sealed', null)
                ->count('items', 1));

        // Le confrère voit que le résultat existe, sans ses valeurs.
        $this->actingAs($colleague)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sealed.recipient', $doctor->name)
                ->count('items', 0));

        $this->actingAs($colleague)->post("/resultats-analyses/{$request->uuid}/ouvrir")->assertRedirect();
        $this->assertTrue(AuditLog::query()->where('action', 'laboratory.results.open')->where('user_id', $colleague->id)->exists());

        $this->actingAs($colleague)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sealed', null)->count('items', 1));
    }

    public function test_the_doctor_does_not_see_a_result_the_laboratory_has_not_sent(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($doctor);

        // Demandée à l'accueil (sans consultation) : elle n'entre dans la liste du
        // médecin qu'une fois que le laboratoire la lui adresse.
        $this->actingAs($doctor)->get('/medicine/demandes-examens?filter=recent')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Medicine/Requests', false)->count('requests', 0));
        $this->actingAs($doctor)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->count('items', 0)->where('pending.0', 'NFS'));

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $doctor->uuid,
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->get('/medicine/demandes-examens?filter=recent')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('requests.0.items.0.resulted_at', null)
                ->where('requests.0.results_url', "/resultats-analyses/{$request->uuid}")
                ->where('requests.0.recipient', $doctor->name)
                ->where('requests.0.sealed', false));
    }
}
