<?php

namespace Tests\Feature\Pharmacy;

use App\Models\CatalogItem;
use App\Models\MedicineLot;
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

        // 18 historiques + 20 pour l'hospitalisation, la Maternité et les Soins (ADR-163).
        $this->assertDatabaseCount('medicines', 38);
        $this->assertDatabaseCount('medicine_categories', 10);
        $this->assertDatabaseCount('medicine_suppliers', 3);
        $this->assertDatabaseCount('catalog_tariffs', 38);
        $this->assertDatabaseCount('medicine_lots', 38);
        $this->assertDatabaseCount('pharmacy_stock_movements', 38);
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

        // ADR-104 — le catalogue vendable se lit désormais depuis le rayon
        // Pharmacie du panier de la Réception : le médicament en rupture
        // reste exclu, comme sur l'écran comptoir qu'il remplace.
        $receptionist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'RECEPTION')->value('id'),
        ]);

        $this->actingAs($receptionist)
            ->get('/reception/patients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('capabilities.can_sell_medicines', true)
                ->has('pharmacyCatalog', 37));
    }

    /**
     * ADR-163 — relancer le seeder complète ce qui manque et n'écrase rien :
     * ni un prix changé, ni un stock entamé, ni un médicament archivé.
     */
    public function test_re_seeding_never_overwrites_what_the_pharmacy_changed(): void
    {
        $this->seed(DevelopmentMedicineStockSeeder::class);

        $item = CatalogItem::query()->where('code', 'DEV-PARA-500')->sole();
        $item->tariffs()->where('active_key', 'CURRENT')->update(['amount' => '999.00']);
        $lot = MedicineLot::query()->where('lot_number', 'DEV-PARA-500-LOT-01')->sole();
        $lot->update(['quantity_on_hand' => 3]);
        $archived = CatalogItem::query()->where('code', 'DEV-IBU-400')->sole();
        $archived->delete();

        $this->seed(DevelopmentMedicineStockSeeder::class);

        $this->assertSame('999.00', $item->tariffs()->where('active_key', 'CURRENT')->sole()->amount);
        $this->assertSame(3, $lot->fresh()->quantity_on_hand);
        $this->assertTrue(CatalogItem::withTrashed()->findOrFail($archived->id)->trashed());
        $this->assertDatabaseCount('pharmacy_stock_movements', 38);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $this->expectException(LogicException::class);
        $this->seed(DevelopmentMedicineStockSeeder::class);
    }
}
