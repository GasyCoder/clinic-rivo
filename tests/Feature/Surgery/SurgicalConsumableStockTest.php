<?php

namespace Tests\Feature\Surgery;

use App\Actions\Catalog\SyncCareActConsumablesAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Enums\BillableItemStatus;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\SurgicalRequestStatus;
use App\Models\AuditLog;
use App\Models\BillableItem;
use App\Models\CareActConsumable;
use App\Models\CareConsumableRequest;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\Role;
use App\Models\SurgicalConsumable;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-169 — le matériel du bloc passe par le stock de la Pharmacie : même
 * demande, même file, même sortie FEFO et même facturation que les
 * consommables Soins (ADR-072) et Maternité (ADR-142). La ligne « hors stock »
 * reste possible pour un produit absent du stock : ni sortie, ni facture.
 */
class SurgicalConsumableStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_stock_material_declared_at_the_bloc_becomes_a_pharmacy_request_billed_per_line(): void
    {
        $surgeon = $this->surgeon();
        $surgicalRequest = $this->surgicalRequest($surgeon);
        $gauze = $this->product($surgeon, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 50, 500);

        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $gauze->uuid, 'quantity' => 6]],
            'notes' => 'Pansement de fin d’intervention',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $request = CareConsumableRequest::query()->with('lines')->sole();
        $this->assertSame('SURGERY', $request->source_module);
        $this->assertSame($surgicalRequest->id, $request->surgical_request_id);
        $this->assertSame($surgicalRequest->episode_id, $request->episode_id);
        $this->assertSame(CareConsumableRequestStatus::Pending, $request->status);
        $this->assertSame(6, $request->lines->sole()->quantity_requested);

        // Facturé en plus de l'intervention, au tarif serveur, encaissé à la Caisse.
        $billable = BillableItem::query()->sole();
        $this->assertSame($billable->id, $request->lines->sole()->billable_item_id);
        $this->assertSame(BillableItemStatus::Pending, $billable->status);
        $this->assertSame('3000.00', (string) $billable->total_amount);

        // Rien ne sort du stock tant que la Pharmacie n'a pas servi.
        $this->assertSame(50, MedicineLot::query()->sole()->quantity_on_hand);
        $this->assertTrue(AuditLog::query()->where('action', 'surgery.consumables.request')->exists());
    }

    public function test_the_bloc_accepts_parapharmacy_and_material_configured_for_a_surgical_act_only(): void
    {
        $surgeon = $this->surgeon();
        $surgicalRequest = $this->surgicalRequest($surgeon);
        $tablet = $this->product($surgeon, 'Paracétamol 500 mg', MedicineForm::Tablet, 20, 200);

        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $tablet->uuid, 'quantity' => 2]],
        ])->assertSessionHasErrors('lines');
        $this->assertSame(0, CareConsumableRequest::query()->count());

        // Une fois configuré comme matériel habituel d'un acte de Chirurgie, le
        // produit devient déclarable depuis le bloc.
        $suture = $this->product($surgeon, 'Fil résorbable 2/0', MedicineForm::Other, 10, 4000);
        $act = $this->surgicalAct($surgeon, 'CHIR-TEST', 'Appendicectomie');
        CareActConsumable::query()->create([
            'catalog_item_id' => $act->id, 'medicine_id' => $suture->id, 'default_quantity' => 2, 'position' => 0,
            'created_by' => $surgeon->id, 'updated_by' => $surgeon->id,
        ]);

        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $suture->uuid, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, CareConsumableRequest::query()->count());
    }

    public function test_a_cancelled_surgical_request_takes_no_more_material(): void
    {
        $surgeon = $this->surgeon();
        $surgicalRequest = $this->surgicalRequest($surgeon);
        $surgicalRequest->forceFill(['status' => SurgicalRequestStatus::Cancelled, 'cancelled_at' => now()])->save();
        $gauze = $this->product($surgeon, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 50, 500);

        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $gauze->uuid, 'quantity' => 2]],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, CareConsumableRequest::query()->count());
    }

    public function test_declaring_bloc_material_requires_the_surgery_consumables_right(): void
    {
        $surgeon = $this->surgeon();
        $surgicalRequest = $this->surgicalRequest($surgeon);
        $gauze = $this->product($surgeon, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 50, 500);
        $surgeon->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'surgery.consumables.create')->value('id') => ['effect' => 'deny'],
        ]);

        $this->actingAs($surgeon->fresh())->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $gauze->uuid, 'quantity' => 2]],
        ])->assertForbidden();

        $props = $this->actingAs($surgeon->fresh())->get("/surgery/{$surgicalRequest->uuid}")->viewData('page')['props'];
        $this->assertSame([], $props['consumableCatalog']);
        $this->assertSame([], $props['consumableSuggestions']);
        $this->assertSame(0, CareConsumableRequest::query()->count());
    }

    public function test_a_bloc_request_can_be_cancelled_until_the_pharmacy_serves_it(): void
    {
        $surgeon = $this->surgeon();
        $surgicalRequest = $this->surgicalRequest($surgeon);
        $gauze = $this->product($surgeon, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 50, 500);
        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $gauze->uuid, 'quantity' => 4]],
        ]);
        $request = CareConsumableRequest::query()->sole();

        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests/{$request->uuid}/cancel", [
            'reason' => 'Saisi par erreur',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(CareConsumableRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame(BillableItemStatus::Cancelled, BillableItem::query()->sole()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'surgery.consumables.cancel')->exists());
    }

    public function test_a_request_of_another_dossier_is_not_reachable_from_this_one(): void
    {
        $surgeon = $this->surgeon();
        $first = $this->surgicalRequest($surgeon);
        $other = $this->surgicalRequest($surgeon);
        $gauze = $this->product($surgeon, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 50, 500);
        $this->actingAs($surgeon)->post("/surgery/{$other->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $gauze->uuid, 'quantity' => 1]],
        ]);
        $request = CareConsumableRequest::query()->sole();

        $this->actingAs($surgeon)->post("/surgery/{$first->uuid}/consumable-requests/{$request->uuid}/cancel", [
            'reason' => 'Mauvais dossier',
        ])->assertNotFound();

        $this->assertSame(CareConsumableRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_the_pharmacy_serves_bloc_material_without_waiting_for_payment_and_labels_its_origin(): void
    {
        $surgeon = $this->surgeon();
        $surgicalRequest = $this->surgicalRequest($surgeon);
        $gauze = $this->product($surgeon, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 20, 500);
        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $gauze->uuid, 'quantity' => 5]],
        ]);
        $request = CareConsumableRequest::query()->with('lines')->sole();
        $pharmacist = $this->pharmacist();

        $queue = $this->actingAs($pharmacist)->get('/pharmacy/care-consumables')->viewData('page')['props']['careConsumables'];
        $this->assertSame('Bloc opératoire', $queue['requests'][0]['source_label']);

        $this->actingAs($pharmacist)->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
            'lines' => [['uuid' => $request->lines->sole()->uuid, 'quantity' => 5]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(15, MedicineLot::query()->sole()->quantity_on_hand);
        $this->assertSame(BillableItemStatus::Pending, BillableItem::query()->sole()->status);
        $movement = PharmacyStockMovement::query()->sole();
        $this->assertStringStartsWith('Bloc opératoire — patient', $movement->destination);
        $this->assertStringContainsString('Consommables Bloc opératoire', $movement->reason);
    }

    public function test_the_surgery_dossier_serves_catalogue_suggestions_and_requests_without_any_amount(): void
    {
        $surgeon = $this->surgeon();
        $gauze = $this->product($surgeon, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 30, 500);
        $act = $this->surgicalAct($surgeon, 'CHIR-TEST', 'Appendicectomie');
        CareActConsumable::query()->create([
            'catalog_item_id' => $act->id, 'medicine_id' => $gauze->id, 'default_quantity' => 3, 'position' => 0,
            'created_by' => $surgeon->id, 'updated_by' => $surgeon->id,
        ]);
        $surgicalRequest = $this->surgicalRequest($surgeon, $act);
        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumable-requests", [
            'lines' => [['medicine_uuid' => $gauze->uuid, 'quantity' => 3]],
        ]);

        $props = $this->actingAs($surgeon)->get("/surgery/{$surgicalRequest->uuid}")->viewData('page')['props'];

        $this->assertSame([$gauze->uuid], collect($props['consumableCatalog'])->pluck('medicine_uuid')->all());
        $this->assertSame([['medicine_uuid' => $gauze->uuid, 'default_quantity' => 3]], $props['consumableSuggestions']);
        $this->assertCount(1, $props['consumableRequests']);

        // ADR-036 — le bloc ne voit ni ne saisit de prix.
        $serialised = json_encode([$props['consumableRequests'], $props['consumableCatalog']]);
        $this->assertStringNotContainsString('1500', $serialised);
        $this->assertNull($props['consumableRequests'][0]['billing']);
        $this->assertNull($props['consumableRequests'][0]['lines'][0]['billing']);
    }

    public function test_the_administration_can_configure_any_stockable_product_for_a_surgical_act(): void
    {
        $admin = $this->surgeon();
        $admin->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'catalog.items.update')->value('id') => ['effect' => 'allow'],
        ]);
        $act = $this->surgicalAct($admin, 'CHIR-TEST', 'Appendicectomie');
        $suture = $this->product($admin, 'Fil résorbable 2/0', MedicineForm::Other, 10, 4000);

        app(SyncCareActConsumablesAction::class)->execute(
            $act, [['medicine_uuid' => $suture->uuid, 'default_quantity' => 2]], CatalogActor::fromUser($admin->fresh()),
        );

        $this->assertSame(2, CareActConsumable::query()->where('catalog_item_id', $act->id)->sole()->default_quantity);
    }

    public function test_an_out_of_stock_line_is_still_recorded_without_stock_or_invoice(): void
    {
        $surgeon = $this->surgeon();
        $surgicalRequest = $this->surgicalRequest($surgeon);

        $this->actingAs($surgeon)->post("/surgery/{$surgicalRequest->uuid}/consumables", [
            'label' => 'Agrafes cutanées (don)', 'quantity' => 1, 'unit' => 'boîte',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, SurgicalConsumable::query()->count());
        $this->assertSame(0, CareConsumableRequest::query()->count());
        $this->assertSame(0, BillableItem::query()->count());
        $this->assertSame(0, PharmacyStockMovement::query()->count());
    }

    // ── Aides ────────────────────────────────────────────────────────────

    private function surgeon(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', 'SURGERY')->value('id')])->fresh('role');
    }

    private function pharmacist(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
    }

    private function surgicalRequest(User $actor, ?CatalogItem $act = null): SurgicalRequest
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'birth_date' => '1988-03-04', 'sex' => 'M',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $actor);
        Episode::query()->whereKey($episode->id)->update([
            'financial_mode' => 'SELF',
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $actor->id,
        ]);

        return SurgicalRequest::query()->create([
            'episode_id' => $episode->id, 'catalog_item_id' => $act?->id,
            'procedure_name' => $act?->name ?? 'Appendicectomie',
            'status' => SurgicalRequestStatus::Pending, 'requested_by' => $actor->id, 'created_by' => $actor->id,
        ]);
    }

    private function surgicalAct(User $actor, string $code, string $name): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code, 'name' => $name, 'type' => CatalogItemType::Service, 'module' => CatalogModule::Surgery,
            'unit' => 'acte', 'billable' => true, 'stockable' => false, 'reception_selectable' => false,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function product(User $actor, string $name, MedicineForm $form, int $stock, int $price): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.(CatalogItem::query()->count() + 1), 'name' => $name, 'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy, 'unit' => 'unité', 'billable' => true, 'stockable' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id, 'amount' => number_format($price, 2, '.', ''), 'currency' => 'MGA',
            'effective_from' => now(), 'active_key' => 'CURRENT', 'change_reason' => 'Tarif de test', 'created_by' => $actor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id, 'generic_name' => $name, 'form' => $form, 'active' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        MedicineLot::query()->create([
            'medicine_id' => $medicine->id, 'lot_number' => 'L-'.$medicine->id, 'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => $stock, 'active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        return $medicine->load('catalogItem');
    }
}
