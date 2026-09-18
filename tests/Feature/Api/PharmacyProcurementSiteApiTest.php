<?php

namespace Tests\Feature\Api;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\PurchaseOrderStatus;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-098 — orders and supplier invoices written from the central portal:
 * the remote Super Admin goes through the clinic's own Actions, is audited
 * by identity, and never becomes a local author.
 */
class PharmacyProcurementSiteApiTest extends TestCase
{
    use RefreshDatabase;

    private string $actorUuid;

    private User $pharmacist;

    private MedicineSupplier $supplier;

    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        Storage::fake('local');
        $this->actorUuid = (string) Str::uuid();
        $this->pharmacist = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $this->supplier = MedicineSupplier::query()->create(['code' => 'CENTRALE', 'name' => 'Centrale']);

        $item = CatalogItem::query()->create([
            'code' => 'PH-0001',
            'name' => 'Amoxicilline 500 mg',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'gélule',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
        $this->medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Amoxicilline',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $this->actorUuid,
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }

    private function base(): string
    {
        return "/api/v1/super-admin/pharmacy/suppliers/{$this->supplier->uuid}";
    }

    public function test_an_order_is_created_submitted_and_cancelled_from_the_portal(): void
    {
        $payload = ['notes' => 'Réassort', 'lines' => [
            ['medicine_uuid' => $this->medicine->uuid, 'quantity_ordered' => 10, 'unit_price' => '250'],
        ]];

        $this->withHeaders($this->headers(['purchase_orders.view']))
            ->postJson("{$this->base()}/orders", $payload)
            ->assertForbidden();

        $this->withHeaders($this->headers(['purchase_orders.create']))
            ->getJson("{$this->base()}/order-form")
            ->assertOk()
            ->assertJsonPath('data.medicines.0.uuid', $this->medicine->uuid);

        $uuid = $this->withHeaders($this->headers(['purchase_orders.create']))
            ->postJson("{$this->base()}/orders", $payload)
            ->assertCreated()
            ->json('data.uuid');

        $order = PurchaseOrder::query()->where('uuid', $uuid)->sole();
        $this->assertNull($order->created_by);
        $this->assertSame($this->actorUuid, $order->external_created_by_uuid);
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame('2500.00', (string) $order->total_amount);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => PurchaseOrder::class,
            'entity_id' => $order->id,
            'external_actor_uuid' => $this->actorUuid,
        ]);

        $this->withHeaders($this->headers(['purchase_orders.view']))
            ->getJson("{$this->base()}/orders/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.order.lines.0.quantity_ordered', 10)
            ->assertJsonPath('data.order.created_by_name', 'Direction centrale');

        $this->withHeaders($this->headers(['purchase_orders.submit']))
            ->postJson("{$this->base()}/orders/{$uuid}/submit")
            ->assertOk();
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->fresh()->status);

