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
use Tests\TestCase;

/**
 * ADR-098 — the portal manages supplier folders only through the selected
 * site's API; it never reads a clinic database, and a site that does not
 * answer never breaks the others.
 */
class PharmacySupplierPortalTest extends TestCase
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

    public function test_supplier_folders_are_read_per_site_without_failing_for_an_unconfigured_one(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/pharmacy/suppliers?status=ACTIVE' => Http::response([
                'data' => [['uuid' => 'supplier-m', 'code' => 'PHARMADIS', 'name' => 'Pharmadis', 'catalogs_count' => 2]],
            ], 200),
            'https://a.test/api/v1/super-admin/pharmacy/suppliers?status=ACTIVE' => Http::response(['data' => []], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/pharmacy-suppliers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/PharmacySuppliers/Index')
                ->has('sites', 3)
                ->where('selectedSite', 'M')
                ->where('sites.0.suppliers.0.name', 'Pharmadis')
                ->where('sites.2.status', 'UNCONFIGURED')
                ->where('can.create', true));
    }

    public function test_a_catalog_file_is_forwarded_to_the_selected_site_as_a_real_file(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/pharmacy/suppliers/supplier-a/catalogs' => Http::response([
                'message' => 'Catalogue ajouté au dossier du fournisseur.',
                'data' => ['uuid' => 'catalog-a'],
            ], 201),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/pharmacy-suppliers/A/supplier-a/catalogs', [
                'file' => UploadedFile::fake()->create('tarif.pdf', 120, 'application/pdf'),
                'notes' => 'Tarif spécial',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Catalogue ajouté au dossier du fournisseur.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://a.test/api/v1/super-admin/pharmacy/suppliers/supplier-a/catalogs'
            && $request->isMultipart()
            && $request->hasFile('file')
            && $request->hasHeader('Idempotency-Key')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'supplier_catalogs.create'));
    }

    public function test_a_refusal_from_the_site_is_shown_back_instead_of_being_hidden(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/pharmacy/suppliers/supplier-m/catalogs/catalog-m' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => ['reason' => ['Le motif est obligatoire.']],
            ], 422),
        ]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/pharmacy-suppliers/M/supplier-m')
            ->delete('/super-admin/pharmacy-suppliers/M/supplier-m/catalogs/catalog-m', ['reason' => 'Doublon'])
            ->assertRedirect('/super-admin/pharmacy-suppliers/M/supplier-m')
            ->assertSessionHasErrors(['reason' => 'Le motif est obligatoire.']);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request['reason'] === 'Doublon');
    }

    public function test_the_supplier_folder_shows_the_same_sub_folders_as_the_clinic(): void
    {
        $supplier = ['uuid' => 'supplier-m', 'code' => 'DEV-CENTRALE', 'name' => 'Centrale Pharmaceutique Démo', 'archived' => false];
        Http::fake([
            'https://m.test/api/v1/super-admin/pharmacy/suppliers/supplier-m' => Http::response(['data' => [
                'supplier' => $supplier,
                'counts' => ['catalogs' => 3, 'orders' => 3, 'open_orders' => 2, 'invoices' => 0, 'offers' => 7],
            ]]),
            'https://m.test/api/v1/super-admin/pharmacy/suppliers/supplier-m/orders' => Http::response(['data' => [
                'supplier' => $supplier,
                'orders' => [['uuid' => 'order-1', 'order_number' => 'BC-000002', 'status' => 'ORDERED', 'status_label' => 'Commandée', 'total_amount' => '1000.00']],
            ]]),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/pharmacy-suppliers/M/supplier-m')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/PharmacySuppliers/Show')
                ->where('supplier.name', 'Centrale Pharmaceutique Démo')
                ->where('counts.open_orders', 2)
                ->where('counts.offers', 7)
                ->where('can.view_catalogs', true)
                ->where('can.view_orders', true)
                ->where('can.view_invoices', true)
                ->where('can.view_offers', true));

        $this->actingAs($this->superAdmin)->get('/super-admin/pharmacy-suppliers/M/supplier-m/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/PharmacySuppliers/Orders')
                ->has('orders', 1)
                ->where('orders.0.order_number', 'BC-000002')
                ->where('error', null));
    }

    public function test_an_unknown_site_is_refused_before_any_api_call(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/pharmacy-suppliers/Z/supplier-z')->assertNotFound();

        Http::assertNothingSent();
    }
}
