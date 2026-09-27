<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-098 — the portal forwards every correction (medicine, family, order,
 * invoice, catalog note) to the site's API; the site decides and audits.
 */
class PharmacyRecordEditingPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private const API = 'https://a.test/api/v1/super-admin/pharmacy';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_a_medicine_correction_is_forwarded_to_the_site(): void
    {
        Http::fake([self::API.'/medicines/med-1' => Http::response(['message' => 'Médicament Amoxil mis à jour.', 'data' => ['uuid' => 'med-1']])]);

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/stock/A/medicines/med-1', ['name' => 'Amoxil', 'sale_price' => '5000', 'tariff_reason' => 'Nouveau tarif'])
            ->assertRedirect('/super-admin/stock')
            ->assertSessionHas('status', 'Médicament Amoxil mis à jour.');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === self::API.'/medicines/med-1'
            && $request['tariff_reason'] === 'Nouveau tarif'
            && $request->hasHeader('X-Rivo-Actor-UUID', $this->superAdmin->uuid));
    }

    public function test_a_catalog_file_content_is_listed_from_the_site(): void
    {
        Http::fake([self::API.'/suppliers/supplier-a/catalogs/cat-1/items' => Http::response(['data' => [
            'supplier' => ['uuid' => 'supplier-a', 'name' => 'Pharmadis'],
            'catalog' => ['uuid' => 'cat-1', 'original_name' => 'tarif.xlsx', 'kind' => 'EXCEL'],
            'items' => [
                ['uuid' => 'i-1', 'row_number' => 2, 'reference' => 'AMX', 'medicine_label' => 'Amoxicilline Gé', 'presentation' => 'B/12', 'supplier_price' => '450.00', 'linked_medicine_name' => 'Amoxil'],
            ],
        ]])]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/pharmacy-suppliers/A/supplier-a/catalogs/cat-1/items')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/PharmacySuppliers/CatalogItems')
                ->where('catalog.original_name', 'tarif.xlsx')
                ->where('items.0.medicine_label', 'Amoxicilline Gé')
                ->where('items.0.linked_medicine_name', 'Amoxil'));
    }

    public function test_a_refused_family_archive_shows_the_site_reason(): void
    {
        Http::fake([self::API.'/categories/cat-1' => Http::response([
            'message' => 'Refusé',
            'errors' => ['category' => ['2 médicament(s) actif(s) appartiennent encore à la famille.']],
        ], 422)]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/stock/A/categories')
            ->delete('/super-admin/stock/A/categories/cat-1', ['reason' => 'Regroupement'])
            ->assertRedirect('/super-admin/stock/A/categories')
            ->assertSessionHasErrors('category');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request['reason'] === 'Regroupement');
    }

    public function test_orders_invoices_and_catalog_notes_are_corrected_through_the_site(): void
    {
        $folder = self::API.'/suppliers/supplier-a';
        Http::fake([
            $folder.'/orders/order-1' => Http::response(['message' => 'Commande BC-000001 mise à jour.']),
            $folder.'/invoices/inv-1/update' => Http::response(['message' => 'Facture F-1 mise à jour.']),
            $folder.'/catalogs/cat-1' => Http::response(['message' => 'Catalogue mis à jour.']),
        ]);
        $portal = '/super-admin/pharmacy-suppliers/A/supplier-a';

        $this->actingAs($this->superAdmin)
            ->put($portal.'/orders/order-1', ['lines' => [['medicine_uuid' => 'm-1', 'quantity_ordered' => 3, 'unit_price' => '100']]])
            ->assertRedirect($portal.'/orders/order-1');

        $this->post($portal.'/invoices/inv-1/update', [
            'invoice_number' => 'F-1',
            'invoice_date' => '2026-09-15',
            'lines' => [['medicine_uuid' => 'm-1', 'description' => 'Amoxil', 'quantity' => 3, 'unit_price' => '100']],
        ])->assertRedirect($portal.'/invoices/inv-1');

        $this->from($portal.'/catalogs')
            ->patch($portal.'/catalogs/cat-1', ['notes' => 'Tarif de septembre'])
            ->assertRedirect($portal.'/catalogs');

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->url() === $folder.'/orders/order-1');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === $folder.'/invoices/inv-1/update');
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->url() === $folder.'/catalogs/cat-1' && $request['notes'] === 'Tarif de septembre');
    }
}
