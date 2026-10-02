<?php

namespace Tests\Feature\Administration;

use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private function asProduction(string $siteType): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production', 'rivo.site.type' => $siteType, 'rivo.site.code' => 'A', 'rivo.site.name' => 'Ambondromamy']);
    }

    /** Called directly: `db:seed` asks for confirmation in production. */
    private function runSeeder(): void
    {
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
    }

    public function test_a_production_clinic_site_gets_its_reference_lists_without_accounts_or_prices(): void
    {
        $this->asProduction('clinic');

        $this->runSeeder();

        $this->assertGreaterThan(700, AnalysisCatalog::query()->count());
        $this->assertGreaterThan(300, CatalogItem::query()->count());
        $this->assertSame(0, CatalogTariff::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertNull(AnalysisCatalog::query()->value('created_by'));
    }

    public function test_seeding_twice_adds_nothing(): void
    {
        $this->asProduction('clinic');
        $this->runSeeder();
        $analyses = AnalysisCatalog::query()->count();
        $items = CatalogItem::query()->count();

        $this->runSeeder();

        $this->assertSame($analyses, AnalysisCatalog::query()->count());
        $this->assertSame($items, CatalogItem::query()->count());
    }

    public function test_the_portal_gets_no_clinic_catalogue(): void
    {
        $this->asProduction('admin');

        $this->runSeeder();

        $this->assertSame(0, AnalysisCatalog::query()->count());
        $this->assertSame(0, CatalogItem::query()->count());
    }
}
