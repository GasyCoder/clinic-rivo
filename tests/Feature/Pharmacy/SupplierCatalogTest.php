<?php

namespace Tests\Feature\Pharmacy;

use App\Actions\Pharmacy\CreateMedicineFromSupplierCatalogAction;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
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

    public function test_a_real_supplier_catalogue_without_prices_is_imported_instead_of_being_rejected(): void
    {
        // Le fichier réel du fournisseur Arbiochem : 119 produits, aucun
        // prix. Un catalogue liste d'abord ce que le fournisseur propose ;
        // le tarif arrive parfois séparément (ADR-098).
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.create', 'supplier_catalogs.update']);
        $fixture = base_path('tests/Fixtures/Pharmacy/Arbiochem_catalogue_rempli.xlsx');

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => new UploadedFile($fixture, 'Arbiochem_catalogue_rempli.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertRedirect();

        $catalog = SupplierCatalog::query()->latest('id')->sole();
        $preview = app(SupplierCatalogImportService::class)->preview($catalog);

        $this->assertNull($preview['error']);
        $this->assertSame([], $preview['missing_headers']);
        $this->assertSame(0, $preview['invalid_count']);
        $this->assertSame(119, $preview['valid_count']);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")
            ->assertSessionHasNoErrors();

        $this->assertSame(119, $catalog->items()->count());
        $this->assertNull($catalog->items()->first()->supplier_price);
    }

    public function test_an_unreadable_price_names_the_line_the_column_and_the_value(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.create', 'supplier_catalogs.update']);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => $this->excelFile([
                ['REF-1', 'Paracétamol 500 mg', 'boîte de 10', '4 500,50 Ar'],
                ['REF-2', 'Amoxicilline', 'boîte', 'cent'],
                ['REF-3', '', 'flacon', '100'],
            ]),
        ]);
        $catalog = SupplierCatalog::query()->latest('id')->sole();
        $preview = app(SupplierCatalogImportService::class)->preview($catalog);

        // Un montant formaté par Excel reste un prix, pas une erreur.
        $this->assertSame('4500.50', $preview['rows'][0]['supplier_price']);
        $this->assertSame([], $preview['rows'][0]['errors']);

        $this->assertSame('Prix fournisseur', $preview['rows'][1]['issues'][0]['column']);
        $this->assertSame('cent', $preview['rows'][1]['issues'][0]['value']);
        $this->assertStringContainsString('nombre', $preview['rows'][1]['issues'][0]['message']);

        $this->assertSame('Médicament', $preview['rows'][2]['issues'][0]['column']);
        $this->assertStringContainsString('obligatoire', $preview['rows'][2]['issues'][0]['message']);
        $this->assertSame(2, $preview['invalid_count']);
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

    /**
     * ADR-098 — an import transcribes a supplier's document, and a document
     * can be misread. Correcting a line is allowed with the catalogue's own
     * rights; withdrawing it is a Soft Delete, never a destruction.
     */
    public function test_a_catalogue_line_is_corrected_withdrawn_and_restored(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser(['supplier_catalogs.create', 'supplier_catalogs.update', 'supplier_catalogs.delete', 'supplier_catalogs.restore']);
        $item = $this->importedLine($supplier, $user);

        $this->actingAs($user)->put("/pharmacy/suppliers/{$supplier->uuid}/catalog-items/{$item->uuid}", [
            'reference' => 'REF-1B',
            'medicine_label' => 'Paracétamol 500 mg',
            'presentation' => 'boîte de 10',
            'supplier_price' => '120',
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('REF-1B', $item->reference);
        $this->assertSame('120.00', (string) $item->supplier_price);

        $this->actingAs($user)->delete("/pharmacy/suppliers/{$supplier->uuid}/catalog-items/{$item->uuid}", ['reason' => 'Ligne inexistante chez le fournisseur'])
            ->assertRedirect();

        $withdrawn = SupplierCatalogItem::withTrashed()->findOrFail($item->id);
        $this->assertTrue($withdrawn->trashed());
        $this->assertSame('Ligne inexistante chez le fournisseur', $withdrawn->delete_reason);
        // Soft delete, jamais destruction : la ligne existe toujours.
        $this->assertDatabaseCount('supplier_catalog_items', 2);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalog-items/{$item->uuid}/restore")->assertRedirect();
        $this->assertFalse($item->fresh()->trashed());
    }

    public function test_correcting_a_catalogue_line_requires_the_catalogue_right(): void
    {
        $supplier = $this->supplier();
        $author = $this->setupUser(['supplier_catalogs.create']);
        $item = $this->importedLine($supplier, $author);
        $reader = $this->setupUser(['supplier_catalogs.view']);

        $this->actingAs($reader)->put("/pharmacy/suppliers/{$supplier->uuid}/catalog-items/{$item->uuid}", [
            'reference' => 'PIRATE', 'medicine_label' => 'Détournée',
        ])->assertForbidden();

        $this->assertSame('REF-1', $item->fresh()->reference);
    }

    /**
     * ADR-098 — le cas réel : « Alcool bleu » rattaché à « Alcool blanc ».
     * Défaire le rattachement clôt le prix qu'il avait créé, sans jamais le
     * supprimer : la clinique a réellement cru ce prix-là (ADR-010).
     */
    public function test_undoing_a_wrong_link_closes_its_price_without_deleting_it(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser([
            'supplier_catalogs.create', 'medicine_supplier_offers.create', 'medicine_supplier_offers.update',
            'medicines.create', 'catalog.items.create', 'catalog.tariffs.create',
        ]);
        $item = $this->importedLine($supplier, $user);

        $medicine = app(CreateMedicineFromSupplierCatalogAction::class)
            ->execute($item, CatalogActor::fromUser($user));

        $this->assertSame($medicine->id, $item->fresh()->linked_medicine_id);
        $this->assertSame('100.00', (string) $medicine->currentOfferFor($supplier)->value('quoted_price'));

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalog-items/{$item->uuid}/unlink")->assertRedirect();

        $this->assertNull($item->fresh()->linked_medicine_id);
        // Plus de prix en cours, mais la ligne de prix demeure, close.
        $this->assertNull($medicine->currentOfferFor($supplier)->value('quoted_price'));
        $this->assertDatabaseCount('medicine_supplier_offers', 1);
    }

    /** Relire le fichier ne doit pas défaire le travail de rattachement. */
    public function test_re_reading_a_catalogue_keeps_the_links_already_made(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser([
            'supplier_catalogs.create', 'medicine_supplier_offers.create',
            'medicines.create', 'catalog.items.create', 'catalog.tariffs.create',
        ]);
        $item = $this->importedLine($supplier, $user);
        $medicine = app(CreateMedicineFromSupplierCatalogAction::class)
            ->execute($item, CatalogActor::fromUser($user));
        $catalog = $item->catalog;

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")->assertRedirect();

        $reread = $catalog->fresh()->items()->where('reference', 'REF-1')->sole();
        $this->assertNotSame($item->id, $reread->id, 'la ligne est bien remplacée');
        $this->assertSame($medicine->id, $reread->linked_medicine_id, 'mais son rattachement survit');
        // Remplacement physique : rien ne s'accumule dans la corbeille.
        $this->assertSame(2, SupplierCatalogItem::withTrashed()->count());
    }

    /**
     * ADR-098 — the supplier classifies its own price list. The family is
     * read, kept verbatim, and proposed when the product enters the clinic
     * catalogue. It stays optional: the files already distributed have no
     * such column.
     */
    public function test_the_family_declared_by_the_supplier_is_read_and_carried_to_the_medicine(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser([
            'supplier_catalogs.create', 'medicine_supplier_offers.create',
            'medicines.create', 'catalog.items.create', 'catalog.tariffs.create', 'medicine_categories.create',
        ]);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => $this->classifiedExcelFile([
                ['AMOX-500', 'Amoxicilline 500 mg', 'Boîte de 12', 'Antibiotiques', 4500],
                ['COMP-001', 'Compresses stériles', 'Sachet de 5', '', 900],
            ]),
        ]);
        $catalog = SupplierCatalog::query()->sole();
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import")->assertRedirect();

        $classified = $catalog->items()->where('reference', 'AMOX-500')->sole();
        $unclassified = $catalog->items()->where('reference', 'COMP-001')->sole();
        $this->assertSame('Antibiotiques', $classified->family_label);
        $this->assertNull($unclassified->family_label, 'une ligne sans famille reste sans famille');

        $medicine = app(CreateMedicineFromSupplierCatalogAction::class)
            ->execute($classified, CatalogActor::fromUser($user));
        $this->assertSame('Antibiotiques', $medicine->category?->name);

        // La famille n'est pas recréée deux fois pour une autre écriture.
        $second = app(CreateMedicineFromSupplierCatalogAction::class)
            ->execute($unclassified, CatalogActor::fromUser($user));
        $this->assertNull($second->medicine_category_id);
        $this->assertDatabaseCount('medicine_categories', 1);
    }

    /** Sans le droit d'écrire le référentiel, aucune famille n'est créée. */
    public function test_a_family_is_never_created_by_an_actor_who_may_not_write_the_referential(): void
    {
        $supplier = $this->supplier();
        $user = $this->setupUser([
            'supplier_catalogs.create', 'medicine_supplier_offers.create',
            'medicines.create', 'catalog.items.create', 'catalog.tariffs.create',
        ]);

        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => $this->classifiedExcelFile([['AMOX-500', 'Amoxicilline 500 mg', 'Boîte de 12', 'Antibiotiques', 4500]]),
        ]);
        $catalog = SupplierCatalog::query()->sole();
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import");

        $medicine = app(CreateMedicineFromSupplierCatalogAction::class)
            ->execute($catalog->items()->sole(), CatalogActor::fromUser($user));

        $this->assertNull($medicine->medicine_category_id);
        $this->assertDatabaseCount('medicine_categories', 0);
    }

    /** Un fichier à cinq colonnes, « Famille » incluse. */
    private function classifiedExcelFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([array_keys(SupplierCatalogImportService::HEADER_LABELS)], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'supplier-catalog-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'catalogue-classe.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importedLine(MedicineSupplier $supplier, User $user): SupplierCatalogItem
    {
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs", [
            'file' => $this->excelFile([
                ['REF-1', 'Paracétamol 500mg', 'boîte 10', 100],
                ['REF-2', 'Amoxicilline 500mg', 'boîte 20', 250],
            ]),
        ]);
        $catalog = SupplierCatalog::query()->latest('id')->first();
        $this->actingAs($user)->post("/pharmacy/suppliers/{$supplier->uuid}/catalogs/{$catalog->uuid}/import");

        return $catalog->items()->where('reference', 'REF-1')->sole();
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