        $this->withHeaders($this->headers(['purchase_orders.cancel']))
            ->postJson("{$this->base()}/orders/{$uuid}/cancel", ['reason' => 'Quantité erronée'])
            ->assertOk();
        $cancelled = $order->fresh();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->cancelled_by);
        $this->assertSame($this->actorUuid, $cancelled->external_cancelled_by_uuid);
    }

    /**
     * ADR-098 — the owner's own case, end to end: a supplier whose catalogue
     * is imported but whose products the clinic does not hold. The catalogue
     * line must be orderable from the portal, and the order must really
     * exist in the site's database afterwards.
     */
    public function test_a_catalogue_line_is_ordered_from_the_portal_and_enters_the_clinic_catalogue(): void
    {
        $catalog = $this->supplier->catalogs()->create([
            'original_name' => 'arbiochem.xlsx',
            'path' => 'suppliers/'.$this->supplier->uuid.'/arbiochem.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 2048,
            'kind' => \App\Enums\SupplierCatalogFileKind::Excel,
            'active_key' => 'ACTIVE',
            'imported_at' => now(),
        ]);
        $line = $catalog->items()->create([
            'reference' => 'ARB-014',
            'medicine_label' => 'Zinc sulfate 20 mg',
            'presentation' => 'boîte de 30',
            'supplier_price' => '4500.00',
            'row_number' => 1,
        ]);

        // The form offers it although no clinic medicine exists for it.
        $this->withHeaders($this->headers(['purchase_orders.create']))
            ->getJson("{$this->base()}/order-form")
            ->assertOk()
            ->assertJsonPath('data.medicines.1.catalog_item_uuid', $line->uuid)
            ->assertJsonPath('data.medicines.1.in_clinic_catalog', false);

        $permissions = ['purchase_orders.create', 'medicines.create', 'catalog.items.create', 'medicine_supplier_offers.create'];

        $uuid = $this->withHeaders($this->headers($permissions))
            ->postJson("{$this->base()}/orders", ['lines' => [
                ['supplier_catalog_item_uuid' => $line->uuid, 'quantity_ordered' => 12, 'unit_price' => '4500'],
            ]])
            ->assertCreated()
            ->json('data.uuid');

        $order = PurchaseOrder::query()->where('uuid', $uuid)->sole();
        $created = Medicine::query()->whereRelation('catalogItem', 'code', 'ARB-014')->sole();

        $this->assertSame(12, $order->lines->sole()->quantity_ordered);
        $this->assertSame($created->id, $order->lines->sole()->medicine_id);
        $this->assertSame('54000.00', (string) $order->total_amount);
        // No local author, and the portal identity is kept beside it.
        $this->assertNull($created->created_by);
        $this->assertSame($this->actorUuid, $created->external_created_by_uuid);
        // Purchase price recorded, selling price never invented (ADR-024).
        $this->assertSame('4500.00', (string) $created->currentOfferFor($this->supplier)->value('quoted_price'));
        $this->assertNull($created->catalogItem->currentStandardTariff);
        $this->assertSame($created->id, $line->fresh()->linked_medicine_id);
    }

    public function test_ordering_a_catalogue_line_is_refused_without_the_right_to_add_a_medicine(): void
    {
        $catalog = $this->supplier->catalogs()->create([
            'original_name' => 'arbiochem.xlsx',
            'path' => 'suppliers/'.$this->supplier->uuid.'/arbiochem.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 2048,
            'kind' => \App\Enums\SupplierCatalogFileKind::Excel,
            'active_key' => 'ACTIVE',
            'imported_at' => now(),
        ]);
        $line = $catalog->items()->create(['reference' => 'ARB-015', 'medicine_label' => 'Albendazole 400 mg', 'supplier_price' => '2000.00', 'row_number' => 1]);

        $this->withHeaders($this->headers(['purchase_orders.create']))
            ->postJson("{$this->base()}/orders", ['lines' => [
                ['supplier_catalog_item_uuid' => $line->uuid, 'quantity_ordered' => 5, 'unit_price' => '2000'],
            ]])
            ->assertForbidden();

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(1, Medicine::query()->count());
    }

    public function test_an_invoice_is_recorded_with_its_document_then_archived_and_restored(): void
    {
        $response = $this->withHeaders($this->headers(['supplier_invoices.create']))
            ->post("{$this->base()}/invoices", [
                'invoice_number' => 'FAC-001',
                'invoice_date' => now()->toDateString(),
                // A multipart body carries the lines as JSON.
                'lines' => json_encode([[
                    'medicine_uuid' => $this->medicine->uuid,
                    'description' => 'Amoxicilline 500 mg',
                    'quantity' => 2,
                    'unit_price' => '300',
                ]]),
                'attachment' => UploadedFile::fake()->create('facture.pdf', 40, 'application/pdf'),
            ])
            ->assertCreated();

        $invoice = SupplierInvoice::query()->where('uuid', $response->json('data.uuid'))->sole();
        $this->assertNull($invoice->created_by);
        $this->assertSame($this->actorUuid, $invoice->external_created_by_uuid);
        $this->assertSame('600.00', (string) $invoice->total_amount);
        Storage::disk('local')->assertExists($invoice->attachment_path);

        $this->withHeaders($this->headers(['supplier_invoices.view']))
            ->getJson("{$this->base()}/invoices/{$invoice->uuid}")
            ->assertOk()
            ->assertJsonPath('data.invoice.has_attachment', true)
            ->assertJsonPath('data.invoice.lines.0.quantity', 2);

        $this->withHeaders($this->headers(['supplier_invoices.delete']))
            ->deleteJson("{$this->base()}/invoices/{$invoice->uuid}", ['reason' => 'Doublon de saisie'])
            ->assertOk();
        $this->assertSoftDeleted('supplier_invoices', ['id' => $invoice->id]);

        $this->withHeaders($this->headers(['supplier_invoices.restore']))
            ->postJson("{$this->base()}/invoices/{$invoice->uuid}/restore")
            ->assertOk();
        $restored = $invoice->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertSame($this->actorUuid, $restored->external_updated_by_uuid);
    }

    public function test_an_invoice_cannot_point_to_another_suppliers_order(): void
    {
        $other = MedicineSupplier::query()->create(['code' => 'AUTRE', 'name' => 'Autre fournisseur']);
        $foreignOrder = PurchaseOrder::query()->create([
            'order_number' => 'BC-000077',
            'medicine_supplier_id' => $other->id,
            'status' => 'ORDERED',
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        $this->withHeaders($this->headers(['supplier_invoices.create']))
            ->postJson("{$this->base()}/invoices", [
                'invoice_number' => 'FAC-002',
                'invoice_date' => now()->toDateString(),
                'purchase_order_uuid' => $foreignOrder->uuid,
                'lines' => [[
                    'medicine_uuid' => $this->medicine->uuid,
                    'description' => 'Amoxicilline 500 mg',
                    'quantity' => 1,
                    'unit_price' => '300',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purchase_order_uuid');

        $this->assertSame(0, SupplierInvoice::query()->count());
    }
}
