<?php

namespace Tests\Feature\Api;

use App\Models\MedicineSupplier;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Services\Pharmacy\SupplierCatalogImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ADR-098 — the site API through which the central portal manages supplier
 * folders and catalogs. The remote Super Admin has no local account: every
 * write is authorized from the permissions the portal forwards, attributed
 * to the portal identity and audited on the site.
 */
class PharmacySupplierSiteApiTest extends TestCase
{
    use RefreshDatabase;

    private string $actorUuid;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        (new PermissionSeeder)->run();
        Storage::fake('local');
        $this->actorUuid = (string) Str::uuid();
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

    private function excelFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([SupplierCatalogImportService::HEADERS], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'portal-catalog-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'tarif-septembre.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_suppliers_are_listed_and_created_only_with_the_forwarded_permissions(): void
    {
        $this->withHeaders($this->headers([]))->getJson('/api/v1/super-admin/pharmacy/suppliers')->assertForbidden();

        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->postJson('/api/v1/super-admin/pharmacy/suppliers', ['code' => 'pharmadis', 'name' => 'Pharmadis'])
            ->assertForbidden();

        $this->withHeaders($this->headers(['medicine_suppliers.create']))
            ->postJson('/api/v1/super-admin/pharmacy/suppliers', ['code' => 'pharmadis', 'name' => 'Pharmadis'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'PHARMADIS');

        $supplier = MedicineSupplier::query()->sole();
        $this->assertNull($supplier->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => MedicineSupplier::class,
            'entity_id' => $supplier->id,
            'external_actor_uuid' => $this->actorUuid,
        ]);

        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson('/api/v1/super-admin/pharmacy/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Pharmadis')
            ->assertJsonPath('data.0.catalogs_count', 0)
            ->assertJsonPath('meta.site.code', 'A');
    }

    public function test_a_catalog_file_is_uploaded_previewed_imported_and_activated_from_the_portal(): void
    {
        $supplier = MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis']);
        $base = "/api/v1/super-admin/pharmacy/suppliers/{$supplier->uuid}/catalogs";

        // A binary file, not JSON rows: the site keeps the catalog as sent.
        $this->withHeaders($this->headers(['supplier_catalogs.create']))
            ->post($base, ['file' => $this->excelFile([
                ['PDS-1', 'Paracétamol 500 mg', 'boîte de 10', 120],
                ['PDS-2', 'Amoxicilline 500 mg', 'boîte de 12', 380],
            ]), 'notes' => 'Tarif septembre'])
            ->assertCreated()
            ->assertJsonPath('data.creator', 'Direction centrale');

        $catalog = SupplierCatalog::query()->sole();
        $this->assertNull($catalog->created_by);
        $this->assertSame($this->actorUuid, $catalog->external_created_by_uuid);
        Storage::disk('local')->assertExists($catalog->path);

        $this->withHeaders($this->headers(['supplier_catalogs.create']))
            ->getJson("{$base}/{$catalog->uuid}/import-preview")
            ->assertOk()
            ->assertJsonPath('data.supplier.name', 'Pharmadis')
            ->assertJsonPath('data.preview.valid_count', 2)
            ->assertJsonPath('data.preview.invalid_count', 0);
        $this->assertSame(0, SupplierCatalogItem::query()->count());

        $this->withHeaders($this->headers(['supplier_catalogs.create']))
            ->postJson("{$base}/{$catalog->uuid}/import")
            ->assertOk()
            ->assertJsonPath('data.rows', 2);
        $this->assertSame(2, SupplierCatalogItem::query()->whereNull('created_by')->count());

        $this->withHeaders($this->headers(['supplier_catalogs.view']))
            ->postJson("{$base}/{$catalog->uuid}/activate")
            ->assertForbidden();
        $this->withHeaders($this->headers(['supplier_catalogs.update']))
            ->postJson("{$base}/{$catalog->uuid}/activate")
            ->assertOk();
        $this->assertTrue($catalog->fresh()->isActive());

        $this->withHeaders($this->headers(['supplier_catalogs.view']))
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.catalogs.0.is_active', true)
            ->assertJsonPath('data.catalogs.0.items_count', 2);
    }

    public function test_archiving_from_the_portal_requires_a_reason_and_can_be_restored(): void
    {
        $supplier = MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis']);
        $base = "/api/v1/super-admin/pharmacy/suppliers/{$supplier->uuid}/catalogs";
        $this->withHeaders($this->headers(['supplier_catalogs.create']))
            ->post($base, ['file' => $this->excelFile([['PDS-1', 'Paracétamol', 'boîte', 100]])])
            ->assertCreated();
        $catalog = SupplierCatalog::query()->sole();

        $this->withHeaders($this->headers(['supplier_catalogs.delete']))
            ->deleteJson("{$base}/{$catalog->uuid}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->withHeaders($this->headers(['supplier_catalogs.delete']))
            ->deleteJson("{$base}/{$catalog->uuid}", ['reason' => 'Tarif remplacé'])
            ->assertOk();
        $this->assertSoftDeleted('supplier_catalogs', ['id' => $catalog->id]);

        $this->withHeaders($this->headers(['supplier_catalogs.restore']))
            ->postJson("{$base}/{$catalog->uuid}/restore")
            ->assertOk();
        $this->assertNull($catalog->fresh()->deleted_at);
    }
}
