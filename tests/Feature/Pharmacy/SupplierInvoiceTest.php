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
use App\Models\Role;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ADR-097 — spec §10: a supplier invoice is pure administrative bookkeeping
 * linked to a supplier (and optionally an order/reception). It never touches
 * stock — that impact was already fully recorded by the reception itself.
 */
class SupplierInvoiceTest extends TestCase
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

        // ADR-098 — granted by name, never through the PHARMACY role.
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', [
                'supplier_invoices.view', 'supplier_invoices.create',
                'supplier_invoices.delete', 'supplier_invoices.restore',
            ])->pluck('id'),
            ['effect' => 'allow'],
        );
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

    public function test_creating_an_invoice_requires_the_permission(): void
    {
        $role = Role::query()->where('code', 'LABORATORY')->firstOrFail();
        $unauthorized = User::factory()->create(['role_id' => $role->id]);
        $supplier = $this->supplier();
        $medicine = $this->medicine();

        $this->actingAs($unauthorized)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-001',
            'invoice_date' => now()->toDateString(),
            'lines' => [
                ['medicine_uuid' => $medicine->uuid, 'description' => 'Paracétamol 500mg', 'quantity' => 10, 'unit_price' => 100],
            ],
        ])->assertForbidden();

        $this->assertSame(0, SupplierInvoice::query()->count());
    }

    public function test_an_invoice_is_recorded_as_a_global_document_without_listing_its_products(): void
    {
        $supplier = $this->supplier();

        // What the supplier billed, and its document — the detail of what
        // physically arrived belongs to the reception, not here.
        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-GLOBAL-001',
            'invoice_date' => now()->toDateString(),
            'total_amount' => '250000',
        ])->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::query()->where('invoice_number', 'FAC-GLOBAL-001')->sole();
        $this->assertSame('250000.00', (string) $invoice->total_amount);
        $this->assertSame(0, $invoice->lines()->count());

        // Neither lines nor total: the invoice says nothing and is refused.
        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-GLOBAL-002',
            'invoice_date' => now()->toDateString(),
        ])->assertSessionHasErrors('total_amount');

        $this->assertSame(1, SupplierInvoice::query()->count());
    }

    public function test_recording_an_invoice_never_touches_stock(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-001',
            'invoice_date' => now()->toDateString(),
            'notes' => 'Facture de contrôle',
            'lines' => [
                ['medicine_uuid' => $medicine->uuid, 'description' => 'Paracétamol 500mg boîte de 10', 'quantity' => 10, 'unit_price' => 100],
                ['medicine_uuid' => $medicine->uuid, 'description' => 'Frais de livraison', 'quantity' => 1, 'unit_price' => 50],
            ],
        ])->assertRedirect();

        $invoice = SupplierInvoice::query()->sole();
        $this->assertSame('FAC-001', $invoice->invoice_number);
        $this->assertSame($supplier->id, $invoice->medicine_supplier_id);
        $this->assertSame('1050.00', $invoice->total_amount);
        $this->assertCount(2, $invoice->lines);

        // A supplier invoice is pure bookkeeping: no stock movement, no lot,
        // no medicine quantity is ever created or changed by recording one.
        $this->assertSame(0, PharmacyStockMovement::query()->count());
        $this->assertSame(0, $medicine->lots()->count());
    }

    public function test_an_invoice_can_carry_an_attachment_stored_privately(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-002',
            'invoice_date' => now()->toDateString(),
            'attachment' => UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf'),
            'lines' => [
                ['medicine_uuid' => $medicine->uuid, 'description' => 'Paracétamol', 'quantity' => 1, 'unit_price' => 10],
            ],
        ])->assertRedirect();

        $invoice = SupplierInvoice::query()->sole();
        $this->assertTrue($invoice->hasAttachment());

        $this->actingAs($this->pharmacist)->get("/pharmacy/supplier-invoices/{$invoice->uuid}/attachment")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $role = Role::query()->where('code', 'LABORATORY')->firstOrFail();
        $unauthorized = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($unauthorized)->get("/pharmacy/supplier-invoices/{$invoice->uuid}/attachment")->assertForbidden();
    }

    public function test_an_invoice_can_be_archived_and_restored(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-003',
            'invoice_date' => now()->toDateString(),
            'lines' => [
                ['medicine_uuid' => $medicine->uuid, 'description' => 'Paracétamol', 'quantity' => 1, 'unit_price' => 10],
            ],
        ]);
        $invoice = SupplierInvoice::query()->sole();

        $this->actingAs($this->pharmacist)->delete("/pharmacy/supplier-invoices/{$invoice->uuid}", ['reason' => 'Erreur de saisie'])
            ->assertRedirect();
        $this->assertSoftDeleted('supplier_invoices', ['id' => $invoice->id]);

        $this->actingAs($this->pharmacist)->post("/pharmacy/supplier-invoices/{$invoice->uuid}/restore")
            ->assertRedirect();
        $this->assertNull($invoice->fresh()->deleted_at);
    }

    public function test_two_invoices_cannot_share_the_same_number_for_the_same_supplier(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $lines = [['medicine_uuid' => $medicine->uuid, 'description' => 'Paracétamol', 'quantity' => 1, 'unit_price' => 10]];

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-DUP',
            'invoice_date' => now()->toDateString(),
            'lines' => $lines,
        ])->assertRedirect();

        // Le doublon remontait en violation de contrainte : une 500 pour le
        // pharmacien, et une pile d'appels dans le log. C'est un message sous
        // le champ (ADR-163).
        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-DUP',
            'invoice_date' => now()->toDateString(),
            'lines' => $lines,
        ])->assertSessionHasErrors('invoice_number');

        $this->assertSame(1, SupplierInvoice::query()->where('invoice_number', 'FAC-DUP')->count());

        // Une facture archivée garde son numéro : il n'est pas libre, il est rangé.
        SupplierInvoice::query()->where('invoice_number', 'FAC-DUP')->sole()->delete();

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/invoices", [
            'invoice_number' => 'FAC-DUP',
            'invoice_date' => now()->toDateString(),
            'lines' => $lines,
        ])->assertSessionHasErrors('invoice_number');
    }
}
