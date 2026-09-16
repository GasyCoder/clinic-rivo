<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\BillableItemStatus;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyStockMovementType;
use App\Enums\ReceptionRoutingMode;
use App\Models\BillableItem;
use App\Models\CareActConsumable;
use App\Models\CareConsumableRequest;
use App\Models\CareRecordProcedure;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-072 — Soins declares the consumables it actually used, Pharmacy
 * records the stock exit, Réception/Caisse collects the patient's share.
 */
class CareConsumableFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_nurse_declares_a_consumable_which_is_billed_and_queued_for_pharmacy(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 20, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        $this->giveActiveTariff($consumable->catalogItem, $nurse, 3000);

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record", [
                'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 3]],
                'consumable_notes' => 'Pansement refait deux fois',
            ])
            ->assertRedirect();

        $request = CareConsumableRequest::query()->with('lines')->sole();
        $this->assertSame(CareConsumableRequestStatus::Pending, $request->status);
        $this->assertSame($orientation->episode_id, $request->episode_id);
        $this->assertSame($orientation->id, $request->care_orientation_id);
        $this->assertSame('Pansement refait deux fois', $request->notes);
        $this->assertStringContainsString('DC-', $request->request_number);

        $line = $request->lines->sole();
        $this->assertSame(3, $line->quantity_requested);
        $this->assertSame(0, $line->quantity_served);
        // Snapshot: a later catalog rename never rewrites the declaration.
        $this->assertSame('Compresses stériles', $line->medicine_name);

        // Billed separately from the nursing act, tariff resolved server-side.
        $billableItem = BillableItem::query()->findOrFail($line->billable_item_id);
        $this->assertSame('9000.00', $billableItem->total_amount);
        $this->assertSame(CatalogModule::Pharmacy->value, $billableItem->source_module);

        // Nothing has left the stock yet: that belongs to Pharmacy.
        $this->assertSame(20, MedicineLot::query()->sole()->quantity_on_hand);
        $this->assertSame(0, PharmacyStockMovement::query()->count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'care.consumables.request']);
    }

    public function test_soins_can_never_declare_a_medicine_only_a_parapharmacy_consumable(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $medicine = $this->consumable(
            $nurse,
            [['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()]],
            form: MedicineForm::Tablet,
            name: 'Paracétamol 500 mg',
        );

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record", [
                'consumables' => [['medicine_uuid' => $medicine->uuid, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('consumables.0.medicine_uuid');

        $this->assertSame(0, CareConsumableRequest::query()->count());
    }

    public function test_declaring_material_requires_its_own_permission(): void
    {
        // Every care right except the one that governs this declaration.
        $nurse = $this->nurse(['care.view', 'care.create', 'care.update', 'care_consumables.view']);
        $orientation = $this->activeCareOrientation($nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 5, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record", [
                'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('consumables');

        $this->assertSame(0, CareConsumableRequest::query()->count());
    }

    /**
     * The regression that motivated merging the two steps: material declared
     * on a separate form was silently lost when the nurse finished the visit
     * instead of pressing that second form's own button. Acts and material
     * are now one submission, and "record and complete" carries both.
     */
    public function test_finishing_the_visit_records_the_material_in_the_same_gesture(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        $act = CatalogItem::query()->where('code', 'PANSEMENT')->sole();
        $this->giveActiveTariff($act, $nurse, 5000);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 20, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        $this->giveActiveTariff($consumable->catalogItem, $nurse, 3000);

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record-and-complete", [
                'procedures' => [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]],
                'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 2]],
            ])
            ->assertRedirect();

        // The act was recorded AND the material reached Pharmacy.
        $this->assertSame(1, CareRecordProcedure::query()->count());
        $request = CareConsumableRequest::query()->with('lines')->sole();
        $this->assertSame(CareConsumableRequestStatus::Pending, $request->status);
        $this->assertSame(2, $request->lines->sole()->quantity_requested);
        $this->assertNotNull($request->care_record_id);

        // Act 5 000 + material 2 × 3 000 = 11 000, both charged to the visit.
        $this->assertEquals(11000, BillableItem::query()->sum('total_amount'));
        $this->assertSame(2, BillableItem::query()->count());

        // The visit is closed for the nurse, the stock exit is Pharmacy's job.
        $this->assertSame(0, PharmacyStockMovement::query()->count());
    }

    public function test_a_missing_sale_price_never_blocks_the_declaration_or_the_pharmacy_notification(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        // No CatalogTariff on purpose.
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 8, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record", [
                'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 2]],
            ])
            ->assertRedirect();

        $line = CareConsumableRequest::query()->with('lines')->sole()->lines->sole();
        $this->assertNull($line->billable_item_id);
        $this->assertSame(2, $line->quantity_requested);
        $this->assertSame(0, BillableItem::query()->count());
    }

    public function test_pharmacy_serves_the_request_in_fefo_order_without_waiting_for_any_payment(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'LATER', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
            ['lot' => 'SOON', 'quantity' => 4, 'expires_at' => now()->addMonth()->toDateString()],
        ]);
        $this->giveActiveTariff($consumable->catalogItem, $nurse, 1000);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 6]],
        ])->assertRedirect();

        $request = CareConsumableRequest::query()->with('lines')->sole();
        $line = $request->lines->sole();
        $pharmacist = $this->pharmacist();

        $this->actingAs($pharmacist)
            ->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
                'lines' => [['uuid' => $line->uuid, 'quantity' => 6]],
            ])
            ->assertRedirect();

        // The exit happened although nothing was ever collected (ADR-072):
        // no payment, no receipt — Réception/Caisse keeps that monopoly.
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(BillableItemStatus::Pending, BillableItem::query()->sole()->status);

        $request->refresh();
        $this->assertSame(CareConsumableRequestStatus::Served, $request->status);
        $this->assertNotNull($request->served_at);
        $this->assertSame($pharmacist->id, $request->served_by);

        // FEFO: the nearest expiration is emptied first.
        $this->assertSame(0, MedicineLot::query()->where('lot_number', 'SOON')->sole()->quantity_on_hand);
        $this->assertSame(8, MedicineLot::query()->where('lot_number', 'LATER')->sole()->quantity_on_hand);

        $movements = PharmacyStockMovement::query()->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertTrue($movements->every(
            fn (PharmacyStockMovement $movement) => $movement->type === PharmacyStockMovementType::Dispensing,
        ));
        $this->assertSame(-4, (int) $movements->first()->quantity_delta);
        $this->assertSame(-2, (int) $movements->last()->quantity_delta);
        $this->assertStringContainsString($request->request_number, $movements->first()->reason);

        $this->assertSame(6, $line->fresh()->quantity_served);
        $this->assertSame(2, $line->fresh()->allocations()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'pharmacy.care_consumables.serve']);
    }

    public function test_serving_never_consumes_a_quantity_already_reserved_for_a_prescription(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'ONLY', 'quantity' => 5, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 3]],
        ])->assertRedirect();

        // 4 of the 5 units are already promised to a prescription.
        $this->reserveForPrescription($consumable, 4, $nurse);

        $request = CareConsumableRequest::query()->with('lines')->sole();
        $line = $request->lines->sole();

        $this->actingAs($this->pharmacist())
            ->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
                'lines' => [['uuid' => $line->uuid, 'quantity' => 3]],
            ])
            ->assertSessionHasErrors('lines');

        // Nothing moved: no partial exit, no negative balance.
        $this->assertSame(5, MedicineLot::query()->sole()->quantity_on_hand);
        $this->assertSame(0, PharmacyStockMovement::query()->count());
        $this->assertSame(CareConsumableRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_a_partial_exit_keeps_the_request_open_for_the_remainder(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 5]],
        ])->assertRedirect();

        $request = CareConsumableRequest::query()->with('lines')->sole();
        $line = $request->lines->sole();
        $pharmacist = $this->pharmacist();

        $this->actingAs($pharmacist)->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
            'lines' => [['uuid' => $line->uuid, 'quantity' => 2]],
        ])->assertRedirect();

        $request->refresh();
        $this->assertSame(CareConsumableRequestStatus::PartiallyServed, $request->status);
        $this->assertNull($request->served_at);
        $this->assertSame(3, $line->fresh()->remainingQuantity());

        $this->actingAs($pharmacist)->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
            'lines' => [['uuid' => $line->uuid, 'quantity' => 3]],
        ])->assertRedirect();

        $this->assertSame(CareConsumableRequestStatus::Served, $request->fresh()->status);
        $this->assertSame(5, MedicineLot::query()->sole()->quantity_on_hand);

        // Over-serving the completed request is refused.
        $this->actingAs($pharmacist)->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
            'lines' => [['uuid' => $line->uuid, 'quantity' => 1]],
        ])->assertSessionHasErrors('request');
    }

    public function test_a_pending_request_is_cancelled_with_a_reason_and_releases_its_pending_charge(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        $this->giveActiveTariff($consumable->catalogItem, $nurse, 2500);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 2]],
        ])->assertRedirect();

        $request = CareConsumableRequest::query()->sole();

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/consumables/{$request->uuid}/cancel", [
                'reason' => 'Déclaré par erreur sur ce passage',
            ])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(CareConsumableRequestStatus::Cancelled, $request->status);
        $this->assertSame('Déclaré par erreur sur ce passage', $request->cancellation_reason);
        $this->assertSame($nurse->id, $request->cancelled_by);

        // The request itself is never destroyed (ADR-010).
        $this->assertDatabaseCount('care_consumable_requests', 1);
        $this->assertSame(BillableItemStatus::Cancelled, BillableItem::query()->sole()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'care.consumables.cancel']);
    }

    public function test_a_served_request_can_no_longer_be_cancelled(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 1]],
        ])->assertRedirect();

        $request = CareConsumableRequest::query()->with('lines')->sole();
        $this->actingAs($this->pharmacist())->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
            'lines' => [['uuid' => $request->lines->sole()->uuid, 'quantity' => 1]],
        ])->assertRedirect();

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/consumables/{$request->uuid}/cancel", [
                'reason' => 'Trop tard',
            ])
            ->assertSessionHasErrors('request');

        $this->assertSame(CareConsumableRequestStatus::Served, $request->fresh()->status);
    }

    public function test_the_care_page_exposes_only_parapharmacy_consumables_and_never_a_price(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 12, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        $this->giveActiveTariff($consumable->catalogItem, $nurse, 4000);
        $this->consumable(
            $nurse,
            [['lot' => 'B', 'quantity' => 30, 'expires_at' => now()->addYear()->toDateString()]],
            form: MedicineForm::Tablet,
            name: 'Amoxicilline 500 mg',
        );

        $this->actingAs($nurse)->get(route('care.orientations.show', $orientation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Care/Show')
                ->where('capabilities.can_request_consumables', true)
                ->has('consumableCatalog', 1)
                ->where('consumableCatalog.0.name', 'Compresses stériles')
                ->where('consumableCatalog.0.available_quantity', 12)
                ->where('consumableCatalog.0.medicine_uuid', $consumable->uuid)
                // No amount is ever serialized to a clinician (ADR-036).
                ->where('consumableCatalog.0', fn ($item) => ! array_key_exists('sale_price', (array) $item)
                    && ! array_key_exists('unit_price', (array) $item))
                ->has('consumableRequests', 0)
            );
    }

    public function test_the_pharmacy_workspace_lists_soins_requests_for_whoever_may_view_them(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 9, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 4]],
        ])->assertRedirect();

        $this->actingAs($this->pharmacist())->get('/pharmacy/care-consumables')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/CareConsumables/Index')
                ->where('capabilities.can_view_care_consumables', true)
                ->where('capabilities.can_serve_care_consumables', true)
                ->where('careConsumables.summary.pending', 1)
                ->where('careConsumables.summary.lines_to_serve', 4)
                ->has('careConsumables.requests', 1)
                ->where('careConsumables.requests.0.can_be_served', true)
            );

        // Viewing is separate from serving.
        $observer = $this->userWithPermissions(['pharmacy.view', 'care_consumables.view'], 'PHARMACY_OBSERVER');
        $this->actingAs($observer)->get('/pharmacy/care-consumables')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_serve_care_consumables', false)
                ->has('careConsumables.requests', 1)
            );
    }

    /**
     * Le propriétaire a signalé le 2026-09-16 que l'écran laissait croire
     * que ce matériel était gratuit. Il ne l'est pas — il est facturé
     * ligne par ligne et encaissé à la Caisse — mais l'écran n'en disait
     * rien. Ce test fixe ce que la Pharmacie doit pouvoir lire.
     */
    public function test_the_pharmacy_queue_shows_what_the_patient_owes_for_the_consumables(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 20, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        $this->giveActiveTariff($consumable->catalogItem, $nurse, 3000);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 3]],
        ])->assertRedirect();

        $this->actingAs($this->pharmacist())->get('/pharmacy/care-consumables')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('careConsumables.summary.unbilled_lines', 0)
                ->where('careConsumables.requests.0.billing.total_amount', 9000)
                ->where('careConsumables.requests.0.billing.unbilled_lines', 0)
                ->where('careConsumables.requests.0.lines.0.billing.state', 'PENDING')
                ->where('careConsumables.requests.0.lines.0.billing.amount', 9000)
                ->where('careConsumables.requests.0.lines.0.billing.needs_attention', false)
            );
    }

    /**
     * Le seul chemin par lequel un consommable finit réellement gratuit.
     *
     * La facturation est volontairement non bloquante (ADR-072) : une
     * compresse déjà posée sur une plaie ne s'annule pas parce qu'un tarif
     * manque. Mais l'échec était silencieux — plus rien ne le signalait
     * ensuite. Il doit désormais se compter et se nommer.
     */
    public function test_a_consumable_that_could_not_be_priced_is_reported_as_unbilled(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        // Aucun appel à giveActiveTariff() : le prix de vente n'est pas
        // configuré, exactement le cas que la facturation avale en silence.
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 20, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 2]],
        ])->assertRedirect();

        // La déclaration et la notification Pharmacie survivent : le geste
        // clinique n'est jamais annulé par un défaut de paramétrage.
        $request = CareConsumableRequest::query()->with('lines')->sole();
        $this->assertNull($request->lines->sole()->billable_item_id);

        $this->actingAs($this->pharmacist())->get('/pharmacy/care-consumables')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('careConsumables.summary.unbilled_lines', 1)
                ->where('careConsumables.requests.0.billing.unbilled_lines', 1)
                ->where('careConsumables.requests.0.billing.total_amount', 0)
                ->where('careConsumables.requests.0.lines.0.billing.state', 'NOT_BILLED')
                ->where('careConsumables.requests.0.lines.0.billing.needs_attention', true)
                ->where(
                    'careConsumables.requests.0.lines.0.billing.reason',
                    fn (string $reason) => str_contains($reason, 'Réception'),
                )
            );
    }

    /**
     * ADR-036 — un soignant ne voit et ne saisit jamais un montant. Rendre
     * la facturation visible côté Pharmacie ne doit pas la faire fuiter au
     * poste de soins, et la garantie tient au défaut de `present()`, pas à
     * la vigilance de chaque appelant.
     */
    public function test_the_soins_worksheet_never_carries_a_price(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $this->markEpisodeSelfFunded($orientation->episode_id, $nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 20, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        $this->giveActiveTariff($consumable->catalogItem, $nurse, 3000);

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 3]],
        ])->assertRedirect();

        $projection = app(\App\Services\Care\CareConsumableDirectory::class)
            ->forOrientation($orientation->id)
            ->first();

        $this->assertNull($projection['billing']);
        $this->assertNull($projection['lines'][0]['billing']);
        $this->assertStringNotContainsString('3000', json_encode($projection));
    }

    public function test_the_pharmacy_queue_is_hidden_without_the_view_permission(): void
    {
        $blind = $this->userWithPermissions(['pharmacy.view'], 'PHARMACY_BLIND');

        $this->actingAs($blind)->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pharmacy.capabilities.can_view_care_consumables', false)
                ->where('pharmacy.careConsumableCount', 0)
            );

        $this->actingAs($blind)->get('/pharmacy/care-consumables')->assertForbidden();
    }

    public function test_a_declaration_cannot_target_another_visits_request_when_cancelling(): void
    {
        $nurse = $this->nurse();
        $first = $this->activeCareOrientation($nurse);
        $second = $this->activeCareOrientation($nurse);
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)->put("/care/orientations/{$first->uuid}/record", [
            'consumables' => [['medicine_uuid' => $consumable->uuid, 'quantity' => 1]],
        ])->assertRedirect();

        $request = CareConsumableRequest::query()->sole();

        $this->actingAs($nurse)
            ->post("/care/orientations/{$second->uuid}/consumables/{$request->uuid}/cancel", [
                'reason' => 'Mauvais passage',
            ])
            ->assertNotFound();

        $this->assertSame(CareConsumableRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_an_act_suggests_its_configured_material_to_the_nurse(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $act = CatalogItem::query()->where('code', 'PANSEMENT')->sole();
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 40, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        CareActConsumable::query()->create([
            'catalog_item_id' => $act->id,
            'medicine_id' => $consumable->id,
            'default_quantity' => 2,
            'position' => 0,
        ]);

        $this->actingAs($nurse)->get(route('care.orientations.show', $orientation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Care/Show')
                ->where('procedureCatalog', fn ($catalog) => collect($catalog)
                    ->firstWhere('code', 'PANSEMENT')['default_consumables'] === [[
                        'medicine_uuid' => $consumable->uuid,
                        'code' => $consumable->catalogItem->code,
                        'name' => 'Compresses stériles',
                        'unit' => 'paquet',
                        'quantity' => 2,
                    ]])
            );
    }

    public function test_the_suggestion_is_never_exposed_to_an_account_that_cannot_declare_material(): void
    {
        $nurse = $this->nurse(['care.view', 'care.create', 'care.update', 'care_consumables.view']);
        $orientation = $this->activeCareOrientation($nurse);
        $act = CatalogItem::query()->where('code', 'PANSEMENT')->sole();
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);
        CareActConsumable::query()->create([
            'catalog_item_id' => $act->id,
            'medicine_id' => $consumable->id,
            'default_quantity' => 2,
        ]);

        $this->actingAs($nurse)->get(route('care.orientations.show', $orientation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_request_consumables', false)
                ->where('procedureCatalog', fn ($catalog) => collect($catalog)
                    ->firstWhere('code', 'PANSEMENT')['default_consumables'] === [])
                ->has('consumableCatalog', 0)
            );
    }

    public function test_the_catalogue_administrator_configures_and_clears_an_acts_usual_material(): void
    {
        $admin = $this->userWithPermissions([
            'catalog.items.view', 'catalog.items.update',
        ], 'CATALOG_ADMIN');
        $act = CatalogItem::query()->create([
            'code' => 'CARE-LAVAGE-NEZ',
            'name' => 'Lavage de nez',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $consumable = $this->consumable($admin, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($admin)
            ->put("/administration/catalog/{$act->uuid}/care-consumables", [
                'consumables' => [[
                    'medicine_uuid' => $consumable->uuid,
                    'default_quantity' => 3,
                ]],
            ])
            ->assertRedirect();

        $row = CareActConsumable::query()->sole();
        $this->assertSame($act->id, $row->catalog_item_id);
        $this->assertSame(3, $row->default_quantity);
        $this->assertSame($admin->id, $row->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.care_act_consumables.update']);

        // An empty list clears every suggestion — configuration, not a
        // clinical record, so removal is legitimate.
        $this->actingAs($admin)
            ->put("/administration/catalog/{$act->uuid}/care-consumables", ['consumables' => []])
            ->assertRedirect();

        $this->assertSame(0, CareActConsumable::query()->count());
    }

    public function test_only_a_care_act_can_carry_usual_material_and_only_parapharmacy_is_accepted(): void
    {
        $admin = $this->userWithPermissions([
            'catalog.items.view', 'catalog.items.update',
        ], 'CATALOG_ADMIN');
        $consumable = $this->consumable($admin, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        // A pharmacy product is not a nursing act.
        $this->actingAs($admin)
            ->put("/administration/catalog/{$consumable->catalogItem->uuid}/care-consumables", [
                'consumables' => [['medicine_uuid' => $consumable->uuid, 'default_quantity' => 1]],
            ])
            ->assertSessionHasErrors('catalog_item');

        // And a medicine may never be attached to an act.
        $act = CatalogItem::query()->create([
            'code' => 'CARE-ACT-PROBE',
            'name' => 'Lavage de nez',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $medicine = $this->consumable(
            $admin,
            [['lot' => 'B', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()]],
            form: MedicineForm::Tablet,
            name: 'Amoxicilline 500 mg',
        );

        $this->actingAs($admin)
            ->put("/administration/catalog/{$act->uuid}/care-consumables", [
                'consumables' => [['medicine_uuid' => $medicine->uuid, 'default_quantity' => 1]],
            ])
            ->assertSessionHasErrors('consumables');

        $this->assertSame(0, CareActConsumable::query()->count());
    }

    public function test_configuring_usual_material_requires_the_catalogue_permission(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse);
        $act = CatalogItem::query()->where('code', 'PANSEMENT')->sole();
        $consumable = $this->consumable($nurse, [
            ['lot' => 'A', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($nurse)
            ->put("/administration/catalog/{$act->uuid}/care-consumables", [
                'consumables' => [['medicine_uuid' => $consumable->uuid, 'default_quantity' => 1]],
            ])
            ->assertForbidden();

        $this->assertSame(0, CareActConsumable::query()->count());
        $this->assertSame($orientation->uuid, $orientation->fresh()->uuid);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function nurse(?array $permissions = null): User
    {
        return $this->userWithPermissions($permissions ?? [
            'care.view', 'care.create', 'care.update', 'care.complete',
            'care_consumables.view', 'care_consumables.request', 'care_consumables.cancel',
        ], 'NURSE');
    }

    private function pharmacist(): User
    {
        return $this->userWithPermissions([
            'pharmacy.view', 'care_consumables.view', 'care_consumables.serve',
        ], 'PHARMACY');
    }

    private function activeCareOrientation(User $nurse): EpisodeOrientation
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $procedure = CatalogItem::query()->firstOrCreate(
            ['code' => 'PANSEMENT'],
            [
                'name' => 'Pansement simple',
                'type' => CatalogItemType::Service,
                'module' => CatalogModule::Care,
                'unit' => 'soin',
                'billable' => true,
                'stockable' => false,
                'reception_selectable' => true,
                'reception_routing_mode' => ReceptionRoutingMode::CareOnly,
                'created_by' => $nurse->id,
                'updated_by' => $nurse->id,
            ],
        );
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $procedure->uuid,
            'quantity' => 1,
        ]], $nurse);
        $orientation = $episode->orientations()->sole();
        $this->app->make(AcceptCareOrientationAction::class)->execute($orientation, $nurse);

        return $orientation->fresh();
    }

    /** @param array<int, array{lot: string, quantity: int, expires_at: string}> $lots */
    private function consumable(
        User $actor,
        array $lots,
        MedicineForm $form = MedicineForm::ParapharmacyConsumable,
        string $name = 'Compresses stériles',
    ): Medicine {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.(CatalogItem::query()->count() + 1),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'paquet',
            'billable' => true,
            'stockable' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $name,
            'form' => $form,
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($lots as $lot) {
            MedicineLot::query()->create([
                'medicine_id' => $medicine->id,
                'lot_number' => $lot['lot'],
                'expires_at' => $lot['expires_at'],
                'quantity_on_hand' => $lot['quantity'],
                'active' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        return $medicine->load('catalogItem');
    }

    private function reserveForPrescription(Medicine $medicine, int $quantity, User $actor): void
    {
        $lot = $medicine->lots()->orderBy('expires_at')->firstOrFail();
        MedicineStockReservation::query()->create([
            'prescription_line_id' => $this->prescriptionLineFor($medicine, $actor)->id,
            'medicine_lot_id' => $lot->id,
            'quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'status' => MedicineStockReservationStatus::Reserved,
            'reserved_at' => now(),
            'reserved_by' => $actor->id,
        ]);
    }

    private function prescriptionLineFor(Medicine $medicine, User $actor): PrescriptionLine
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $consultation = Consultation::query()->create([
            'episode_id' => $episode->id,
            'doctor_id' => $actor->id,
            'reason' => 'Réservation de stock concurrente',
            'consulted_at' => now(),
        ]);
        $prescription = Prescription::query()->create([
            'consultation_id' => $consultation->id,
            'status' => 'ACTIVE',
            'prescribed_at' => now(),
            'prescribed_by' => $actor->id,
        ]);

        return PrescriptionLine::query()->create([
            'prescription_id' => $prescription->id,
            'medicine_id' => $medicine->id,
            'medication_name' => $medicine->catalogItem->name,
            'quantity' => 4,
            'dosage' => '1',
            'frequency' => '1/j',
            'duration' => '1 j',
        ]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    private function giveActiveTariff(CatalogItem $item, User $actor, int $amount): CatalogTariff
    {
        return CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test',
            'created_by' => $actor->id,
        ]);
    }

    private function markEpisodeSelfFunded(int $episodeId, User $actor): void
    {
        Episode::query()->whereKey($episodeId)->update([
            'financial_mode' => 'SELF',
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $actor->id,
        ]);
    }

    private function userWithPermissions(array $permissions, string $roleCode): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
