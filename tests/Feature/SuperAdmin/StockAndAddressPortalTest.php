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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StockAndAddressPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'url' => 'https://m.test', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
                ['code' => 'B', 'name' => 'Boriziny', 'url' => 'https://b.test', 'api_url' => null, 'api_token' => null],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_stock_page_aggregates_reachable_sites_without_failing_for_an_unconfigured_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/pharmacy/stock' => Http::response($this->stockPayload('Mampikony', 7), 200),
            'https://a.test/api/v1/super-admin/pharmacy/stock' => Http::response($this->stockPayload('Ambondromamy', 5), 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Stock/Index')
                ->has('sites', 3)
                ->where('sites.0.status', 'ONLINE')
                ->where('sites.2.status', 'UNCONFIGURED')
                ->where('summary.online_sites', 2)
                ->where('summary.medicines', 2)
                ->where('summary.available_quantity', 12));
    }

    public function test_address_page_and_create_command_use_the_selected_site_api(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/address-entries*' => Http::response([
                'data' => [['uuid' => 'address-m', 'label' => 'Mampikony centre', 'active' => true]],
                'meta' => ['summary' => ['displayed' => 1, 'active' => 1, 'archived' => 0]],
            ], 200),
            'https://a.test/api/v1/super-admin/address-entries*' => Http::response([
                'data' => [],
                'meta' => ['summary' => ['displayed' => 0, 'active' => 0, 'archived' => 0]],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/addresses?status=ACTIVE')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Addresses/Index')
                ->has('sites', 3)
                ->where('sites.0.data.0.label', 'Mampikony centre')
                ->where('filters.status', 'ACTIVE'));

        $this->actingAs($this->superAdmin)->post('/super-admin/addresses', [
            'site_code' => 'M',
            'label' => 'Nouvelle localité',
        ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/address-entries'
            && $request->hasHeader('Idempotency-Key')
            && $request['label'] === 'Nouvelle localité');
    }

    public function test_stock_export_generates_one_excel_row_per_lot_for_the_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/pharmacy/stock' => Http::response($this->stockPayloadWithLot(), 200),
            'https://a.test/api/v1/super-admin/pharmacy/stock' => Http::response($this->stockPayload('Ambondromamy', 5), 200),
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->get('/super-admin/stock/export?site_code=M')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = $this->excelRows($response->streamedContent());

        $this->assertSame(['Code site', 'Site', 'Code médicament', 'Médicament'], array_slice($rows[0], 0, 4));
        $this->assertSame(['M', 'Mampikony', 'PARA-500', 'Paracétamol 500 mg'], array_slice($rows[1], 0, 4));
        $this->assertSame('LOT-2026-01', $rows[1][8]);
        $this->assertNotContains('Ambondromamy', $rows[1]);
    }

    public function test_address_export_respects_the_selected_site_and_current_filters(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/address-entries*' => Http::response([
                'data' => [[
                    'uuid' => 'address-m',
                    'label' => 'Mampikony centre',
                    'active' => true,
                    'archived_at' => null,
                    'archive_reason' => null,
                    'updated_at' => '2026-08-23T10:00:00+03:00',
                ]],
                'meta' => ['summary' => ['displayed' => 1, 'active' => 1, 'archived' => 0]],
            ], 200),
            'https://a.test/api/v1/super-admin/address-entries*' => Http::response(['data' => []], 200),
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->get('/super-admin/addresses/export?site_code=M&status=ACTIVE&search=centre')
            ->assertOk();

        $rows = $this->excelRows($response->streamedContent());

        $this->assertSame(['Code site', 'Site', 'UUID', 'Adresse', 'Statut'], array_slice($rows[0], 0, 5));
        $this->assertSame(['M', 'Mampikony', 'address-m', 'Mampikony centre', 'ACTIVE'], array_slice($rows[1], 0, 5));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://m.test/api/v1/super-admin/address-entries')
            && $request->data()['status'] === 'ACTIVE'
            && $request->data()['search'] === 'centre');
    }

    public function test_address_excel_import_is_parsed_and_forwarded_to_the_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/address-entries/import' => Http::response([
                'message' => '2 adresse(s) importée(s), 0 déjà présente(s).',
                'data' => ['received' => 2, 'unique' => 2, 'created' => 2, 'existing' => 0],
            ], 200),
        ]);
        $file = $this->excelUpload(
            'adresses.xlsx',
            ['Adresse'],
            [['Mampikony centre'], ['Quartier Nord'], ['quartier nord']],
        );

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/addresses/import', [
                'site_code' => 'M',
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2 adresse(s) importée(s), 0 déjà présente(s).');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/address-entries/import'
            && $request->hasHeader('Idempotency-Key')
            && $request['labels'] === ['Mampikony centre', 'Quartier Nord']);
    }

    public function test_stock_excel_import_is_parsed_and_forwarded_to_the_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/pharmacy/stock/import' => Http::response([
                'message' => '1 ligne(s) Excel importée(s), 25 unité(s) ajoutée(s).',
                'data' => ['rows' => 1, 'quantity' => 25, 'created_lots' => 1, 'updated_lots' => 0],
            ], 200),
        ]);
        $file = $this->excelUpload(
            'stock.xlsx',
            ['Code médicament', 'Numéro de lot', 'Opération', 'Quantité', 'Date réception', 'Date péremption', 'Motif'],
            [['PARA-500', 'LOT-2026-03', 'STOCK_INITIAL', 25, '01/09/2026', '31/12/2027', 'Stock initial vérifié']],
        );

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/stock/import', ['site_code' => 'M', 'file' => $file])
            ->assertRedirect()
            ->assertSessionHas('status', '1 ligne(s) Excel importée(s), 25 unité(s) ajoutée(s).');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/pharmacy/stock/import'
            && $request->hasHeader('Idempotency-Key')
            && $request['rows'][0] === [
                'code_medicament' => 'PARA-500',
                'numero_lot' => 'LOT-2026-03',
                'operation' => 'STOCK_INITIAL',
                'quantite' => 25,
                'date_reception' => '2026-09-01',
                'date_peremption' => '2027-12-31',
                'motif' => 'Stock initial vérifié',
            ]);
    }

    public function test_tariff_workspace_aggregates_site_catalogs_and_preserves_an_offline_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/catalog*' => Http::response($this->catalogPayload('Mampikony'), 200),
            'https://a.test/api/v1/super-admin/catalog*' => Http::response($this->catalogPayload('Ambondromamy'), 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/tariffs?site=A')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Tariffs/Index')
                ->has('sites', 3)
                ->where('sites.0.status', 'ONLINE')
                ->where('sites.2.status', 'UNCONFIGURED')
                ->where('sites.1.data.items.0.code', 'CONSULT-GEN')
                ->where('sites.1.data.mutual_organizations.0.name', 'ADEFI')
                ->where('selectedSiteCode', 'A'));
    }

    public function test_tariff_workspace_forwards_a_creation_with_idempotency_and_actor_permissions(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/catalog' => Http::response([
                'message' => 'Désignation ECHO-API créée sur le site.',
                'data' => ['uuid' => 'catalog-uuid', 'code' => 'ECHO-API'],
            ], 201),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/workspaces/tariffs/items', [
                'site_code' => 'M',
                'code' => 'ECHO-API',
                'name' => 'Échographie API',
                'type' => 'SERVICE',
                'module' => 'MEDICINE',
                'unit' => 'examen',
                'billable' => true,
                'stockable' => false,
                'reception_selectable' => true,
                'reception_routing_mode' => 'MEDICINE_DIRECT',
                'care_requires_allergy_check' => false,
                'care_recommends_vitals' => false,
                'tariff_amount' => 30000,
                'mutual_tariff_amount' => 25000,
                'tariff_reason' => 'Grilles initiales validées',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/catalog'
            && $request->hasHeader('Idempotency-Key')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'catalog.items.create')
            && $request['code'] === 'ECHO-API'
            && $request['mutual_tariff_amount'] === 25000);
    }

    public function test_tariff_workspace_forwards_mutual_organization_management_to_the_selected_site(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/mutual-organizations' => Http::response([
                'message' => 'Organisme ajouté au référentiel du site.',
                'data' => ['uuid' => 'organization-uuid', 'name' => 'ADEFI'],
            ], 201),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/workspaces/tariffs/mutual-organizations', [
                'site_code' => 'A',
                'name' => 'ADEFI',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://a.test/api/v1/super-admin/mutual-organizations'
            && $request->hasHeader('Idempotency-Key')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'mutual_organizations.create')
            && $request['name'] === 'ADEFI');
    }

    public function test_bulk_commands_are_forwarded_to_exactly_one_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/address-entries/bulk/archive' => Http::response(['message' => '2 adresses archivées.'], 200),
            'https://m.test/api/v1/super-admin/catalog/bulk/restore' => Http::response(['message' => '2 désignations restaurées.'], 200),
            'https://m.test/api/v1/super-admin/mutual-organizations/bulk/archive' => Http::response(['message' => '2 organismes archivés.'], 200),
        ]);
        $uuids = [
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
        ];

        $this->actingAs($this->superAdmin)->post('/super-admin/addresses/bulk/archive', [
            'site_code' => 'M',
            'uuids' => $uuids,
            'reason' => 'Référentiel remplacé après validation',
        ])->assertRedirect()->assertSessionHas('status');
        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/tariffs/items/bulk/restore', [
            'site_code' => 'M',
            'uuids' => $uuids,
        ])->assertRedirect()->assertSessionHas('status');
        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/tariffs/mutual-organizations/bulk/archive', [
            'site_code' => 'M',
            'uuids' => $uuids,
            'reason' => 'Conventions terminées par la direction',
        ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->url() === 'https://m.test/api/v1/super-admin/address-entries/bulk/archive'
            && $request->hasHeader('Idempotency-Key')
            && $request['uuids'] === $uuids
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'address_entries.archive'));
        Http::assertSent(fn ($request) => $request->url() === 'https://m.test/api/v1/super-admin/catalog/bulk/restore'
            && $request['uuids'] === $uuids);
        Http::assertSent(fn ($request) => $request->url() === 'https://m.test/api/v1/super-admin/mutual-organizations/bulk/archive'
            && $request['reason'] === 'Conventions terminées par la direction');
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://a.test'));
    }

    public function test_selected_excel_exports_only_include_the_requested_addresses_and_medicines(): void
    {
        $selectedAddressUuid = '11111111-1111-4111-8111-111111111111';
        $otherAddressUuid = '22222222-2222-4222-8222-222222222222';
        $selectedMedicineUuid = '33333333-3333-4333-8333-333333333333';
        $otherMedicineUuid = '44444444-4444-4444-8444-444444444444';
        $stock = $this->stockPayloadWithLot();
        $stock['data']['medicines'][0]['uuid'] = $selectedMedicineUuid;
        $otherMedicine = $stock['data']['medicines'][0];
        $otherMedicine['uuid'] = $otherMedicineUuid;
        $otherMedicine['code'] = 'AMOX-500';
        $otherMedicine['name'] = 'Amoxicilline 500 mg';
        $otherMedicine['lots'][0]['lot_number'] = 'LOT-AMOX-01';
        $stock['data']['medicines'][] = $otherMedicine;

        Http::fake([
            'https://m.test/api/v1/super-admin/address-entries*' => Http::response(['data' => [
                ['uuid' => $selectedAddressUuid, 'label' => 'Quartier Nord', 'active' => true, 'archived_at' => null, 'archive_reason' => null, 'updated_at' => null],
                ['uuid' => $otherAddressUuid, 'label' => 'Quartier Sud', 'active' => true, 'archived_at' => null, 'archive_reason' => null, 'updated_at' => null],
            ]], 200),
            'https://a.test/api/v1/super-admin/address-entries*' => Http::response(['data' => []], 200),
            'https://m.test/api/v1/super-admin/pharmacy/stock' => Http::response($stock, 200),
            'https://a.test/api/v1/super-admin/pharmacy/stock' => Http::response($this->stockPayload('Ambondromamy', 5), 200),
        ]);

        $addressResponse = $this->actingAs($this->superAdmin)->get(
            '/super-admin/addresses/export?site_code=M&status=ACTIVE&uuids[]='.$selectedAddressUuid,
        )->assertOk();
        $addressRows = $this->excelRows($addressResponse->streamedContent());
        $this->assertSame('Quartier Nord', $addressRows[1][3]);
        $this->assertCount(2, $addressRows);

        $stockResponse = $this->actingAs($this->superAdmin)->get(
            '/super-admin/stock/export?site_code=M&medicine_uuids[]='.$selectedMedicineUuid,
        )->assertOk();
        $stockRows = $this->excelRows($stockResponse->streamedContent());
        $this->assertSame('PARA-500', $stockRows[1][2]);
        $this->assertCount(2, $stockRows);
    }

    public function test_tariff_and_mutual_workbooks_export_and_import_through_the_selected_site_api(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/catalog*' => Http::response($this->catalogPayload('Mampikony'), 200),
            'https://a.test/api/v1/super-admin/catalog*' => Http::response($this->catalogPayload('Ambondromamy'), 200),
        ]);

        $tariffExport = $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/tariffs/export?site_code=M')
            ->assertOk();
        $tariffRows = $this->excelRows($tariffExport->streamedContent());
        $this->assertSame('Tarif sans mutuelle', $tariffRows[0][6]);
        $this->assertSame('20000.00', $tariffRows[1][6]);

        $organizationExport = $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/tariffs/mutual-organizations/export?site_code=M')
            ->assertOk();
        $organizationRows = $this->excelRows($organizationExport->streamedContent());
        $this->assertSame('Couverture mutuelle (%)', $organizationRows[0][4]);
        $this->assertSame('80.00', $organizationRows[1][4]);
        $this->assertSame('20.00', $organizationRows[1][5]);

        Http::fake([
            'https://m.test/api/v1/super-admin/catalog/tariffs/import' => Http::response([
                'message' => 'Import terminé : 2 tarif(s) versionné(s), 0 inchangé(s).',
            ], 200),
            'https://m.test/api/v1/super-admin/mutual-organizations/import' => Http::response([
                'message' => 'Import terminé : 2 créé(s), 0 mis à jour, 0 inchangé(s).',
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/tariffs/import', [
            'site_code' => 'M',
            'file' => $this->excelUpload('tarifs.xlsx',
                ['Code désignation', 'Tarif sans mutuelle', 'Tarif mutuelle', 'Motif de modification'],
                [['CONSULT-GEN', 20000, 18000, 'Grille validée par la direction']]),
        ])->assertRedirect()->assertSessionHas('status');

        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/tariffs/mutual-organizations/import', [
            'site_code' => 'M',
            'file' => $this->excelUpload('mutuelles.xlsx',
                ['Organisme', 'Couverture mutuelle (%)'],
                [['ADEFI', 100], ['BOA', 80]]),
        ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->url() === 'https://m.test/api/v1/super-admin/catalog/tariffs/import'
            && $request['rows'][0]['standard_amount'] === '20000.00'
            && $request['rows'][0]['mutual_amount'] === '18000.00');
        Http::assertSent(fn ($request) => $request->url() === 'https://m.test/api/v1/super-admin/mutual-organizations/import'
            && $request['rows'][1]['name'] === 'BOA'
            && $request['rows'][1]['coverage_rate'] === '80.00');
    }

    /** @return array<string, mixed> */
    private function stockPayload(string $site, int $available): array
    {
        return [
            'data' => [
                'summary' => [
                    'medicines' => 1,
                    'quantity_on_hand' => $available,
                    'reserved_quantity' => 0,
                    'available_quantity' => $available,
                    'out_of_stock' => 0,
                    'expiring_soon' => 0,
                    'expired_lots' => 0,
                ],
                'medicines' => [],
            ],
            'meta' => ['site' => ['name' => $site], 'scope' => 'READ_ONLY'],
        ];
    }

    /** @return array<string, mixed> */
    private function stockPayloadWithLot(): array
    {
        $payload = $this->stockPayload('Mampikony', 12);
        $payload['data']['medicines'] = [[
            'uuid' => 'medicine-uuid',
            'catalog_uuid' => 'catalog-uuid',
            'code' => 'PARA-500',
            'name' => 'Paracétamol 500 mg',
            'generic_name' => 'Paracétamol',
            'form_label' => 'Comprimé',
            'strength' => '500 mg',
            'unit' => 'comprimé',
            'quantity_on_hand' => 12,
            'reserved_quantity' => 2,
            'available_quantity' => 10,
            'status' => 'AVAILABLE',
            'lots' => [[
                'lot_number' => 'LOT-2026-01',
                'received_at' => '2026-08-01',
                'expires_at' => '2027-08-01',
                'quantity_on_hand' => 12,
                'reserved_quantity' => 2,
                'available_quantity' => 10,
                'status' => 'AVAILABLE',
            ]],
        ]];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function catalogPayload(string $site): array
    {
        return [
            'data' => [
                'items' => [[
                    'uuid' => 'catalog-uuid',
                    'code' => 'CONSULT-GEN',
                    'name' => 'Consultation générale',
                    'type' => 'SERVICE',
                    'type_label' => 'Prestation',
                    'module' => 'MEDICINE',
                    'module_label' => 'Médecine',
                    'unit' => 'consultation',
                    'billable' => true,
                    'stockable' => false,
                    'archived' => false,
                    'current_standard_tariff' => ['amount' => '20000.00', 'currency' => 'MGA'],
                    'current_mutual_tariff' => null,
                    'tariffs' => [],
                ]],
                'summary' => [
                    'active' => 1,
                    'archived' => 0,
                    'billable' => 1,
                    'without_standard_tariff' => 0,
                    'without_mutual_tariff' => 1,
                ],
                'options' => ['types' => [], 'modules' => [], 'routing_modes' => [], 'tariff_categories' => []],
                'mutual_organizations' => [[
                    'uuid' => 'organization-uuid',
                    'name' => 'ADEFI',
                    'coverage_rate' => '80.00',
                    'patient_rate' => '20.00',
                    'active' => true,
                    'coverages_count' => 3,
                    'active_coverages_count' => 2,
                ]],
                'mutual_organizations_summary' => [
                    'active' => 1,
                    'archived' => 0,
                    'active_coverages' => 2,
                ],
            ],
            'meta' => ['site' => ['name' => $site]],
        ];
    }

    /** @param array<int, string> $headers @param array<int, array<int, mixed>> $rows */
    private function excelUpload(string $name, array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headers, ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'rivo-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            $name,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    /** @return array<int, array<int, mixed>> */
    private function excelRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rivo-xlsx-');
        file_put_contents($path, $content);
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray('', false, false, false);
        $spreadsheet->disconnectWorksheets();
        unlink($path);

        return $rows;
    }
}
