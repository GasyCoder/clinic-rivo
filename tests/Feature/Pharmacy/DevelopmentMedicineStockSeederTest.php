<?php

namespace Tests\Feature\Pharmacy;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentMedicineStockSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DevelopmentMedicineStockSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'M',
            'rivo.site.name' => 'Mampikony',
        ]);
        $this->app['env'] = 'testing';

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
    }

    public function test_it_creates_a_rich_idempotent_pharmacy_demo_catalog(): void
    {
        $this->seed(DevelopmentMedicineStockSeeder::class);
        $this->seed(DevelopmentMedicineStockSeeder::class);

        $this->assertDatabaseCount('medicines', 18);
        $this->assertDatabaseCount('medicine_categories', 8);
        $this->assertDatabaseCount('medicine_suppliers', 3);
        $this->assertDatabaseCount('catalog_tariffs', 18);
        $this->assertDatabaseCount('medicine_lots', 18);
        $this->assertDatabaseCount('pharmacy_stock_movements', 18);
        $this->assertDatabaseCount('pharmacy_stock_alerts', 4);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);

        $this->assertDatabaseHas('catalog_items', [
            'code' => 'DEV-PARA-500',
            'billable' => true,
            'stockable' => true,
        ]);
        $this->assertDatabaseHas('pharmacy_stock_alerts', [
            'type' => 'OUT_OF_STOCK',
            'available_quantity' => 0,
            'active_key' => 'OPEN',
        ]);

        $pharmacist = User::query()->sole();
        $this->actingAs($pharmacist)
            ->get('/pharmacy/counter-sales/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/CounterSales/Create')
                ->has('medicines', 17));
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $this->expectException(LogicException::class);
        $this->seed(DevelopmentMedicineStockSeeder::class);
    }
}
