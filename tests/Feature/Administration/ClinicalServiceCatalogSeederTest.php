<?php

namespace Tests\Feature\Administration;

use App\Enums\CatalogItemType;
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

        $this->assertDatabaseCount('catalog_items', 16);
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
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'ECHO-ABD',
            'name' => 'Échographie abdominale',
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

        $this->assertDatabaseCount('catalog_items', 16);
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

        $this->assertDatabaseCount('catalog_items', 16);
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
