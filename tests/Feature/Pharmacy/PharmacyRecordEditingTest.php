<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\SupplierCatalogFileKind;
use App\Models\CatalogTariff;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-098 — correcting what was recorded: a medicine (and its sale price, whose
 * previous version stays), a medicine family, a supplier invoice, a catalog's
 * note and a draft order. Nothing here is ever physically deleted.
 */
class PharmacyRecordEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'M', 'rivo.site.name' => 'Mampikony']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);

        $role = Role::query()->create(['code' => 'CATALOG_MANAGER', 'name' => 'Catalogue']);
        $role->permissions()->attach(Permission::query()->whereIn('name', [
            'pharmacy.view', 'stock.view', 'medicines.view', 'medicines.create', 'medicines.update', 'medicines.delete', 'medicines.restore',
            'catalog.items.create', 'catalog.items.update', 'catalog.tariffs.create', 'catalog.tariffs.update',
            'medicine_categories.view', 'medicine_categories.create', 'medicine_categories.update', 'medicine_categories.delete', 'medicine_categories.restore',
            'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update', 'purchase_orders.submit',
            'supplier_invoices.view', 'supplier_invoices.create', 'supplier_invoices.update',
            'supplier_catalogs.view', 'supplier_catalogs.update',
        ])->pluck('id'));
        $this->manager = User::factory()->create(['role_id' => $role->id]);
    }

    private function createMedicine(array $overrides = []): Medicine
    {
        $this->actingAs($this->manager)->post('/pharmacy/setup/medicines', [
            'code' => 'AMOX-500',
            'name' => 'Amoxil',
            'generic_name' => 'Amoxicilline',
            'form' => 'TABLET',
            'strength' => '500 mg',
            'unit' => 'boîte',
            'minimum_stock' => 5,
            'prescription_required' => true,
            'sale_price' => '4500',
            'tariff_reason' => 'Prix de vente initial',
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return Medicine::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function medicinePayload(array $overrides = []): array
    {
        return [
            'name' => 'Amoxil Gé',
            'generic_name' => 'Amoxicilline',
            'form' => 'TABLET',
            'strength' => '500 mg',
            'unit' => 'boîte',
            'minimum_stock' => 10,
            'prescription_required' => true,
            'sale_price' => '4500',
            ...$overrides,
        ];
    }

    public function test_a_medicine_is_corrected_and_a_new_price_keeps_the_previous_one(): void
    {
        $medicine = $this->createMedicine();

        $this->actingAs($this->manager)->get("/pharmacy/medicines/{$medicine->uuid}/edit")->assertOk();

        // A new price without its reason is refused: the history must say why.
        $this->put("/pharmacy/medicines/{$medicine->uuid}", $this->medicinePayload(['sale_price' => '5000']))
            ->assertSessionHasErrors('tariff_reason');

        $this->put("/pharmacy/medicines/{$medicine->uuid}", $this->medicinePayload(['sale_price' => '5000', 'tariff_reason' => 'Nouveau tarif fournisseur']))
            ->assertRedirect('/pharmacy/stock');

        $medicine->refresh()->load('catalogItem.currentStandardTariff');
        $this->assertSame('Amoxil Gé', $medicine->catalogItem->name);
        $this->assertSame('AMOX-500', $medicine->catalogItem->code);
        $this->assertSame(10, $medicine->minimum_stock);
        $this->assertSame(2, CatalogTariff::query()->where('catalog_item_id', $medicine->catalog_item_id)->count());
        $this->assertSame(500000, (int) round((float) $medicine->catalogItem->currentStandardTariff->amount * 100));
    }

    public function test_a_medicine_is_deactivated_with_a_reason_then_reactivated(): void
    {
        $medicine = $this->createMedicine();

        $this->actingAs($this->manager)->post("/pharmacy/medicines/{$medicine->uuid}/deactivate", [])->assertSessionHasErrors('reason');
        $this->post("/pharmacy/medicines/{$medicine->uuid}/deactivate", ['reason' => 'Retiré du marché'])->assertSessionHasNoErrors();

        $medicine->refresh();
        $this->assertFalse($medicine->active);
        $this->assertSame('Retiré du marché', $medicine->delete_reason);
        $this->assertFalse($medicine->trashed());

        $this->post("/pharmacy/medicines/{$medicine->uuid}/reactivate")->assertSessionHasNoErrors();
        $this->assertTrue($medicine->refresh()->active);
        $this->assertNull($medicine->delete_reason);
    }

    public function test_a_family_is_archived_only_once_it_holds_no_active_medicine(): void
    {
        $this->actingAs($this->manager)->post('/pharmacy/setup/categories', ['code' => 'ATB', 'name' => 'Antibiotiques'])->assertSessionHasNoErrors();
        $category = MedicineCategory::query()->where('code', 'ATB')->firstOrFail();
        $medicine = $this->createMedicine(['medicine_category_uuid' => $category->uuid]);

        $this->put("/pharmacy/setup/categories/{$category->uuid}", ['name' => 'Anti-infectieux'])->assertSessionHasNoErrors();
        $this->assertSame('Anti-infectieux', $category->refresh()->name);

        $this->delete("/pharmacy/setup/categories/{$category->uuid}", ['reason' => 'Regroupement'])->assertSessionHasErrors('category');
        $this->assertFalse($category->refresh()->trashed());

        $this->post("/pharmacy/medicines/{$medicine->uuid}/deactivate", ['reason' => 'Plus utilisé']);
        $this->delete("/pharmacy/setup/categories/{$category->uuid}", ['reason' => 'Regroupement'])->assertSessionHasNoErrors();
        $this->assertTrue($category->refresh()->trashed());

        $this->post("/pharmacy/setup/categories/{$category->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertFalse($category->refresh()->trashed());
    }

    public function test_a_supplier_invoice_is_corrected_without_touching_the_stock(): void
    {
        $medicine = $this->createMedicine();
        $supplier = MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis']);
        $movements = PharmacyStockMovement::query()->count();

        $this->actingAs($this->manager)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'F-001',
            'invoice_date' => now()->toDateString(),
            'lines' => [['medicine_uuid' => $medicine->uuid, 'description' => 'Amoxil', 'quantity' => 10, 'unit_price' => '300']],
        ])->assertSessionHasNoErrors();
        $invoice = SupplierInvoice::query()->where('invoice_number', 'F-001')->firstOrFail();

        $this->get("/pharmacy/supplier-invoices/{$invoice->uuid}/edit")->assertOk();
        $this->post("/pharmacy/supplier-invoices/{$invoice->uuid}/update", [
            'invoice_number' => 'F-001-B',
            'invoice_date' => now()->toDateString(),
            'lines' => [['medicine_uuid' => $medicine->uuid, 'description' => 'Amoxil 500', 'quantity' => 12, 'unit_price' => '300']],
        ])->assertRedirect("/pharmacy/supplier-invoices/{$invoice->uuid}");

        $invoice->refresh()->load('lines');
        $this->assertSame('F-001-B', $invoice->invoice_number);
        $this->assertSame(3600, (int) round((float) $invoice->total_amount));
        $this->assertCount(1, $invoice->lines);
        $this->assertSame($movements, PharmacyStockMovement::query()->count());
    }

    public function test_a_catalog_note_is_corrected_but_never_its_file(): void
    {
        $supplier = MedicineSupplier::query()->create(['code' => 'SOMAPHAR', 'name' => 'Somaphar']);
        $catalog = $supplier->catalogs()->create([
            'original_name' => 'tarif.pdf',
            'path' => 'suppliers/x/tarif.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'kind' => SupplierCatalogFileKind::Pdf,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->patch("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}", ['catalog_date' => '2026-09-01', 'notes' => 'Valable jusqu’en décembre'])
            ->assertSessionHasNoErrors();

        $catalog->refresh();
        $this->assertSame('Valable jusqu’en décembre', $catalog->notes);
        $this->assertSame('tarif.pdf', $catalog->original_name);
    }

    public function test_only_a_draft_order_opens_for_editing(): void
    {
        $medicine = $this->createMedicine();
        $supplier = MedicineSupplier::query()->create(['code' => 'DISTRI', 'name' => 'Distri']);

        $this->actingAs($this->manager)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [['medicine_uuid' => $medicine->uuid, 'quantity_ordered' => 5, 'unit_price' => '300']],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::query()->latest('id')->firstOrFail();

        $this->get("/pharmacy/purchase-orders/{$order->uuid}/edit")->assertOk();
        $this->put("/pharmacy/purchase-orders/{$order->uuid}", [
            'lines' => [['medicine_uuid' => $medicine->uuid, 'quantity_ordered' => 8, 'unit_price' => '300']],
        ])->assertRedirect("/pharmacy/purchase-orders/{$order->uuid}");
        $this->assertSame(8, $order->refresh()->lines()->first()->quantity_ordered);

        $this->post("/pharmacy/purchase-orders/{$order->uuid}/submit")->assertSessionHasNoErrors();
        $this->get("/pharmacy/purchase-orders/{$order->uuid}/edit")->assertRedirect("/pharmacy/purchase-orders/{$order->uuid}");
    }
}
