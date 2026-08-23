<?php

namespace Tests\Feature\Administration;

use App\Enums\CatalogItemType;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalServiceCatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClinicalServiceCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_it_creates_reception_services_with_active_tariffs_and_a_real_audit_actor(): void
    {
        $actor = $this->catalogManager();
        config(['rivo.seeders.catalog_actor' => $actor->uuid]);

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $this->assertDatabaseCount('catalog_items', 30);
        $this->assertDatabaseCount('catalog_tariffs', 16);
        $this->assertSame(
            16,
            CatalogItem::query()
                ->where('type', CatalogItemType::Service->value)
                ->where('billable', true)
                ->where('stockable', false)
                ->whereHas('currentTariff')
                ->count(),
        );
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'ECG',
            'name' => 'Électrocardiogramme (ECG)',
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'ECHO-ABD',
            'name' => 'Échographie abdominale',
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'CONSULT-GEN',
            'reception_routing_mode' => ReceptionRoutingMode::CareThenMedicine->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'INJECTION-IM',
            'reception_routing_mode' => ReceptionRoutingMode::CareOnly->value,
            'care_requires_allergy_check' => true,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'INJECTION-IV',
            'care_requires_allergy_check' => true,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'PERFUSION',
            'care_requires_allergy_check' => true,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'PANSEMENT-C',
            'care_requires_allergy_check' => false,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'LAB-NFS',
            'reception_selectable' => false,
            'reception_routing_mode' => null,
        ]);
        $this->assertSame(10, CatalogItem::query()->where('reception_selectable', true)->count());
        $this->assertSame(18, CatalogItem::query()->where('module', 'CARE')->count());
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'CARE-ABL-SONDE',
            'name' => 'Ablation sonde',
            'reception_selectable' => false,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'CARE-OTHER',
            'name' => 'Autres',
            'billable' => false,
        ]);
        $this->assertDatabaseMissing('catalog_tariffs', [
            'catalog_item_id' => CatalogItem::query()->where('code', 'CARE-ABL-SONDE')->value('id'),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'user_id' => $actor->id,
            'entity_type' => CatalogItem::class,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'user_id' => $actor->id,
            'entity_type' => CatalogTariff::class,
        ]);
    }

    public function test_it_is_idempotent_and_preserves_a_manually_changed_tariff(): void
    {
        $actor = $this->catalogManager();
        config(['rivo.seeders.catalog_actor' => $actor->email]);

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $ecg = CatalogItem::query()->where('code', 'ECG')->firstOrFail();
        $ecg->currentTariff()->update(['amount' => '27500.00']);

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $this->assertDatabaseCount('catalog_items', 30);
        $this->assertDatabaseCount('catalog_tariffs', 16);
        $this->assertSame('27500.00', $ecg->fresh()->currentTariff->amount);
    }

    public function test_an_explicit_provisioning_actor_gets_no_catalog_permission(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->firstOrFail();
        $actor = User::factory()->create(['role_id' => $role->id]);
        config(['rivo.seeders.catalog_actor' => $actor->uuid]);

        $this->assertFalse($actor->hasPermissionTo('catalog.items.create'));
        $this->assertFalse($actor->hasPermissionTo('catalog.tariffs.create'));

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $this->assertDatabaseCount('catalog_items', 30);
        $this->assertFalse($actor->fresh()->hasPermissionTo('catalog.items.create'));
        $this->assertFalse($actor->fresh()->hasPermissionTo('catalog.tariffs.create'));
    }

    public function test_it_refuses_to_create_demo_accounts_when_no_authorized_actor_exists(): void
    {
        try {
            $this->seed(ClinicalServiceCatalogSeeder::class);
            $this->fail('Le seeder aurait dû exiger un compte autorisé existant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Aucun compte actif ne peut créer le référentiel',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('users', 0);
    }

    private function catalogManager(): User
    {
        $role = Role::query()->where('code', 'ADMINISTRATION')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('name', [
                'catalog.items.create',
                'catalog.tariffs.create',
            ])->pluck('id'),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }
}
