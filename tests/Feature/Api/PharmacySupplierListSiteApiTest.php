<?php

namespace Tests\Feature\Api;

use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-098 — supplier list managed from the portal: Excel import previewed
 * then written all-or-nothing, correction, archive and restore.
 */
class PharmacySupplierListSiteApiTest extends TestCase
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

    private function existingSuppliers(): void
    {
        MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis', 'contact_name' => 'Mme Rasoa', 'phone' => '034 00 000 00']);
        $archived = MedicineSupplier::query()->create(['code' => 'ANCIEN', 'name' => 'Ancien fournisseur']);
        $archived->delete_reason = 'Plus actif';
        $archived->delete();
    }

    public function test_the_preview_says_what_each_line_would_do_without_writing(): void
    {
        $this->existingSuppliers();

        $response = $this->withHeaders($this->headers(['medicine_suppliers.import']))
            ->postJson('/api/v1/super-admin/pharmacy/suppliers/import-preview', ['rows' => [
                ['line' => 2, 'code' => 'pharmadis', 'name' => '', 'contact_name' => '', 'phone' => 341234567.0],
                ['line' => 3, 'code' => 'SOMAPHAR', 'name' => 'Somaphar'],
                ['line' => 4, 'code' => 'PHARMADIS', 'name' => 'Doublon'],
                ['line' => 5, 'code' => 'ANCIEN', 'name' => 'Ancien fournisseur'],
                ['line' => 6, 'code' => 'NOUVEAU', 'name' => '', 'email' => 'pas-un-email'],
                ['line' => 7, 'code' => 'PHARMADIS-2', 'name' => 'Pharmadis'],
            ]])
            ->assertOk()
            ->assertJsonPath('data.summary', ['create' => 2, 'update' => 1, 'unchanged' => 0, 'error' => 3]);

        $rows = collect($response->json('data.rows'))->keyBy('line');
        // An empty cell keeps the current value; a phone stored as a number keeps its digits.
        $this->assertSame('UPDATE', $rows[2]['action']);
        $this->assertSame([['field' => 'phone', 'label' => 'Téléphone', 'from' => '034 00 000 00', 'to' => '341234567']], $rows[2]['changes']);
        $this->assertSame('CREATE', $rows[3]['action']);
        $this->assertStringContainsString('ligne 2', $rows[4]['errors'][0]);
        $this->assertStringContainsString('archivé', $rows[5]['errors'][0]);
        $this->assertCount(2, $rows[6]['errors']);

        $this->assertSame(2, MedicineSupplier::withTrashed()->count());
        $this->assertSame('034 00 000 00', MedicineSupplier::query()->where('code', 'PHARMADIS')->value('phone'));
    }

    public function test_the_import_writes_every_line_or_none(): void
    {
        $this->existingSuppliers();

        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->postJson('/api/v1/super-admin/pharmacy/suppliers/import', ['rows' => [['code' => 'SOMAPHAR', 'name' => 'Somaphar']]])
            ->assertForbidden();

        $this->withHeaders($this->headers(['medicine_suppliers.import']))
            ->postJson('/api/v1/super-admin/pharmacy/suppliers/import', ['rows' => [
                ['line' => 2, 'code' => 'SOMAPHAR', 'name' => 'Somaphar'],
                ['line' => 3, 'code' => 'ANCIEN', 'name' => 'Ancien fournisseur'],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rows');
        $this->assertFalse(MedicineSupplier::query()->where('code', 'SOMAPHAR')->exists());

        $this->withHeaders($this->headers(['medicine_suppliers.import']))
            ->postJson('/api/v1/super-admin/pharmacy/suppliers/import', ['rows' => [
                ['line' => 2, 'code' => 'SOMAPHAR', 'name' => 'Somaphar', 'email' => 'contact@somaphar.mg'],
                ['line' => 3, 'code' => 'PHARMADIS', 'name' => '', 'contact_name' => '', 'phone' => '032 11 111 11'],
            ]])
            ->assertOk()
            ->assertJsonPath('data', ['created' => 1, 'updated' => 1, 'unchanged' => 0]);

        $pharmadis = MedicineSupplier::query()->where('code', 'PHARMADIS')->sole();
        $this->assertSame('Pharmadis', $pharmadis->name);
        $this->assertSame('Mme Rasoa', $pharmadis->contact_name);
        $this->assertSame('032 11 111 11', $pharmadis->phone);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => MedicineSupplier::class,
            'entity_id' => MedicineSupplier::query()->where('code', 'SOMAPHAR')->value('id'),
            'external_actor_uuid' => $this->actorUuid,
        ]);
    }

    public function test_a_supplier_is_corrected_without_changing_its_code(): void
    {
        $supplier = MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis', 'phone' => '034 00 000 00']);

        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->putJson("/api/v1/super-admin/pharmacy/suppliers/{$supplier->uuid}", ['name' => 'Pharmadis SA'])
            ->assertForbidden();

        $this->withHeaders($this->headers(['medicine_suppliers.update']))
            ->putJson("/api/v1/super-admin/pharmacy/suppliers/{$supplier->uuid}", ['code' => 'AUTRE', 'name' => 'Pharmadis SA', 'phone' => ''])
            ->assertOk()
            ->assertJsonPath('data.code', 'PHARMADIS')
            ->assertJsonPath('data.name', 'Pharmadis SA');

        $this->assertNull($supplier->fresh()->phone);
    }

    public function test_the_folder_and_its_sub_folders_are_read_with_the_supplier_or_their_own_permission(): void
    {
        $this->seed(RoleSeeder::class);
        $pharmacist = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $supplier = MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis']);
        PurchaseOrder::query()->create([
            'order_number' => 'BC-000001',
            'medicine_supplier_id' => $supplier->id,
            'status' => 'ORDERED',
            'total_amount' => '15000.00',
            'created_by' => $pharmacist->id,
            'updated_by' => $pharmacist->id,
        ]);
        $base = "/api/v1/super-admin/pharmacy/suppliers/{$supplier->uuid}";

        $this->withHeaders($this->headers([]))->getJson($base)->assertForbidden();
        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.supplier.code', 'PHARMADIS')
            ->assertJsonPath('data.counts.orders', 1)
            ->assertJsonPath('data.counts.open_orders', 1)
            ->assertJsonPath('data.counts.invoices', 0);

        // ADR-098 — « Voir les fournisseurs » opens every sub-folder read-only.
        $this->withHeaders($this->headers(['medicine_suppliers.view']))->getJson("{$base}/orders")->assertOk();
        $this->withHeaders($this->headers(['stock.view']))->getJson("{$base}/orders")->assertForbidden();
        $this->withHeaders($this->headers(['purchase_orders.view']))
            ->getJson("{$base}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.order_number', 'BC-000001')
            ->assertJsonPath('data.orders.0.status', 'ORDERED');

        $this->withHeaders($this->headers(['medicine_suppliers.view']))->getJson("{$base}/invoices")->assertOk();
        $this->withHeaders($this->headers(['supplier_invoices.view']))
            ->getJson("{$base}/invoices")
            ->assertOk()
            ->assertJsonCount(0, 'data.invoices');

        $this->withHeaders($this->headers(['medicine_suppliers.view']))->getJson("{$base}/products")->assertOk();
        $this->withHeaders($this->headers(['medicine_supplier_offers.view']))
            ->getJson("{$base}/products")
            ->assertOk()
            ->assertJsonPath('data.offers', [])
            ->assertJsonPath('data.history', []);
    }

    public function test_archiving_waits_for_open_orders_and_can_be_restored(): void
    {
        $this->seed(RoleSeeder::class);
        $pharmacist = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $supplier = MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis']);
        $order = PurchaseOrder::query()->create([
            'order_number' => 'BC-000001',
            'medicine_supplier_id' => $supplier->id,
            'status' => 'ORDERED',
            'created_by' => $pharmacist->id,
            'updated_by' => $pharmacist->id,
        ]);
        $url = "/api/v1/super-admin/pharmacy/suppliers/{$supplier->uuid}";

        $this->withHeaders($this->headers(['medicine_suppliers.delete']))
            ->deleteJson($url, ['reason' => 'Ne livre plus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->assertNull($supplier->fresh()->deleted_at);

        $order->update(['status' => 'RECEIVED']);

        $this->withHeaders($this->headers(['medicine_suppliers.delete']))
            ->deleteJson($url, ['reason' => 'Ne livre plus'])
            ->assertOk();
        $this->assertSoftDeleted('medicine_suppliers', ['id' => $supplier->id, 'delete_reason' => 'Ne livre plus']);

        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson('/api/v1/super-admin/pharmacy/suppliers?status=ARCHIVED')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'PHARMADIS')
            ->assertJsonPath('data.0.archived', true);
        $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson('/api/v1/super-admin/pharmacy/suppliers')
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->headers(['medicine_suppliers.restore']))
            ->postJson("{$url}/restore")
            ->assertOk();
        $this->assertNull($supplier->fresh()->deleted_at);
    }
}
