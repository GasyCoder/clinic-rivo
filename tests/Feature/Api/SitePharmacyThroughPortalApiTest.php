<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-189 — le Super Admin du portail voit toute la Pharmacie d'un site et en
 * gère l'administratif, par l'API du site : mêmes routes, droits et actions que
 * /pharmacy. Les actes physiques restent au site (ADR-098).
 */
class SitePharmacyThroughPortalApiTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_UUID = '6d3f4a8e-1c2b-4d5e-9f60-7a8b9c0d1e2f';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_portal_reads_the_pharmacy_screens_of_the_site_as_data(): void
    {
        $this->withHeaders($this->headers(['stock.view', 'medicines.view']))
            ->getJson('/api/v1/super-admin/site-pharmacy/stock')
            ->assertOk()
            ->assertJsonPath('component', 'Pharmacy/Stock/Index')
            ->assertJsonMissingPath('props.auth');

        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson('/api/v1/super-admin/site-pharmacy/suppliers')
            ->assertOk()
            ->assertJsonPath('component', 'Pharmacy/Suppliers/Index');
    }

    public function test_each_screen_keeps_its_own_permission(): void
    {
        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson('/api/v1/super-admin/site-pharmacy/stock')
            ->assertForbidden();

        $this->withHeaders([...$this->headers(['stock.view']), 'Authorization' => 'Bearer wrong'])
            ->getJson('/api/v1/super-admin/site-pharmacy/stock')
            ->assertUnauthorized();
    }

    /** Délivrer, servir, ranger, compter, ajuster, réceptionner : au site, même avec le droit. */
    public function test_physical_acts_stay_at_the_site_even_with_the_permission(): void
    {
        $all = ['stock.view', 'stock.entry', 'stock.adjust', 'stock.lots.view', 'goods_receipts.create'];

        foreach (['/stock/entries/create', '/stock/inventory', '/stock/adjustments/create'] as $path) {
            $this->withHeaders($this->headers($all))
                ->getJson('/api/v1/super-admin/site-pharmacy'.$path)
                ->assertForbidden()
                ->assertJsonPath('message', 'Ce geste se fait à la Pharmacie du site, par la personne qui a les produits en main : le portail le consulte, il ne le fait pas.');
        }

        $this->withHeaders($this->writeHeaders($all))
            ->postJson('/api/v1/super-admin/site-pharmacy/stock/inventory', ['reason' => 'Comptage', 'counts' => []])
            ->assertForbidden();
    }

    public function test_the_same_physical_screen_stays_open_to_the_site_pharmacist(): void
    {
        $pharmacist = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);

        $this->actingAs($pharmacist)->get('/pharmacy/stock/entries/create')->assertOk();
    }

    public function test_an_administrative_write_runs_the_site_action_and_is_signed_by_the_super_admin(): void
    {
        $users = User::query()->count();

        $response = $this->withHeaders($this->writeHeaders(['medicine_suppliers.view', 'medicine_suppliers.create']))
            ->postJson('/api/v1/super-admin/site-pharmacy/setup/suppliers', ['code' => 'ABC', 'name' => 'Arbiochem'])
            ->assertOk();

        $supplier = MedicineSupplier::query()->where('code', 'ABC')->sole();
        $response->assertJsonPath('redirect', '/pharmacy/suppliers/'.$supplier->uuid);

        $audit = AuditLog::query()->where('entity_type', $supplier->getMorphClass())->where('entity_id', $supplier->id)->firstOrFail();
        $this->assertNull($audit->user_id);
        $this->assertSame(self::ACTOR_UUID, $audit->external_actor_uuid);
        $this->assertSame('Direction centrale', $audit->external_actor_name);

        $this->assertSame($users, User::query()->count(), 'le Super Admin distant n’est jamais enregistré sur le site');
    }

    public function test_a_medicine_added_from_the_portal_keeps_its_remote_author(): void
    {
        $this->withHeaders($this->writeHeaders(['stock.view', 'medicines.view', 'medicines.create', 'catalog.items.create', 'catalog.tariffs.create']))
            ->postJson('/api/v1/super-admin/site-pharmacy/setup/medicines', [
                'code' => 'PARA-500',
                'name' => 'Paracétamol 500 mg',
                'generic_name' => 'Paracétamol',
                'form' => 'TABLET',
                'strength' => '500 mg',
                'unit' => 'comprimé',
                'minimum_stock' => 10,
                'prescription_required' => false,
                'sale_price' => 200,
                'tariff_reason' => 'Premier prix',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', '/pharmacy/medicines');

        $medicine = Medicine::query()->whereHas('catalogItem', fn ($query) => $query->where('code', 'PARA-500'))->sole();
        $this->assertNull($medicine->created_by);
        $this->assertSame(self::ACTOR_UUID, $medicine->external_created_by_uuid);
        $this->assertSame('Direction centrale', $medicine->external_created_by_name);
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => self::ACTOR_UUID,
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }

    /** @param array<int, string> $permissions */
    private function writeHeaders(array $permissions): array
    {
        return [...$this->headers($permissions), 'Idempotency-Key' => (string) Str::uuid()];
    }
}
