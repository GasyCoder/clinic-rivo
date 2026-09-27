<?php

namespace Tests\Feature\Pharmacy;

use App\Actions\Pharmacy\SetMedicineSupplierOfferAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\SupplierCatalogFileKind;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\ProcurementFormOptions;
use App\Services\Pharmacy\SupplierOfferComparison;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-097 — spec §5/§6: a medicine can be quoted simultaneously by several
 * suppliers at different prices, and a superseded offer must remain
 * queryable forever rather than being overwritten (SetMedicineSupplierOfferAction
 * copies SetCatalogTariffAction's close-old/open-new mechanism).
 */
class MedicineSupplierOfferTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(array $permissions): User
    {
        (new PermissionSeeder)->run();

        $role = Role::query()->create([
            'code' => 'OFFER_TEST_'.Role::query()->count(),
            'name' => 'Prix fournisseur (test)',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function supplier(string $code = 'FOUR-01', string $name = 'Fournisseur A'): MedicineSupplier
    {
        return MedicineSupplier::query()->create(['code' => $code, 'name' => $name]);
    }

    private function medicine(User $actor, string $name = 'Amoxicilline 500 mg'): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.str_pad((string) (CatalogItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'boîte',
            'billable' => false,
            'stockable' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Amoxicilline',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function test_the_comparison_lists_every_supplier_price_for_the_same_medicine_cheapest_first(): void
    {
        $actor = $this->setupUser(['medicine_supplier_offers.create', 'medicine_supplier_offers.view']);
        $medicine = $this->medicine($actor);
        $cheap = $this->supplier('FOUR-A', 'Fournisseur A');
        $dear = $this->supplier('FOUR-B', 'Fournisseur B');
        $action = app(SetMedicineSupplierOfferAction::class);
        $action->execute($medicine, $dear, '120', 'Tarif 2026', CatalogActor::fromUser($actor));
        $action->execute($medicine, $cheap, '100', 'Tarif 2026', CatalogActor::fromUser($actor));

        $comparison = app(SupplierOfferComparison::class)->forSite();
        $line = $comparison['medicines'][0];

        $this->assertSame($medicine->uuid, $line['medicine_uuid']);
        $this->assertSame(['Fournisseur A', 'Fournisseur B'], array_column($line['quotes'], 'supplier_name'));
        $this->assertSame('100.00', $line['best_price']);

        // Restricting the comparison keeps only the chosen suppliers' prices.
        $restricted = app(SupplierOfferComparison::class)->forSite([$dear->uuid]);
        $this->assertSame(['Fournisseur B'], array_column($restricted['medicines'][0]['quotes'], 'supplier_name'));
    }

    public function test_an_order_form_offers_the_supplier_own_products_not_the_whole_catalogue(): void
    {
        $actor = $this->setupUser(['medicine_supplier_offers.create']);
        $quoted = $this->medicine($actor, 'Amoxicilline 500 mg');
        $this->medicine($actor, 'Paracétamol 500 mg');
        $supplier = $this->supplier();
        app(SetMedicineSupplierOfferAction::class)->execute($quoted, $supplier, '100', 'Tarif 2026', CatalogActor::fromUser($actor));

        $medicines = app(ProcurementFormOptions::class)->orderMedicines($supplier->fresh());

        $this->assertSame(['Amoxicilline 500 mg'], array_column($medicines, 'name'));
    }

    /**
     * ADR-098 — the owner's case: one supplier, one imported catalogue, no
     * medicine in the clinic catalogue yet. Every catalogue line must be
     * orderable, otherwise a first purchase is impossible.
     */
    public function test_the_order_form_also_offers_the_active_catalogue_lines_the_clinic_has_not_taken_up(): void
    {
        $actor = $this->setupUser(['medicine_supplier_offers.create']);
        $supplier = $this->supplier();
        $catalog = $this->activeCatalog($supplier);
        $catalog->items()->create(['reference' => 'ARB-001', 'medicine_label' => 'Zinc 20 mg', 'presentation' => 'boîte de 30', 'supplier_price' => '4500.00', 'row_number' => 1]);
        $catalog->items()->create(['reference' => 'ARB-002', 'medicine_label' => 'Albendazole 400 mg', 'presentation' => null, 'supplier_price' => null, 'row_number' => 2]);

        $products = app(ProcurementFormOptions::class)->orderMedicines($supplier->fresh());

        $this->assertSame(['Albendazole 400 mg', 'Zinc 20 mg'], array_column($products, 'name'));
        $this->assertSame([false, false], array_column($products, 'in_clinic_catalog'));
        // A catalogue line is named by its own uuid: reading a form creates nothing.
        $this->assertNull($products[0]['uuid']);
        $this->assertSame(0, Medicine::query()->count());
    }

    /** An old price list is history, not something to order from. */
    public function test_an_archived_catalogue_is_never_offered_for_ordering(): void
    {
        $actor = $this->setupUser(['medicine_supplier_offers.create']);
        $supplier = $this->supplier();
        $old = $this->activeCatalog($supplier);
        $old->update(['active_key' => null]);
        $old->items()->create(['reference' => 'OLD-1', 'medicine_label' => 'Tarif 2024', 'supplier_price' => '100.00', 'row_number' => 1]);

        $this->assertSame([], app(ProcurementFormOptions::class)->orderMedicines($supplier->fresh()));
    }

    private function activeCatalog(MedicineSupplier $supplier): SupplierCatalog
    {
        return $supplier->catalogs()->create([
            'original_name' => 'catalogue.xlsx',
            'path' => 'suppliers/'.$supplier->uuid.'/catalogue.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 1024,
            'kind' => SupplierCatalogFileKind::Excel,
            'active_key' => 'ACTIVE',
            'imported_at' => now(),
        ]);
    }

    public function test_creating_an_offer_requires_the_create_permission_and_updating_it_requires_update(): void
    {
        $supplier = $this->supplier();
        $viewer = $this->setupUser(['medicine_supplier_offers.view']);
        $medicine = $this->medicine($viewer);

        $this->actingAs($viewer)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 100,
            'change_reason' => 'Prix catalogue initial',
        ])->assertForbidden();

        $creator = $this->setupUser(['medicine_supplier_offers.view', 'medicine_supplier_offers.create']);

        $this->actingAs($creator)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 100,
            'change_reason' => 'Prix catalogue initial',
        ])->assertRedirect();

        // A second write on the same (medicine, supplier) pair now needs
        // .update, not .create — the "create vs update" split in
        // SetMedicineSupplierOfferAction is permission-gated, not just
        // a data-shape difference.
        $this->actingAs($creator)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 120,
            'change_reason' => 'Hausse fournisseur',
        ])->assertForbidden();

        $updater = $this->setupUser(['medicine_supplier_offers.view', 'medicine_supplier_offers.update']);

        $this->actingAs($updater)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 120,
            'change_reason' => 'Hausse fournisseur',
        ])->assertRedirect();
    }

    public function test_a_superseded_offer_is_closed_and_never_overwritten(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['medicine_supplier_offers.create', 'medicine_supplier_offers.update']);
        $medicine = $this->medicine($user);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 100,
            'change_reason' => 'Prix catalogue initial',
        ]);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 120,
            'change_reason' => 'Hausse fournisseur',
        ]);

        $offers = MedicineSupplierOffer::query()->orderBy('id')->get();
        $this->assertCount(2, $offers);

        [$old, $new] = $offers;
        $this->assertSame('100.00', $old->quoted_price);
        $this->assertFalse($old->isCurrent());
        $this->assertNotNull($old->effective_until);
        $this->assertNull($old->active_key);

        $this->assertSame('120.00', $new->quoted_price);
        $this->assertTrue($new->isCurrent());
        $this->assertSame('CURRENT', $new->active_key);

        // The old row must still be readable — spec §6's "l'ancien prix
        // reste consultable pour toujours" is not just soft-delete-safe,
        // it's never even touched again.
        $this->assertDatabaseHas('medicine_supplier_offers', [
            'id' => $old->id,
            'quoted_price' => '100.00',
        ]);
    }

    public function test_resubmitting_the_identical_price_is_rejected(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['medicine_supplier_offers.create', 'medicine_supplier_offers.update']);
        $medicine = $this->medicine($user);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 100,
            'change_reason' => 'Prix catalogue initial',
        ]);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 100,
            'change_reason' => 'Resaisie du même prix',
        ])->assertSessionHasErrors('quoted_price');

        $this->assertSame(1, MedicineSupplierOffer::query()->count());
    }

    public function test_two_suppliers_can_hold_simultaneous_current_offers_for_the_same_medicine_at_different_prices(): void
    {
        $supplierA = $this->supplier('FOUR-A', 'Fournisseur A');
        $supplierB = $this->supplier('FOUR-B', 'Fournisseur B');
        $user = $this->setupUser(['medicine_supplier_offers.create', 'medicine_supplier_offers.update']);
        $medicine = $this->medicine($user);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplierA->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 100,
            'change_reason' => 'Offre fournisseur A',
        ])->assertRedirect();

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplierB->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 130,
            'change_reason' => 'Offre fournisseur B',
        ])->assertRedirect();

        $current = MedicineSupplierOffer::query()->where('active_key', 'CURRENT')->get();
        $this->assertCount(2, $current);
        $this->assertEqualsCanonicalizing(
            ['100.00', '130.00'],
            $current->pluck('quoted_price')->all(),
        );

        $this->assertTrue($medicine->currentOfferFor($supplierA)->first()->isCurrent());
        $this->assertTrue($medicine->currentOfferFor($supplierB)->first()->isCurrent());
        $this->assertSame('100.00', $medicine->currentOfferFor($supplierA)->first()->quoted_price);
        $this->assertSame('130.00', $medicine->currentOfferFor($supplierB)->first()->quoted_price);
    }

    public function test_zero_or_negative_price_is_rejected(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['medicine_supplier_offers.create']);
        $medicine = $this->medicine($user);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/offers", [
            'medicine_uuid' => $medicine->uuid,
            'quoted_price' => 0,
            'change_reason' => 'Prix invalide',
        ])->assertSessionHasErrors('quoted_price');

        $this->assertSame(0, MedicineSupplierOffer::query()->count());
    }
}
