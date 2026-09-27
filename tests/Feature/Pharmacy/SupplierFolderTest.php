<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\MedicineForm;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Pharmacy\SupplierCatalogImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ADR-098 — the supplier "folder" space: folders are only shown to accounts
 * granted by name, a catalog is checked before anything is written, and a
 * clinic medicine created from a supplier line is linked in the same step.
 */
class SupplierFolderTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(array $permissions): User
    {
        // Migrations pre-insert a few permission rows, so the full catalog
        // must always be (re)seeded; updateOrCreate keeps this idempotent.
        (new PermissionSeeder)->run();

        $role = Role::query()->create([
            'code' => 'SUPPLIER_FOLDER_TEST_'.Role::query()->count(),
            'name' => 'Dossier fournisseur (test)',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function supplier(): MedicineSupplier
    {
        return MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis']);
    }

    /** @param array<int, string> $headers */
    private function excelFile(array $rows, array $headers = SupplierCatalogImportService::HEADERS): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'supplier-folder-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'catalogue.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function uploadCatalog(User $user, MedicineSupplier $supplier, UploadedFile $file): SupplierCatalog
    {
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", ['file' => $file])->assertRedirect();

        return SupplierCatalog::query()->latest('id')->firstOrFail();
    }

    public function test_supplier_folders_are_shown_only_to_accounts_granted_the_permission(): void
    {
        $supplier = $this->supplier();

        $withoutAccess = $this->setupUser(['pharmacy.view']);
        $this->actingAs($withoutAccess)->get('/pharmacy/suppliers')->assertForbidden();
        $this->actingAs($withoutAccess)->get("/pharmacy/suppliers/{$supplier->uuid}")->assertForbidden();

        $viewer = $this->setupUser(['medicine_suppliers.view']);
        $this->actingAs($viewer)->get('/pharmacy/suppliers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/Suppliers/Index')
                ->has('suppliers', 1)
                ->where('suppliers.0.name', 'Pharmadis')
                ->where('suppliers.0.catalogs_count', 0)
                ->where('can.create', false));
    }

    public function test_viewing_a_supplier_opens_its_sub_folders_read_only(): void
    {
        $supplier = $this->supplier();

        $readOnly = $this->setupUser(['medicine_suppliers.view', 'supplier_catalogs.view']);
        $this->actingAs($readOnly)->get("/pharmacy/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/Suppliers/Show')
                ->where('can.view_catalogs', true)
                // ADR-098 — « Voir les fournisseurs » opens the whole folder read-only.
                ->where('can.view_orders', true)
                ->where('can.view_invoices', true)
                ->where('can.view_offers', true)
                ->where('can.create_order', false));

        $this->actingAs($readOnly)->get("/pharmacy/suppliers/{$supplier->uuid}/catalogs")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.create', false)->where('can.delete', false));

        $this->actingAs($readOnly)->get("/pharmacy/suppliers/{$supplier->uuid}/products")->assertOk();
        $this->actingAs($readOnly)->get('/pharmacy/purchase-orders/create')->assertForbidden();
    }

    public function test_the_import_preview_reports_row_errors_without_writing_anything(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.view', 'supplier_catalogs.create']);
        $catalog = $this->uploadCatalog($user, $supplier, $this->excelFile([
            ['REF-1', 'Paracétamol 500 mg', 'boîte de 10', 100],
            ['REF-2', 'Amoxicilline 500 mg', 'boîte de 12', -5],
        ]));

        $this->actingAs($user)->get("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/Suppliers/ImportPreview')
                ->where('preview.error', null)
                ->where('preview.valid_count', 1)
                ->where('preview.invalid_count', 1)
                ->has('preview.rows', 2)
                ->where('preview.rows.0.errors', [])
                ->where('preview.rows.1.row_number', 3)
                ->where('preview.rows.1.errors', fn ($errors) => count($errors) > 0));

        $this->assertSame(0, SupplierCatalogItem::query()->count());
        $this->assertNull($catalog->fresh()->imported_at);
    }

    public function test_the_import_preview_names_the_missing_columns(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.view', 'supplier_catalogs.create']);
        $catalog = $this->uploadCatalog($user, $supplier, $this->excelFile(
            [['REF-1', 'Paracétamol 500 mg', 'boîte de 10']],
            ['reference', 'medicament', 'presentation'],
        ));

        $this->actingAs($user)->get("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preview.missing_headers', ['Prix fournisseur'])
                ->has('preview.rows', 0));
    }

    public function test_a_medicine_added_from_a_supplier_line_is_linked_to_that_supplier_price_in_one_step(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser([
            'supplier_catalogs.view', 'supplier_catalogs.create',
            'medicine_supplier_offers.create',
            'medicines.view', 'medicines.create', 'catalog.items.create', 'catalog.tariffs.create',
        ]);
        $catalog = $this->uploadCatalog($user, $supplier, $this->excelFile([
            ['PDS-042', 'Ibuprofène 400 mg', 'boîte de 20', 350],
        ]));
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")->assertRedirect();
        $item = SupplierCatalogItem::query()->sole();

        $this->actingAs($user)->get("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/items")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.add_to_catalog', true)->has('items', 1));

        $this->actingAs($user)->post('/pharmacy/setup/medicines', $this->medicinePayload($item))
            ->assertRedirect("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/items");

        $medicine = Medicine::query()->sole();
        $this->assertSame($medicine->id, $item->fresh()->linked_medicine_id);

        $offer = MedicineSupplierOffer::query()->sole();
        $this->assertTrue($offer->isCurrent());
        $this->assertSame($supplier->id, $offer->medicine_supplier_id);
        $this->assertSame('350.00', $offer->quoted_price);

        // The plain supplier link is kept in step with the offer.
        $this->assertTrue($medicine->suppliers()->whereKey($supplier->id)->exists());
    }

    public function test_a_refused_supplier_link_leaves_no_half_added_medicine(): void
    {
        $supplier = $this->supplier();
        $importer = $this->setupUser(['supplier_catalogs.view', 'supplier_catalogs.create']);
        $catalog = $this->uploadCatalog($importer, $supplier, $this->excelFile([
            ['PDS-042', 'Ibuprofène 400 mg', 'boîte de 20', 350],
        ]));
        $this->actingAs($importer)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")->assertRedirect();
        $item = SupplierCatalogItem::query()->sole();

        // May create a clinic medicine, but not record a supplier price.
        $creator = $this->setupUser(['medicines.view', 'medicines.create', 'catalog.items.create', 'catalog.tariffs.create']);

        $this->actingAs($creator)->post('/pharmacy/setup/medicines', $this->medicinePayload($item))->assertForbidden();

        $this->assertSame(0, Medicine::query()->count());
        $this->assertNull($item->fresh()->linked_medicine_id);
        $this->assertSame(0, MedicineSupplierOffer::query()->count());
    }

    /** @return array<string, mixed> */
    private function medicinePayload(SupplierCatalogItem $item): array
    {
        return [
            'supplier_catalog_item_uuid' => $item->uuid,
            'code' => 'IBU-400',
            'name' => 'Ibuprofène 400 mg',
            'generic_name' => 'Ibuprofène',
            'form' => MedicineForm::Tablet->value,
            'strength' => '400 mg',
            'unit' => 'boîte',
            'minimum_stock' => 5,
            'prescription_required' => false,
            'sale_price' => 500,
            'tariff_reason' => 'Prix de vente initial',
        ];
    }
}
