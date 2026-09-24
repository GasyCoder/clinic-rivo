<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\PharmacyStockMovementType;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockOverviewService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-098 — a delivery of several medicines recorded at once, the counting
 * sheet, « Médicaments & stock » as one page, and « Achats » tabs.
 */
class StockWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'M', 'rivo.site.name' => 'Mampikony']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->pharmacist = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
    }

    private function medicine(string $name): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.str_pad((string) (CatalogItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        return Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $name,
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    private function lot(Medicine $medicine, int $quantity, string $number): MedicineLot
    {
        return MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => $number,
            'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => $quantity,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    /**
     * ADR-176 — un produit entré au catalogue par une commande (ADR-098) n'est
     * pas « en rupture » : on ne l'a jamais eu. Il a son propre état, et il
     * sort de la liste courante jusqu'à sa première entrée en stock.
     */
    public function test_a_medicine_never_received_is_not_counted_as_a_shortage(): void
    {
        $ordered = $this->medicine('Compresses stériles');
        $held = $this->medicine('Paracétamol');
        $this->lot($held, 20, 'PARA-01');
        $emptied = $this->medicine('Amoxicilline');
        $this->lot($emptied, 0, 'AMOX-01');

        $overview = app(MedicineStockOverviewService::class)->overview();
        $status = collect($overview['medicines'])->pluck('status', 'name');

        $this->assertSame('NEVER_RECEIVED', $status['Compresses stériles']);
        $this->assertSame('AVAILABLE', $status['Paracétamol']);
        // Un lot vidé, lui, EST une rupture : la clinique l'a tenu.
        $this->assertSame('OUT_OF_STOCK', $status['Amoxicilline']);

        $this->assertSame(1, $overview['summary']['never_received']);
        $this->assertSame(1, $overview['summary']['out_of_stock']);
        $this->assertSame(2, $overview['summary']['stocked_medicines']);

        // La page les sert toutes ; c'est l'écran qui range les jamais reçus
        // dans leur onglet, comptés à part.
        $this->actingAs($this->pharmacist)->get('/pharmacy/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stock.summary.never_received', 1)
                ->where('stock.summary.out_of_stock', 1));
    }

    public function test_the_medicine_stock_page_lists_its_movements(): void
    {
        $medicine = $this->medicine('Paracétamol');
        $lot = $this->lot($medicine, 0, 'LOT-MV');
        PharmacyStockMovement::query()->create([
            'medicine_lot_id' => $lot->id,
            'type' => PharmacyStockMovementType::Entry,
            'quantity_delta' => 12,
            'balance_after' => 12,
            'source_key' => 'test-movement-1',
            'reason' => 'Livraison',
            'occurred_at' => now(),
            'performed_by' => $this->pharmacist->id,
        ]);

        $response = $this->actingAs($this->pharmacist)->get("/pharmacy/stock/{$medicine->uuid}")->assertOk();
        $movements = $response->viewData('page')['props']['movements'];

        $this->assertCount(1, $movements);
        $this->assertSame(12, $movements[0]['quantity_delta']);
        $this->assertSame('LOT-MV', $movements[0]['lot_number']);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions, string $code): User
    {
        $role = Role::query()->create(['code' => $code, 'name' => $code]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    // ADR-182 — une livraison entrée d'un coup ou pas du tout se vérifie
    // désormais sur les lignes réceptionnées : StockEntryFromReceptionTest.

    public function test_the_counting_sheet_adjusts_only_the_lots_whose_count_differs(): void
    {
        $paracetamol = $this->medicine('Paracétamol');
        $counted = $this->lot($paracetamol, 15, 'PARA-01');
        $matching = $this->lot($paracetamol, 8, 'PARA-02');

        $this->actingAs($this->pharmacist)->get('/pharmacy/stock/inventory')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Pharmacy/Stock/Inventory')->has('lots', 2));

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/inventory', [
                'reason' => 'Inventaire de septembre',
                'counts' => [
                    ['lot_uuid' => $counted->uuid, 'counted_quantity' => 12],
                    ['lot_uuid' => $matching->uuid, 'counted_quantity' => 8],
                ],
            ])
            ->assertRedirect('/pharmacy/stock')
            ->assertSessionHas('status', 'Inventaire validé : 1 lot(s) corrigé(s), 1 conforme(s).');

        $this->assertSame(12, $counted->fresh()->quantity_on_hand);
        $this->assertSame(8, $matching->fresh()->quantity_on_hand);
        $this->assertSame(1, PharmacyStockMovement::query()->where('type', PharmacyStockMovementType::Adjustment->value)->count());

        $laboratory = User::factory()->create(['role_id' => Role::query()->where('code', 'LABORATORY')->value('id')]);
        $this->actingAs($laboratory)->get('/pharmacy/stock/inventory')->assertForbidden();
    }

    public function test_medicines_and_stock_are_one_page_even_for_a_catalog_only_account(): void
    {
        $paracetamol = $this->medicine('Paracétamol');
        $this->lot($paracetamol, 20, 'PARA-01');

        $this->actingAs($this->pharmacist)->get('/pharmacy/medicines')->assertRedirect('/pharmacy/stock');
        $this->actingAs($this->pharmacist)->get('/pharmacy/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/Stock/Index')
                ->where('stock.medicines.0.available_quantity', 20)
                ->has('categories'));

        $catalogOnly = $this->userWith(['pharmacy.view', 'medicines.view'], 'CATALOG_ONLY');
        $this->actingAs($catalogOnly)->get('/pharmacy/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stock.medicines.0.name', 'Paracétamol')
                ->where('stock.medicines.0.available_quantity', null)
                ->where('stock.medicines.0.lots', []));
    }

    public function test_purchases_open_on_an_allowed_tab_and_list_what_awaits_reception(): void
    {
        $invoicesOnly = $this->userWith(['pharmacy.view', 'supplier_invoices.view'], 'INVOICES_ONLY');
        $this->actingAs($invoicesOnly)->get('/pharmacy/purchases')->assertRedirect('/pharmacy/supplier-invoices');

        // ADR-176 — la Pharmacie réceptionne sa propre livraison : son socle
        // ouvre donc les Achats, sur l'onglet des commandes. Un compte qui
        // n'a aucun des trois droits d'achat reste refusé.
        $this->actingAs($this->pharmacist)->get('/pharmacy/purchases')->assertRedirect('/pharmacy/purchase-orders');

        $stockOnly = $this->userWith(['pharmacy.view', 'stock.view'], 'STOCK_ONLY');
        $this->actingAs($stockOnly)->get('/pharmacy/purchases')->assertForbidden();

        $buyer = $this->userWith(['pharmacy.view', 'purchase_orders.view'], 'BUYER');
        $supplier = MedicineSupplier::query()->create(['code' => 'DISTRIB', 'name' => 'Distrib']);
        foreach (['DRAFT' => 'BC-1', 'ORDERED' => 'BC-2', 'PARTIALLY_RECEIVED' => 'BC-3'] as $status => $number) {
            PurchaseOrder::query()->create([
                'order_number' => $number,
                'medicine_supplier_id' => $supplier->id,
                'status' => $status,
                'created_by' => $this->pharmacist->id,
                'updated_by' => $this->pharmacist->id,
            ]);
        }

        $this->actingAs($buyer)->get('/pharmacy/purchases')->assertRedirect('/pharmacy/purchase-orders');
        $this->actingAs($buyer)->get('/pharmacy/purchase-orders?status=TO_RECEIVE')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 2)
                ->where('purchases.counts.orders', 3)
                ->where('purchases.counts.to_receive', 2)
                ->where('purchases.counts.receipts', null)
                ->where('purchases.can.invoices', false));
    }
}
