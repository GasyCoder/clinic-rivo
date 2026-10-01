<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\AuditLog;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\LabRedoRequested;
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

    /**
     * Amendement ADR-216 du 2026-09-29 — le médecin qui a reçu un résultat peut
     * demander qu'il soit refait ; le technicien qui l'avait envoyé est prévenu.
     * Un confrère à qui il n'est pas adressé ne le peut pas sans l'avoir ouvert.
     */
    public function test_the_doctor_asks_for_a_redo_and_the_sender_is_notified(): void
    {
        Notification::fake();
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($doctor);
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $doctor->uuid,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($doctor->can('laboratory_results.return'), 'Le socle MEDICINE porte le droit.');
        $this->actingAs($doctor)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.return', true));

        $colleague = $this->userWithRole('MEDICINE');
        $this->actingAs($colleague)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'À contrôler'])->assertForbidden();

        $this->actingAs($doctor)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Valeur incohérente avec la clinique'])
            ->assertSessionHasNoErrors();
        $this->assertSame(LabItemStatus::ToRedo, $item->fresh()->currentStatus());
        Notification::assertSentTo($technician, LabRedoRequested::class);
        Notification::assertNotSentTo($doctor, LabResultReturned::class);

        // Sans le droit, la feuille ne propose rien et le serveur refuse.
        $doctor->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'laboratory_results.return')->value('id') => ['effect' => 'deny'],
        ]);
        $this->actingAs($doctor->fresh())->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.return', false)->where('can.bench_url', null));
    }

    /** Une analyse terminée mais pas envoyée se rouvre sans motif ; envoyée, elle se renvoie à refaire. */
    public function test_a_completed_analysis_reopens_without_a_reason_until_it_is_sent(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($doctor);

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/reopen")->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame(LabItemStatus::InProgress, $item->currentStatus());
        $this->assertNull($item->resulted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.item.reopen']);

        // Pas terminée : l'envoi refuse.
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", ['items' => [$item->uuid], 'to_nobody' => true])
            ->assertSessionHasErrors('items');
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/reopen")->assertSessionHasErrors('item');
    }

    /** Un médecin à qui l'on accorde la saisie ou la modification d'une demande les a, sans rôle codé en dur. */
    public function test_a_doctor_granted_the_rights_enters_results_and_edits_the_request(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC', 'unit' => 'g/L']);
        $request = $this->labRequest($episode, $orientation, $doctor);
        $item = $this->requestItem($request, $glycemie);
        $uree = $this->prestation($technician, 'LAB-UREE', 'Urée');

        $results = fn () => $this->actingAs($doctor->fresh())->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '1.10']],
        ]);
        $results()->assertForbidden();

        $doctor->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'laboratory_results.create')->value('id') => ['effect' => 'allow'],
            Permission::query()->where('name', 'laboratory_orders.update')->value('id') => ['effect' => 'allow'],
        ]);

        $this->actingAs($doctor->fresh())->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.bench_url', "/laboratory/requests/{$request->uuid}"));
        $results()->assertSessionHasNoErrors();
        $this->actingAs($doctor->fresh())->post("/laboratory/items/{$item->uuid}/complete")->assertSessionHasNoErrors();
        $this->assertSame(LabItemStatus::Completed, $item->fresh()->currentStatus());
        $this->actingAs($doctor->fresh())->post("/laboratory/requests/{$request->uuid}/items", ['catalog_item_uuids' => [$uree->uuid]])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $request->items()->count());
    }

    /**
     * Amendement ADR-216 du 2026-09-29 (ter) — un, plusieurs ou tous les médecins : chacun
     * est notifié et lit librement ; un envoi suivant ajoute des destinataires sans en retirer.
     */
    public function test_results_go_to_several_doctors_who_all_read_them_freely(): void
    {
        Notification::fake();
        $prescriber = $this->userWithRole('MEDICINE');
        $second = $this->userWithRole('MEDICINE');
        $third = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($prescriber);

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuids' => [$prescriber->uuid, $second->uuid],
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($prescriber, LabResultsAddressed::class);
        Notification::assertSentTo($second, LabResultsAddressed::class);
        Notification::assertNotSentTo($third, LabResultsAddressed::class);
        $request->refresh();
        $this->assertSame($prescriber->id, $request->results_recipient_id);
        $this->assertTrue($request->isAddressedTo($second));

        $this->actingAs($second)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sealed', null)->count('items', 1));
        $this->actingAs($third)->get("/resultats-analyses/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sealed.recipient', "{$prescriber->name}, {$second->name}"));

        // Une seconde analyse, envoyée au troisième seul : les deux premiers gardent leur accès.
        $other = $this->requestItem($request, $this->prestation($technician, 'LAB-GLY', 'Glycémie'), [
            'status' => LabItemStatus::Completed, 'resulted_at' => now(), 'result_value' => '0,95 g/L',
        ]);
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$other->uuid], 'recipient_uuids' => [$third->uuid],
        ])->assertSessionHasNoErrors();
        $request->refresh();
        foreach ([$prescriber, $second, $third] as $doctor) {
            $this->assertTrue($request->isAddressedTo($doctor));
        }

        // « Aucun médecin » et des médecins à la fois : refusé.
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$other->uuid], 'recipient_uuids' => [$third->uuid], 'to_nobody' => true,
        ])->assertSessionHasErrors('recipient_uuids');

        // Renvoyée à refaire : chacun des destinataires est prévenu.
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Valeur à contrôler'])->assertSessionHasNoErrors();
        Notification::assertSentTo($second, LabResultReturned::class);
        Notification::assertSentTo($third, LabResultReturned::class);
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

        // Amendement ADR-216 quater — reçu, il attend la validation du médecin.
        $this->actingAs($doctor)->get('/medicine/demandes-examens?filter=to_validate')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('requests.0.items.0.resulted_at', null)
                ->where('requests.0.results_url', "/resultats-analyses/{$request->uuid}")
                ->where('requests.0.recipient', $doctor->name)
                ->where('requests.0.sealed', false));
    }

    /** ADR-219 — dans « Demandes d'examens », le laboratoire a le bouton de sa file. */
    public function test_the_laboratory_gets_its_queue_gesture_in_the_exam_requests(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->renderedRequest($doctor);

        $this->actingAs($technician)->get('/medicine/demandes-examens?filter=all')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('requests.0.bench_url', "/laboratory/requests/{$request->uuid}")
                ->where('requests.0.bench_action', 'send')
                ->where('requests.0.lab_state', 'to_validate')
                ->has('requests.0.payment.cleared'));

        $item->update(['status' => LabItemStatus::Pending, 'resulted_at' => null, 'result_value' => null]);
        $request->update(['received_at' => null, 'received_by' => null]);
        $this->actingAs($technician)->get('/medicine/demandes-examens?filter=all')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.0.bench_action', 'start')->where('requests.0.lab_state', 'to_do'));

        // Le médecin n'a pas de geste de paillasse.
        $this->actingAs($doctor)->post("/laboratory/requests/{$request->uuid}/send", [])->assertForbidden();
    }
}
