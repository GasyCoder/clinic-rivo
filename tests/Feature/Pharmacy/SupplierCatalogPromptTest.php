<?php

namespace Tests\Feature\Pharmacy;

use App\Models\MedicineSupplier;
use App\Models\SupplierCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ADR-241 — « Générer le prompt » : la structure exacte du fichier du
 * fournisseur fait foi, et le prompt le dit.
 */
class SupplierCatalogPromptTest extends TestCase
{
    use RefreshDatabase;

    private MedicineSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site_api.token' => 'clinic-test-token']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        Storage::fake('local');
        $this->supplier = MedicineSupplier::query()->create(['code' => 'ARB', 'name' => 'Arbiochem']);
    }

    public function test_the_prompt_describes_the_supplier_structure_as_the_source_of_truth(): void
    {
        $catalog = $this->excelCatalog([
            ['Tarif Arbiochem 2026'],
            ['Code article', 'Désignation', 'Conditionnement', 'PU HT', 'TVA', 'Disponible'],
            ['ANTALGIQUES'],
            ['A001', 'Paracetamol 500mg cp', 'Bte 100', 4500, 'Oui', 'oui'],
            ['A002', 'Ibuprofène 400mg cp', 'Bte 30', 3200, 'Oui', 'non'],
            ['ANTISEPTIQUES'],
            ['B001', 'Alcool 70° 1L', 'Flacon', 7100.5, 'Non', 'oui'],
            ['B002', 'Bétadine 125ml', 'Flacon', 9800, 'Non', 'oui'],
        ]);

        $response = $this->withHeaders($this->headers(['supplier_catalogs.view']))
            ->getJson("/api/v1/super-admin/pharmacy/suppliers/{$this->supplier->uuid}/catalogs/{$catalog->uuid}/structure")
            ->assertOk();

        $sheet = $response->json('data.structure.sheets.0');
        $this->assertSame(2, $sheet['header_row']);
        $this->assertSame(4, $sheet['data_rows']);
        $this->assertSame(['Code article', 'Désignation', 'Conditionnement', 'PU HT', 'TVA', 'Disponible'], array_column($sheet['columns'], 'header'));
        $this->assertSame(['ANTALGIQUES', 'ANTISEPTIQUES'], array_column($sheet['sections'], 'label'));
        $price = collect($sheet['columns'])->firstWhere('header', 'PU HT');
        $this->assertEquals(['min' => 3200.0, 'max' => 9800.0], $price['range']);
        $this->assertSame(['Oui', 'Non'], collect($sheet['columns'])->firstWhere('header', 'TVA')['options']);

        $prompt = $response->json('data.prompt');
        $this->assertStringStartsWith('Rôle : tu génères un canevas Excel (.xlsx)', $prompt);
        $this->assertStringContainsString('Le document joint (xlsx, csv, pdf, image ou texte) est la seule source de vérité.', $prompt);
        $this->assertStringContainsString('N\'impose la structure d\'aucun autre système.', $prompt);
        $this->assertStringContainsString('garde la structure du fournisseur et signale l\'écart. Ne le corrige pas.', $prompt);
        $this->assertStringContainsString('Ligne 1 : les intitulés exacts du fournisseur, en gras blanc sur fond #334155.', $prompt);
        $this->assertStringContainsString('Réponse : livre uniquement le fichier.', $prompt);
        $this->assertStringContainsString('en cas d’écart, le document fait foi', $prompt);
        $this->assertStringContainsString('| B | Désignation |', $prompt);
        $this->assertStringContainsString('« ANTALGIQUES »', $prompt);
        $this->assertStringContainsString('Texte au-dessus des en-têtes : « Tarif Arbiochem 2026 »', $prompt);
        // Rien de la structure de RIVO n'est imposé : ses colonnes d'import n'apparaissent pas.
        $this->assertStringNotContainsString('prix_fournisseur', $prompt);

        // Le même fichier donne le même prompt.
        $this->assertSame($prompt, $this->withHeaders($this->headers(['supplier_catalogs.view']))
            ->getJson("/api/v1/super-admin/pharmacy/suppliers/{$this->supplier->uuid}/catalogs/{$catalog->uuid}/structure")
            ->json('data.prompt'));
    }

    public function test_a_pdf_catalog_yields_a_prompt_that_asks_for_the_columns(): void
    {
        Storage::disk('local')->put('catalogues/tarif.pdf', '%PDF-1.4');
        $catalog = $this->catalog('tarif.pdf', 'catalogues/tarif.pdf', 'PDF', 'application/pdf');

        $prompt = $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson("/api/v1/super-admin/pharmacy/suppliers/{$this->supplier->uuid}/catalogs/{$catalog->uuid}/structure")
            ->assertOk()
            ->assertJsonPath('data.structure.readable', false)
            ->json('data.prompt');

        $this->assertStringContainsString('ne suppose aucune colonne', $prompt);
        $this->assertStringStartsWith('Rôle : tu génères un canevas Excel (.xlsx)', $prompt);
    }

    public function test_reading_the_structure_needs_a_catalog_permission(): void
    {
        $catalog = $this->excelCatalog([['Réf', 'Nom'], ['1', 'Gants']]);

        $this->withHeaders($this->headers(['purchase_orders.view']))
            ->getJson("/api/v1/super-admin/pharmacy/suppliers/{$this->supplier->uuid}/catalogs/{$catalog->uuid}/structure")
            ->assertForbidden();
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    private function excelCatalog(array $rows): SupplierCatalog
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Tarif');

        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValue([$column + 1, $index + 1], $value);
            }
        }

        $path = 'catalogues/'.Str::uuid().'.xlsx';
        $file = Storage::disk('local')->path($path);
        @mkdir(dirname($file), 0777, true);
        (new Xlsx($spreadsheet))->save($file);

        return $this->catalog('tarif.xlsx', $path, 'EXCEL', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function catalog(string $name, string $path, string $kind, string $mime): SupplierCatalog
    {
        return SupplierCatalog::query()->create([
            'medicine_supplier_id' => $this->supplier->id,
            'original_name' => $name,
            'path' => $path,
            'mime_type' => $mime,
            'size' => 10,
            'kind' => $kind,
            'external_created_by_uuid' => (string) Str::uuid(),
            'external_created_by_name' => 'Direction centrale',
        ]);
    }

    /** @param  array<int, string>  $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }
}
