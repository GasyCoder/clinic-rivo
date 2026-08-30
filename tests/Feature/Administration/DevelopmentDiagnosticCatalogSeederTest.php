<?php

namespace Tests\Feature\Administration;

use App\Models\DiagnosticCatalog;
use Database\Seeders\DevelopmentDiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DevelopmentDiagnosticCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_local_clinic_catalog_idempotently_without_overwriting_existing_records(): void
    {
        config(['rivo.site.type' => 'clinic']);
        DiagnosticCatalog::query()->create([
            'name' => 'Pneumonie',
            'category' => 'Catégorie validée localement',
            'is_active' => false,
        ]);

        $this->seed(DevelopmentDiagnosticCatalogSeeder::class);
        $this->seed(DevelopmentDiagnosticCatalogSeeder::class);

        $this->assertDatabaseCount('diagnostic_catalogs', count(DevelopmentDiagnosticCatalogSeeder::DIAGNOSTICS));
        $this->assertDatabaseHas('diagnostic_catalogs', [
            'name' => 'Pneumonie',
            'category' => 'Catégorie validée localement',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('diagnostic_catalogs', [
            'name' => 'Appendicite aiguë',
            'category' => 'Gastro-entérologie',
            'is_active' => true,
            'code' => null,
        ]);
    }

    public function test_it_is_refused_on_the_central_admin_deployment(): void
    {
        config(['rivo.site.type' => 'admin']);
        $this->expectException(LogicException::class);

        $this->seed(DevelopmentDiagnosticCatalogSeeder::class);
    }
}
