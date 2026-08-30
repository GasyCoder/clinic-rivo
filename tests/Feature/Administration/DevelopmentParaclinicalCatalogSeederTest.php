<?php

namespace Tests\Feature\Administration;

use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentParaclinicalCatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevelopmentParaclinicalCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_imaging_services_and_structured_laboratory_definitions_idempotently(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $role = Role::query()->where('code', 'ADMINISTRATION')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->where('name', 'catalog.items.create')->pluck('id'),
        );
        $actor = User::factory()->create(['role_id' => $role->id]);
        config(['rivo.seeders.catalog_actor' => $actor->uuid]);

        $this->seed(DevelopmentParaclinicalCatalogSeeder::class);
        $this->seed(DevelopmentParaclinicalCatalogSeeder::class);

        $this->assertSame(20, CatalogItem::query()->where('module', 'IMAGING')->count());
        $this->assertSame(22, CatalogItem::query()->where('module', 'LABORATORY')->count());
        $this->assertSame(26, AnalysisCatalog::query()->count());
        $this->assertDatabaseHas('catalog_items', ['code' => 'ECG', 'module' => 'IMAGING']);
        $this->assertDatabaseHas('catalog_items', ['code' => 'ECHO-OBS-T1', 'module' => 'IMAGING']);
        $this->assertDatabaseHas('analysis_catalogs', [
            'code' => 'NFS-HB', 'reference_male' => '13–17', 'unit' => 'g/dL',
        ]);
        $this->assertDatabaseHas('analysis_catalogs', [
            'code' => 'GROUP-RH', 'result_type' => 'CHOICE',
        ]);
    }
}
