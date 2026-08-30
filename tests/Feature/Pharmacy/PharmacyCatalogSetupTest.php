<?php

namespace Tests\Feature\Pharmacy;

use App\Exceptions\ForceDeleteForbiddenException;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineLot;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Pharmacy\MedicineCatalogImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PharmacyCatalogSetupTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(array $permissions): User
    {
        (new PermissionSeeder)->run();
        $role = Role::query()->create(['code' => 'PHARMACY_SETUP', 'name' => 'Paramétrage Pharmacie']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_authorized_setup_creates_category_supplier_product_tariff_and_audit(): void
    {
        $user = $this->setupUser([
            'medicine_categories.create', 'medicine_suppliers.create', 'medicines.create',
            'catalog.items.create', 'catalog.tariffs.create',
        ]);

        $this->actingAs($user)->post('/pharmacy/setup/categories', [
            'code' => 'ANALG',
            'name' => 'Catégorie de contrôle',
        ])->assertRedirect();
        $this->actingAs($user)->post('/pharmacy/setup/suppliers', [
            'code' => 'FOUR-01',
            'name' => 'Fournisseur de contrôle',
            'phone' => '0000000000',
        ])->assertRedirect();
        $category = MedicineCategory::query()->sole();
        $supplier = MedicineSupplier::query()->sole();

        $this->actingAs($user)->post('/pharmacy/setup/medicines', [
            'code' => 'MED-CONTROLE-01',
            'name' => 'Produit de contrôle',
            'generic_name' => 'DCI de contrôle',
            'form' => 'TABLET',
            'strength' => '100 mg',
            'unit' => 'comprimé',
            'manufacturer' => 'Fabricant contrôlé',
            'medicine_category_uuid' => $category->uuid,
            'supplier_uuids' => [$supplier->uuid],
            'minimum_stock' => 5,
            'prescription_required' => true,
            'sale_price' => '250.00',
            'tariff_reason' => 'Tarif initial validé',
        ])->assertRedirect();

        $medicine = Medicine::query()->with('catalogItem.currentStandardTariff', 'suppliers')->sole();
        $this->assertSame($category->id, $medicine->medicine_category_id);
        $this->assertSame('MED-CONTROLE-01', $medicine->catalogItem->code);
        $this->assertSame('250.00', $medicine->catalogItem->currentStandardTariff->amount);
        $this->assertTrue($medicine->prescription_required);
        $this->assertTrue($medicine->suppliers->contains($supplier));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'pharmacy',
            'entity_type' => Medicine::class,
            'entity_id' => $medicine->id,
        ]);
    }

    public function test_pharmacy_setup_permissions_are_independent_from_operational_pharmacy_access(): void
    {
        $user = $this->setupUser(['pharmacy.view', 'medicines.view']);

        $this->actingAs($user)->post('/pharmacy/setup/categories', [
            'code' => 'DENIED',
            'name' => 'Refusée',
        ])->assertForbidden();
        $this->actingAs($user)->post('/pharmacy/setup/medicines', [])->assertForbidden();
    }

    public function test_medicine_soft_delete_is_audited_and_force_delete_is_protected_after_stock_exists(): void
    {
        $user = $this->setupUser(['medicines.create', 'catalog.items.create', 'catalog.tariffs.create']);
        $this->actingAs($user)->post('/pharmacy/setup/medicines', [
            'code' => 'MED-SOFT-01',
            'name' => 'Produit archivable',
            'generic_name' => 'DCI archivable',
            'form' => 'TABLET',
            'strength' => '10 mg',
            'unit' => 'unité',
            'minimum_stock' => 0,
            'prescription_required' => false,
            'sale_price' => '100.00',
            'tariff_reason' => 'Tarif initial validé',
        ])->assertRedirect();
        $medicine = Medicine::query()->sole();
        MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'TRACE-LOT',
            'expires_at' => now()->addYear(),
            'quantity_on_hand' => 1,
            'active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $medicine->delete_reason = 'Archivage contrôlé';
        $medicine->delete();

        $this->assertSoftDeleted('medicines', ['id' => $medicine->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete',
            'module' => 'pharmacy',
            'entity_id' => $medicine->id,
            'reason' => 'Archivage contrôlé',
        ]);

        $this->expectException(ForceDeleteForbiddenException::class);
        Medicine::onlyTrashed()->findOrFail($medicine->id)->forceDelete();
    }

    public function test_bulk_import_creates_all_rows_transactionally_from_the_empty_template_shape(): void
    {
        $user = $this->setupUser([
            'medicines.import', 'medicines.create', 'catalog.items.create', 'catalog.tariffs.create',
        ]);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([MedicineCatalogImportService::HEADERS], null, 'A1');
        $sheet->fromArray([[
            'MED-IMPORT-01', 'Produit importé', 'DCI importée', 'TABLET', '20 mg', 'unité',
            '', '', '', '', 'NON', 2, '150.00', 'Import initial contrôlé', '',
        ]], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'pharmacy-catalog-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $this->actingAs($user)->post('/pharmacy/setup/medicines/import', [
                'file' => new UploadedFile($path, 'medicaments.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertRedirect()->assertSessionHas('status');
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('catalog_items', ['code' => 'MED-IMPORT-01']);
        $this->assertDatabaseHas('medicines', ['generic_name' => 'DCI importée', 'minimum_stock' => 2]);
        $this->assertDatabaseHas('catalog_tariffs', ['amount' => '150.00', 'active_key' => 'CURRENT']);
    }
}
