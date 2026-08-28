<?php

namespace Tests\Feature\Reception;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\User;
use App\Services\Reception\ReceptionEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionEstimateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_estimate_needs_no_patient_or_episode_and_recalculates_a_forged_browser_price(): void
    {
        $actor = User::factory()->create();
        $service = CatalogItem::query()->create([
            'code' => 'ECHO-ESTIMATE',
            'name' => 'Échographie estimation',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'examen',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        CatalogTariff::query()->create([
            'catalog_item_id' => $service->id,
            'tariff_category' => CatalogTariffCategory::Standard,
            'amount' => '25000.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Test estimation',
            'created_by' => $actor->id,
        ]);

        $before = collect([
            'patients', 'episodes', 'episode_service_requests', 'episode_orientations',
            'billable_items', 'invoices', 'payments',
        ])->mapWithKeys(fn (string $table) => [$table => \DB::table($table)->count()]);

        $serviceUnderTest = app(ReceptionEstimateService::class);
        $catalog = $serviceUnderTest->catalog();
        $estimate = $serviceUnderTest->estimate([[
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 2,
            'unit_price' => '1.00',
            'total_amount' => '2.00',
        ]]);

        $this->assertCount(1, $catalog);
        $this->assertSame('25000.00', $catalog->sole()['unit_price']);
        $this->assertSame(CatalogTariffCategory::Standard->value, $estimate['tariff_category']);
        $this->assertSame('25000.00', $estimate['lines'][0]['unit_price']);
        $this->assertSame('50000.00', $estimate['lines'][0]['line_total']);
        $this->assertSame('50000.00', $estimate['total_amount']);

        foreach ($before as $table => $count) {
            $this->assertSame($count, \DB::table($table)->count(), "La table {$table} ne doit pas être modifiée.");
        }
    }
}
