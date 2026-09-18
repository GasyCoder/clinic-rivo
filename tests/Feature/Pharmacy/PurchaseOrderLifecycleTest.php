<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\SupplierCatalogFileKind;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Pharmacy\SupplierOfferComparison;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR-097 — spec §8/§9/§14: a purchase order's lifecycle
 * (Draft -> Ordered -> PartiallyReceived -> Received / Cancelled), the fact
 * that receiving is a distinct, possibly partial step that never
 * auto-completes an order, and that two receptions at different prices must
 * both remain visible forever (PharmacyStockMovement's existing immutability
 * already guarantees this — nothing new is built for it here).
 */
class PurchaseOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);

        // ADR-098 — procurement is never part of the PHARMACY role: it is
        // granted to this account by name, as the Super Admin would.
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', self::PROCUREMENT_PERMISSIONS)->pluck('id'),
            ['effect' => 'allow'],
        );
    }

    private const PROCUREMENT_PERMISSIONS = [
        'medicine_suppliers.view',
        'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update',
        'purchase_orders.submit', 'purchase_orders.cancel',
        'goods_receipts.view', 'goods_receipts.create',
        // ADR-112 — correcting a purchase price at reception is a cost
        // decision, never part of the PHARMACY role.
        'stock.cost.record',
    ];

    public function test_procurement_is_not_part_of_the_pharmacy_role_by_default(): void
    {
        $pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);

        $this->actingAs($pharmacist)->get('/pharmacy')->assertRedirect('/');

        foreach (['/pharmacy/suppliers', '/pharmacy/purchase-orders', '/pharmacy/receipts', '/pharmacy/supplier-invoices'] as $url) {
            $this->actingAs($pharmacist)->get($url)->assertForbidden();
        }
    }

    private function supplier(): MedicineSupplier
    {
        return MedicineSupplier::query()->create(['code' => 'FOUR-01', 'name' => 'Fournisseur de contrôle']);
    }

    private function medicine(string $name = 'Paracétamol 500 mg'): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.str_pad((string) (CatalogItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'boîte',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        return Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Paracétamol',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    private function createOrder(MedicineSupplier $supplier, Medicine $medicine, int $quantity = 100, string $unitPrice = '100'): PurchaseOrder
    {
        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [
                ['medicine_uuid' => $medicine->uuid, 'quantity_ordered' => $quantity, 'unit_price' => $unitPrice],
            ],
        ]);

        return PurchaseOrder::query()->latest('id')->first();
    }

    /**
     * ADR-098 — a first purchase from a supplier whose catalogue has been
     * imported but whose products the clinic does not hold yet. Ordering a
     * catalogue line is what brings the product into the clinic catalogue —
     * without a selling price, which nobody has decided at this point.
     */
    public function test_ordering_a_supplier_catalogue_line_creates_the_clinic_medicine_without_a_selling_price(): void
    {
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', ['medicines.create', 'catalog.items.create', 'medicine_supplier_offers.create'])->pluck('id'),
            ['effect' => 'allow'],
        );
        $supplier = $this->supplier();
        $line = $this->catalogLine($supplier, 'ARB-014', 'Zinc sulfate 20 mg', '4500.00');

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [['supplier_catalog_item_uuid' => $line->uuid, 'quantity_ordered' => 10, 'unit_price' => '4500']],
        ])->assertRedirect();

        $medicine = Medicine::query()->sole();
        $this->assertSame('Zinc sulfate 20 mg', $medicine->catalogItem->name);
        $this->assertSame('ARB-014', $medicine->catalogItem->code);
        // The purchase price is recorded; the selling price is not invented.
        $this->assertNull($medicine->catalogItem->currentStandardTariff);
        $this->assertSame('4500.00', $medicine->currentOfferFor($supplier)->value('quoted_price'));
        // The catalogue line now points at it: ordering it twice never
        // creates the product twice.
        $this->assertSame($medicine->id, $line->fresh()->linked_medicine_id);
        $this->assertSame($medicine->id, PurchaseOrder::query()->latest('id')->first()->lines->sole()->medicine_id);
    }

    public function test_ordering_a_catalogue_line_is_refused_without_the_right_to_add_a_medicine(): void
    {
        $supplier = $this->supplier();
        $line = $this->catalogLine($supplier, 'ARB-015', 'Albendazole 400 mg', '2000.00');

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [['supplier_catalog_item_uuid' => $line->uuid, 'quantity_ordered' => 5, 'unit_price' => '2000']],
        ])->assertForbidden();

        $this->assertSame(0, Medicine::query()->count());
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    /**
     * ADR-098 — the clinic's own order screen offered the whole catalogue
     * whatever the supplier, which is what the portal's form stopped doing.
     * Nothing is listed until a supplier is named.
     */
    public function test_the_clinic_order_screen_lists_nothing_until_a_supplier_is_chosen(): void
    {
        $supplier = $this->supplier();
        $this->medicine('Paracétamol 500 mg');
        $sold = $this->medicine('Amoxicilline 500 mg');
        $sold->suppliers()->attach($supplier->id);

        $products = fn (?string $query) => collect(
            $this->actingAs($this->pharmacist)->get('/pharmacy/purchase-orders/create'.$query)
                ->assertOk()->viewData('page')['props']['medicines']
        )->pluck('name')->all();

        $this->assertSame([], $products(null));
        $this->assertSame(['Amoxicilline 500 mg'], $products("?supplier={$supplier->uuid}"));
    }

    /**
     * ADR-098 point 3 — "Paracétamol 500 mg" proposed by two suppliers is
     * one product, not two. Ordering it from both must add the second
     * purchase price beside the first, never split the stock in two.
     */
    public function test_the_same_product_from_two_suppliers_is_never_created_twice(): void
    {
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', ['medicines.create', 'catalog.items.create', 'medicine_supplier_offers.create'])->pluck('id'),
            ['effect' => 'allow'],
        );
        $first = $this->supplier();
        $second = MedicineSupplier::query()->create(['code' => 'FOUR-02', 'name' => 'Deuxième fournisseur']);

        $this->orderCatalogLine($first, $this->catalogLine($first, 'A-01', 'Paracétamol 500 mg', '100'));
        // Different reference, different spelling, same product.
        $this->orderCatalogLine($second, $this->catalogLine($second, 'B-77', 'PARACETAMOL  500 MG', '120'));

        $medicine = Medicine::query()->sole();
        $this->assertSame('Paracétamol 500 mg', $medicine->catalogItem->name);
        $this->assertSame('100.00', (string) $medicine->currentOfferFor($first)->value('quoted_price'));
        $this->assertSame('120.00', (string) $medicine->currentOfferFor($second)->value('quoted_price'));
        $this->assertSame(2, PurchaseOrder::query()->count());
    }

    /** A product taken out of service is not silently revived by an order. */
    public function test_ordering_a_deactivated_product_is_refused_instead_of_duplicating_it(): void
    {
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', ['medicines.create', 'catalog.items.create', 'medicine_supplier_offers.create'])->pluck('id'),
            ['effect' => 'allow'],
        );
        $supplier = $this->supplier();
        $this->medicine('Paracétamol 500 mg')->update(['active' => false]);

        $this->orderCatalogLine($supplier, $this->catalogLine($supplier, 'A-01', 'Paracétamol 500 mg', '100'))
            ->assertSessionHasErrors('lines');

        $this->assertSame(1, Medicine::query()->count());
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    /**
     * ADR-098 — cas réel : le catalogue Arbiochem liste le même article sous
     * deux références (GANT-010 et GANT-011). Les deux ramènent au même
     * médicament, et une commande n'accepte qu'une ligne par produit —
     * l'insertion heurtait la contrainte d'unicité avec une erreur SQL.
     */
    public function test_two_catalogue_references_of_one_product_are_refused_instead_of_colliding(): void
    {
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', ['medicines.create', 'catalog.items.create', 'medicine_supplier_offers.create'])->pluck('id'),
            ['effect' => 'allow'],
        );
        $supplier = $this->supplier();
        $first = $this->catalogLine($supplier, 'GANT-010', 'Polyglactin absorbable 1 (4 metric)', '133500');
        $second = $first->catalog->items()->create([
            'reference' => 'GANT-011',
            'medicine_label' => 'POLYGLACTIN  ABSORBABLE 1 (4 METRIC)',
            'supplier_price' => '133500',
            'row_number' => 2,
        ]);

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [
                ['supplier_catalog_item_uuid' => $first->uuid, 'quantity_ordered' => 1, 'unit_price' => '133500'],
                ['supplier_catalog_item_uuid' => $second->uuid, 'quantity_ordered' => 1, 'unit_price' => '133500'],
            ],
        ])->assertSessionHasErrors('lines');

        // Ni commande à moitié écrite, ni produit créé au passage.
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, Medicine::query()->count());
    }

    /** Le même produit désigné par le catalogue et par le catalogue clinique. */
    public function test_a_clinic_medicine_and_its_catalogue_line_cannot_both_be_ordered(): void
    {
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', ['medicines.create', 'catalog.items.create', 'medicine_supplier_offers.create'])->pluck('id'),
            ['effect' => 'allow'],
        );
        $supplier = $this->supplier();
        $medicine = $this->medicine('Alcool blanc 25Litre');
        $line = $this->catalogLine($supplier, 'ALCO-003', 'ALCOOL BLANC 25LITRE', '20000');

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [
                ['medicine_uuid' => $medicine->uuid, 'quantity_ordered' => 2, 'unit_price' => '20000'],
                ['supplier_catalog_item_uuid' => $line->uuid, 'quantity_ordered' => 1, 'unit_price' => '20000'],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    private function orderCatalogLine(MedicineSupplier $supplier, SupplierCatalogItem $line, int $quantity = 10): TestResponse
    {
        return $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [['supplier_catalog_item_uuid' => $line->uuid, 'quantity_ordered' => $quantity, 'unit_price' => '100']],
        ]);
    }

    private function catalogLine(MedicineSupplier $supplier, string $reference, string $label, ?string $price): SupplierCatalogItem
    {
        $catalog = $supplier->catalogs()->create([
            'original_name' => 'catalogue.xlsx',
            'path' => 'suppliers/'.$supplier->uuid.'/catalogue.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 2048,
            'kind' => SupplierCatalogFileKind::Excel,
            'active_key' => 'ACTIVE',
            'imported_at' => now(),
        ]);

        return $catalog->items()->create([
            'reference' => $reference,
            'medicine_label' => $label,
            'supplier_price' => $price,
            'row_number' => 1,
        ]);
    }

    public function test_the_direct_order_comparison_lists_catalogue_lines_not_yet_in_the_clinic(): void
    {
        $first = $this->supplier();
        $second = MedicineSupplier::query()->create(['code' => 'FOUR-02', 'name' => 'Second fournisseur']);
        $this->catalogLine($first, 'GANT-01', 'Gant stérile 7,5', '500');
        $this->catalogLine($second, 'GS-75', 'GANT STERILE 7.5', '450');

        $rows = app(SupplierOfferComparison::class)->forSite()['medicines'];

        // Same product name at two suppliers: one row, cheapest first.
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['in_clinic_catalog']);
        $this->assertSame('450.00', $rows[0]['best_price']);
        $this->assertSame(['Second fournisseur', 'Fournisseur de contrôle'], array_column($rows[0]['quotes'], 'supplier_name'));
        $this->assertNotNull($rows[0]['quotes'][0]['supplier_catalog_item_uuid']);
    }

    public function test_a_draft_order_can_be_submitted_then_becomes_ordered(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $order = $this->createOrder($supplier, $medicine);

        $this->assertSame('DRAFT', $order->status->value);
        $this->assertSame('100.00', $order->lines->sole()->unit_price);
        $this->assertSame('10000.00', $order->total_amount);

        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/submit")->assertRedirect();

        $this->assertSame('ORDERED', $order->fresh()->status->value);
        $this->assertNotNull($order->fresh()->ordered_at);
    }

    public function test_an_empty_order_cannot_be_submitted(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", ['lines' => []])
            ->assertSessionHasErrors('lines');

        // Bypass the FormRequest's own min:1 rule and hit the Action's own
        // guard directly, matching the "belt and suspenders" pattern used
        // throughout this codebase (e.g. StoreGoodsReceiptRequest::passedValidation()).
        $order = PurchaseOrder::query()->create([
            'order_number' => 'BC-TEST-EMPTY',
            'medicine_supplier_id' => $supplier->id,
            'status' => 'DRAFT',
            'total_amount' => 0,
            'currency' => 'MGA',
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/submit")
            ->assertSessionHasErrors('lines');
        $this->assertSame('DRAFT', $order->fresh()->status->value);
    }

    public function test_an_order_can_be_cancelled_while_draft_or_ordered_but_never_once_received(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $order = $this->createOrder($supplier, $medicine, 10, '50');

        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertRedirect();
        $this->assertSame('CANCELLED', $order->fresh()->status->value);
        $this->assertNotNull($order->fresh()->cancelled_at);

        // A cancelled order can never be resubmitted or re-cancelled.
        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/cancel", ['reason' => 'Nouvelle tentative'])
            ->assertSessionHasErrors('status');

        $order2 = $this->createOrder($supplier, $medicine, 10, '50');
        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order2->uuid}/submit");
        $this->receive($order2, [
            ['purchase_order_line_id' => $order2->lines->sole()->id, 'quantity_received' => 10, 'lot_number' => 'LOT-CANCEL-TEST', 'expires_at' => now()->addYear()->toDateString(), 'unit_purchase_price' => 50],
        ]);
        $this->assertSame('RECEIVED', $order2->fresh()->status->value);

        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order2->uuid}/cancel", ['reason' => 'Trop tard'])
            ->assertSessionHasErrors('status');
        $this->assertSame('RECEIVED', $order2->fresh()->status->value);
    }

    public function test_only_a_draft_order_can_be_updated(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $order = $this->createOrder($supplier, $medicine, 5, '20');
        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/submit");

        $this->actingAs($this->pharmacist)->patch("/pharmacy/purchase-orders/{$order->uuid}", [
            'lines' => [['medicine_uuid' => $medicine->uuid, 'quantity_ordered' => 999, 'unit_price' => '20']],
        ])->assertSessionHasErrors('status');

        $this->assertSame(5, $order->fresh()->lines->sole()->quantity_ordered);
    }

    private function receive(PurchaseOrder $order, array $lines): void
    {
        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
            'lines' => $lines,
        ])->assertRedirect();
    }

    public function test_partial_receiving_leaves_the_order_partially_received_then_fully_received_at_a_new_price(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $order = $this->createOrder($supplier, $medicine, 100, '100');
        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/submit");
        $line = $order->lines->sole();

        // Receive 80 of the 100 ordered, at 100 Ar/unit.
        $this->receive($order, [
            ['purchase_order_line_id' => $line->id, 'quantity_received' => 80, 'lot_number' => 'LOT-A', 'expires_at' => now()->addYear()->toDateString(), 'unit_purchase_price' => 100],
        ]);

        $order->refresh();
        $this->assertSame('PARTIALLY_RECEIVED', $order->status->value);
        $this->assertSame(80, $line->fresh()->quantity_received);

        $firstMovement = PharmacyStockMovement::query()->sole();
        $this->assertSame(80, $firstMovement->quantity_delta);
        $this->assertSame('100.00', $firstMovement->unit_purchase_price);
        $this->assertSame($supplier->id, $firstMovement->medicine_supplier_id);

        // The supplier now quotes a higher price; receive the remaining 20
        // at that new price. The old 100 Ar movement must remain untouched.
        $this->receive($order, [
            ['purchase_order_line_id' => $line->id, 'quantity_received' => 20, 'lot_number' => 'LOT-B', 'expires_at' => now()->addYear()->toDateString(), 'unit_purchase_price' => 120],
        ]);

        $order->refresh();
        $this->assertSame('RECEIVED', $order->status->value);
        $this->assertSame(100, $line->fresh()->quantity_received);

        $movements = PharmacyStockMovement::query()->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame('100.00', $movements[0]->unit_purchase_price);
        $this->assertSame(80, $movements[0]->quantity_delta);
        $this->assertSame('120.00', $movements[1]->unit_purchase_price);
        $this->assertSame(20, $movements[1]->quantity_delta);

        // The first movement is never rewritten by the second reception —
        // it is a genuinely separate, immutable row (ADR spec §14).
        $this->assertDatabaseHas('pharmacy_stock_movements', ['id' => $movements[0]->id, 'unit_purchase_price' => '100.00', 'quantity_delta' => 80]);
        $this->assertDatabaseHas('pharmacy_stock_movements', ['id' => $movements[1]->id, 'unit_purchase_price' => '120.00', 'quantity_delta' => 20]);

        $this->assertSame(2, $order->fresh()->receipts()->count());
    }

    public function test_receiving_more_than_what_remains_on_a_line_is_rejected(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $order = $this->createOrder($supplier, $medicine, 10, '100');
        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/submit");
        $line = $order->lines->sole();

        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
            'lines' => [
                ['purchase_order_line_id' => $line->id, 'quantity_received' => 11, 'lot_number' => 'LOT-OVER', 'expires_at' => now()->addYear()->toDateString()],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, $line->fresh()->quantity_received);
        $this->assertSame('ORDERED', $order->fresh()->status->value);
    }

    public function test_receiving_a_purchase_price_requires_the_stock_cost_record_permission(): void
    {
        (new PermissionSeeder)->run();
        $role = Role::query()->create(['code' => 'RECEIVER_NO_COST', 'name' => 'Réceptionnaire sans coût']);
        $role->permissions()->sync(
            Permission::query()
                ->whereIn('name', ['purchase_orders.create', 'purchase_orders.submit', 'purchase_orders.view', 'goods_receipts.create', 'stock.entry', 'stock.lots.create'])
                ->pluck('id'),
        );
        $limitedUser = User::factory()->create(['role_id' => $role->id]);

        $supplier = $this->supplier();
        $medicine = $this->medicine();

        $this->actingAs($limitedUser)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => [['medicine_uuid' => $medicine->uuid, 'quantity_ordered' => 5, 'unit_price' => '10']],
        ]);
        $order = PurchaseOrder::query()->sole();
        $this->actingAs($limitedUser)->post("/pharmacy/purchase-orders/{$order->uuid}/submit");
        $line = $order->lines->sole();

        $this->actingAs($limitedUser)->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
            'lines' => [
                ['purchase_order_line_id' => $line->id, 'quantity_received' => 5, 'lot_number' => 'LOT-NOPRICE', 'expires_at' => now()->addYear()->toDateString(), 'unit_purchase_price' => 10],
            ],
        ])->assertForbidden();

        $this->assertSame(0, $line->fresh()->quantity_received);

        // Without a price, the same limited user can receive normally.
        $this->actingAs($limitedUser)->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
            'lines' => [
                ['purchase_order_line_id' => $line->id, 'quantity_received' => 5, 'lot_number' => 'LOT-NOPRICE', 'expires_at' => now()->addYear()->toDateString()],
            ],
        ])->assertRedirect();

        $this->assertSame(5, $line->fresh()->quantity_received);

        // ADR-112 — the purchase price is never asked again: it is the order's.
        $this->assertSame('10.00', PharmacyStockMovement::query()->sole()->unit_purchase_price);
        $this->assertSame('10.00', $order->receipts()->sole()->lines()->sole()->unit_purchase_price);
    }

    public function test_the_pharmacy_role_no_longer_sees_or_records_purchase_prices(): void
    {
        $pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);

        $this->assertFalse($pharmacist->can('stock.cost.view'));
        $this->assertFalse($pharmacist->can('stock.cost.record'));
        $this->assertTrue($pharmacist->can('medicines.sale_price.update'));
    }
}
