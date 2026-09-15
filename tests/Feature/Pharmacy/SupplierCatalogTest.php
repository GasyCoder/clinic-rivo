<?php

namespace Tests\Feature\Pharmacy;

use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\User;
use App\Services\Pharmacy\SupplierCatalogImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ADR-097 — spec §2/§3: one supplier can hold many catalog files (Excel or
 * PDF), at most one active at a time, and only an Excel catalog can be
 * parsed into raw, unlinked rows.
 */
class SupplierCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(array $permissions): User
    {
        // Migrations already insert a handful of permission rows directly
        // during `up()` (see e.g. register_cash_supervision_permissions),
        // so an existence check is never a reliable "already fully seeded"
        // signal — always (re)run the full catalog; updateOrCreate makes
        // repeated calls within the same test safe.
        (new PermissionSeeder)->run();

        $role = Role::query()->create([
            'code' => 'PROCUREMENT_TEST_'.Role::query()->count(),
            'name' => 'Approvisionnement (test)',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function supplier(): MedicineSupplier
    {
        return MedicineSupplier::query()->create([
            'code' => 'FOUR-01',
            'name' => 'Fournisseur de contrôle',
        ]);
    }

    private function excelFile(array $rows, string $name = 'catalogue.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([SupplierCatalogImportService::HEADERS], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'supplier-catalog-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_uploading_a_catalog_requires_the_permission_and_rejects_unsupported_files(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['medicine_suppliers.view']);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => $this->excelFile([['REF-1', 'Paracétamol', 'boîte 10', 100]]),
        ])->assertForbidden();

        $user = $this->setupUser(['medicine_suppliers.view', 'supplier_catalogs.create']);
        $path = tempnam(sys_get_temp_dir(), 'not-a-catalog-').'.txt';
        file_put_contents($path, 'plain text');

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
        ])->assertSessionHasErrors('file');

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => $this->excelFile([['REF-1', 'Paracétamol', 'boîte 10', 100]]),
        ])->assertRedirect();

        $this->assertDatabaseHas('supplier_catalogs', [
            'medicine_supplier_id' => $supplier->id,
            'kind' => 'EXCEL',
            'active_key' => null,
        ]);
    }

    public function test_only_one_catalog_can_be_active_per_supplier_at_a_time(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.create', 'supplier_catalogs.update', 'supplier_catalogs.delete']);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", ['file' => $this->excelFile([['A', 'Amoxicilline', 'boîte', 100]], 'un.xlsx')]);
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", ['file' => $this->excelFile([['B', 'Ibuprofène', 'boîte', 150]], 'deux.xlsx')]);

        [$first, $second] = SupplierCatalog::query()->orderBy('id')->get();

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$first->uuid}/activate")->assertRedirect();
        $this->assertTrue($first->fresh()->isActive());

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$second->uuid}/activate")->assertRedirect();
        $this->assertFalse($first->fresh()->isActive());
        $this->assertTrue($second->fresh()->isActive());

        // Archiving the active catalog must clear active_key first so the
        // unique(medicine_supplier_id, active_key) constraint never blocks
        // activating a different catalog afterward.
        $this->actingAs($user)->delete("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$second->uuid}", ['reason' => 'Catalogue obsolète'])->assertRedirect();
        $this->assertSoftDeleted('supplier_catalogs', ['id' => $second->id]);
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$first->uuid}/activate")->assertRedirect();
        $this->assertTrue($first->fresh()->isActive());
    }

    public function test_excel_import_is_atomic_and_never_touches_the_clinic_catalog(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.create']);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => $this->excelFile([
                ['REF-1', 'Paracétamol 500mg', 'boîte 10', 100],
                ['REF-2', 'Amoxicilline 500mg', 'boîte 20', 250],
            ]),
        ]);
        $catalog = SupplierCatalog::query()->sole();

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(2, $catalog->fresh()->items()->count());
        $this->assertNotNull($catalog->fresh()->imported_at);
        // The parsed rows are not the clinic catalog: no Medicine/CatalogItem
        // is created by importing a supplier's Excel file (spec §3).
        $this->assertDatabaseCount('medicines', 0);
        $this->assertDatabaseCount('catalog_items', 0);

        // A second catalog with one invalid row (negative price) must abort
        // entirely — no partial import, matching every other importer in
        // this codebase.
        $badCatalog = SupplierCatalog::query()->create([
            'medicine_supplier_id' => $supplier->id,
            'original_name' => 'mauvais.xlsx',
            'path' => 'unused/for/this/assertion.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 10,
            'kind' => 'EXCEL',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        Storage::disk('local')->put(
            $badCatalog->path,
            file_get_contents($this->excelFile([
                ['REF-3', 'Valide', 'unité', 50],
                ['REF-4', 'Invalide', 'unité', -10],
            ])->getRealPath()),
        );

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$badCatalog->uuid}/import")
            ->assertSessionHasErrors('file');
        $this->assertSame(0, $badCatalog->fresh()->items()->count());
    }

    public function test_a_pdf_catalog_cannot_be_parsed_for_structured_rows(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.create']);
        $catalog = SupplierCatalog::query()->create([
            'medicine_supplier_id' => $supplier->id,
            'original_name' => 'catalogue.pdf',
            'path' => 'unused.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'kind' => 'PDF',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")
            ->assertSessionHasErrors('file');
    }
}
