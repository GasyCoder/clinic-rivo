<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ADR-098 — supplier list import/export from the portal. The portal only
 * reads the file's shape; the site decides every rule, first in a preview
 * that writes nothing, then on explicit confirmation.
 */
class PharmacySupplierImportExportPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'url' => 'https://m.test', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function workbook(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headers, ...$rows], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'suppliers-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'fournisseurs.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_the_export_lists_the_site_suppliers_archived_included(): void
    {
        Http::fake(['https://m.test/api/v1/super-admin/pharmacy/suppliers*' => Http::response(['data' => [
            ['uuid' => 'a', 'code' => 'PHARMADIS', 'name' => 'Pharmadis', 'phone' => '034', 'catalogs_count' => 2, 'archived' => false],
            ['uuid' => 'b', 'code' => 'ANCIEN', 'name' => 'Ancien', 'catalogs_count' => 0, 'archived' => true, 'delete_reason' => 'Plus actif'],
        ]])]);

        $response = $this->actingAs($this->superAdmin)->get('/super-admin/pharmacy-suppliers/export?site_code=M');

        $response->assertOk();
        $this->assertStringContainsString('fournisseurs-m-', (string) $response->headers->get('content-disposition'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'status=ALL'));
    }

    public function test_a_file_is_analyzed_shown_then_imported_only_on_confirmation(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/pharmacy/suppliers/import-preview' => Http::response(['data' => [
                'rows' => [['line' => 2, 'code' => 'SOMAPHAR', 'name' => 'Somaphar', 'action' => 'CREATE', 'changes' => [], 'errors' => []]],
                'summary' => ['create' => 1, 'update' => 0, 'unchanged' => 0, 'error' => 0],
            ]]),
            'https://m.test/api/v1/super-admin/pharmacy/suppliers/import' => Http::response([
                'message' => 'Import terminé : 1 fournisseur(s) créé(s), 0 mis à jour, 0 inchangé(s).',
                'data' => ['created' => 1, 'updated' => 0, 'unchanged' => 0],
            ]),
        ]);

        $file = $this->workbook(
            ['Code', 'Nom', 'Téléphone', 'Statut'],
            [['SOMAPHAR', 'Somaphar', '034 12 345 67', 'Actif'], ['ANCIEN', 'Ancien', '', 'Archivé']],
        );

        $analyzed = $this->actingAs($this->superAdmin)
            ->post('/super-admin/pharmacy-suppliers/import', ['site_code' => 'M', 'file' => $file])
            ->assertRedirect();
        $location = (string) $analyzed->headers->get('Location');
        $this->assertMatchesRegularExpression('#/super-admin/pharmacy-suppliers/import/[0-9a-f-]{36}$#', $location);

        // The archived line of an exported file is not sent; nothing is written yet.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/import-preview')
            && count($request['rows']) === 1
            && $request['rows'][0]['code'] === 'SOMAPHAR'
            && $request['rows'][0]['phone'] === '034 12 345 67');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/suppliers/import'));

        $this->actingAs($this->superAdmin)->get($location)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/PharmacySuppliers/ImportSuppliers')
                ->where('summary.create', 1)
                ->where('skippedArchived', 1));

        $this->actingAs($this->superAdmin)->post($location)
            ->assertRedirect('/super-admin/pharmacy-suppliers?site=M')
            ->assertSessionHas('status', 'Import terminé : 1 fournisseur(s) créé(s), 0 mis à jour, 0 inchangé(s).');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/suppliers/import') && $request->hasHeader('Idempotency-Key'));

        // A confirmed preview cannot be replayed.
        $this->actingAs($this->superAdmin)->post($location)
            ->assertRedirect('/super-admin/pharmacy-suppliers')
            ->assertSessionHasErrors('file');
    }

    public function test_a_file_without_the_expected_columns_is_refused_before_reaching_the_site(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/pharmacy-suppliers')
            ->post('/super-admin/pharmacy-suppliers/import', [
                'site_code' => 'M',
                'file' => $this->workbook(['Fournisseur', 'Tel'], [['Somaphar', '034']]),
            ])
            ->assertRedirect('/super-admin/pharmacy-suppliers')
            ->assertSessionHasErrors('file');

        Http::assertNothingSent();
    }
}
