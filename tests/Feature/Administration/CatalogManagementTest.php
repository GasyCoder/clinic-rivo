<?php

namespace Tests\Feature\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode = 'SUPER_ADMIN'): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    /** @return array<string, mixed> */
    private function servicePayload(array $overrides = []): array
    {
        return array_replace([
            'code' => 'CONSULT-GEN',
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service->value,
            'module' => CatalogModule::Medicine->value,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'description' => 'Consultation médicale générale',
            'tariff_amount' => '15000.00',
            'tariff_reason' => 'Tarif initial validé',
        ], $overrides);
    }

    public function test_only_super_admin_receives_catalog_permissions_by_default(): void
    {
        $superAdmin = $this->user();

        $this->actingAs($superAdmin)->get('/administration/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Administration/Catalog/Index'));

        foreach (['ADMINISTRATION', 'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'] as $roleCode) {
            $this->actingAs($this->user($roleCode))
                ->get('/administration/catalog')
                ->assertForbidden();
        }
    }

    public function test_super_admin_creates_a_service_with_an_audited_initial_tariff(): void
    {
        $actor = $this->user();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())
            ->assertRedirect()
            ->assertSessionHas('status');

        $item = CatalogItem::query()->sole();
        $tariff = CatalogTariff::query()->sole();

        $this->assertNotNull($item->uuid);
        $this->assertSame(CatalogItemType::Service, $item->type);
        $this->assertTrue($item->billable);
        $this->assertFalse($item->stockable);
        $this->assertSame('15000.00', $tariff->amount);
        $this->assertSame('CURRENT', $tariff->active_key);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'entity_id' => $item->id,
            'entity_type' => CatalogItem::class,
            'user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'entity_id' => $tariff->id,
            'entity_type' => CatalogTariff::class,
            'user_id' => $actor->id,
        ]);
    }

    public function test_catalog_type_rules_are_enforced_by_the_domain_action(): void
    {
        $actor = $this->user();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'billable' => false,
            'tariff_amount' => null,
            'tariff_reason' => null,
        ]))->assertSessionHasErrors('billable');

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'code' => 'MED-PARA',
            'name' => 'Paracétamol',
            'type' => CatalogItemType::Medicine->value,
            'module' => CatalogModule::Pharmacy->value,
            'stockable' => false,
        ]))->assertSessionHasErrors('stockable');

        $this->assertDatabaseCount('catalog_items', 0);
    }

    public function test_tariff_change_closes_the_old_version_and_never_overwrites_it(): void
    {
        $actor = $this->user();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())->assertRedirect();
        $item = CatalogItem::query()->sole();
        $oldTariff = CatalogTariff::query()->sole();

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff", [
            'tariff_amount' => '17500.00',
            'reason' => 'Révision annuelle validée',
        ])->assertRedirect();

        $oldTariff->refresh();
        $current = $item->fresh()->currentTariff()->sole();

        $this->assertSame('15000.00', $oldTariff->amount);
        $this->assertNull($oldTariff->active_key);
        $this->assertNotNull($oldTariff->effective_until);
        $this->assertSame('17500.00', $current->amount);
        $this->assertSame('CURRENT', $current->active_key);
        $this->assertDatabaseCount('catalog_tariffs', 2);
    }

    public function test_tariff_and_item_can_be_suspended_archived_and_restored_with_reasons(): void
    {
        $actor = $this->user();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())->assertRedirect();
        $item = CatalogItem::query()->sole();

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff/archive", [
            'reason' => 'Tarif en cours de validation',
        ])->assertRedirect();

        $this->assertNull($item->fresh()->currentTariff()->first());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.tariff.archive',
            'module' => 'catalog',
            'reason' => 'Tarif en cours de validation',
        ]);

        $this->actingAs($actor)->delete("/administration/catalog/{$item->uuid}", [
            'reason' => 'Prestation retirée du catalogue',
        ])->assertRedirect();

        $this->assertSoftDeleted('catalog_items', [
            'id' => $item->id,
            'deleted_by' => $actor->id,
            'delete_reason' => 'Prestation retirée du catalogue',
        ]);

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/restore")
            ->assertRedirect();

        $this->assertDatabaseHas('catalog_items', [
            'id' => $item->id,
            'deleted_at' => null,
            'delete_reason' => null,
        ]);
    }

    public function test_item_view_permission_does_not_expose_tariff_data_without_tariff_view(): void
    {
        $superAdmin = $this->user();
        $this->actingAs($superAdmin)->post('/administration/catalog', $this->servicePayload())->assertRedirect();

        $role = Role::query()->create(['code' => 'CATALOG_VIEWER', 'name' => 'Lecteur catalogue']);
        $role->permissions()->attach(Permission::query()->where('name', 'catalog.items.view')->value('id'));
        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get('/administration/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.data.0.current_tariff', null)
                ->where('items.data.0.tariffs_count', null)
                ->has('items.data.0.tariffs', 0)
                ->where('summary.without_tariff', null));
    }

    public function test_catalog_routes_use_uuid_not_local_numeric_ids(): void
    {
        $actor = $this->user();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())->assertRedirect();
        $item = CatalogItem::query()->sole();

        $this->actingAs($actor)->put("/administration/catalog/{$item->id}", [
            'name' => 'Tentative par ID local',
            'module' => CatalogModule::Medicine->value,
            'unit' => 'acte',
        ])->assertNotFound();
    }
}
