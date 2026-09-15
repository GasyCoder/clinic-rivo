<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }
}
