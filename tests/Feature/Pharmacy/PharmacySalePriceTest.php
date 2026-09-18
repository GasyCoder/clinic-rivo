<?php

namespace Tests\Feature\Pharmacy;

use App\Actions\Catalog\SetCatalogTariffAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\MedicineForm;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\Role;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-112 — the pharmacy sets the sale price of its medicines, and nothing
 * else: never a service price, never the Mutual grid.
 */
class PharmacySalePriceTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
    }

    private function item(CatalogItemType $type, CatalogModule $module, string $code): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => "Élément {$code}",
            'type' => $type,
            'module' => $module,
            'unit' => 'unité',
            'billable' => true,
            'stockable' => $type === CatalogItemType::Medicine,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    private function medicine(): Medicine
    {
        return Medicine::query()->create([
            'catalog_item_id' => $this->item(CatalogItemType::Medicine, CatalogModule::Pharmacy, 'PH-0001')->id,
            'form' => MedicineForm::Other,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    public function test_the_pharmacy_sets_then_changes_a_sale_price_keeping_the_history(): void
    {
        $medicine = $this->medicine();

        $this->actingAs($this->pharmacist)
            ->put("/pharmacy/medicines/{$medicine->uuid}/sale-price", ['sale_price' => '1500'])
            ->assertSessionHasNoErrors();
        $this->assertSame('1500.00', $medicine->catalogItem->fresh()->currentStandardTariff->amount);

        // A change needs a reason: the previous price stays in the history.
        $this->actingAs($this->pharmacist)
            ->put("/pharmacy/medicines/{$medicine->uuid}/sale-price", ['sale_price' => '1800'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->pharmacist)
            ->put("/pharmacy/medicines/{$medicine->uuid}/sale-price", ['sale_price' => '1800', 'reason' => 'Hausse fournisseur'])
            ->assertSessionHasNoErrors();

        $item = $medicine->catalogItem->fresh();
        $this->assertSame('1800.00', $item->currentStandardTariff->amount);
        $this->assertSame(2, $item->tariffs()->count());
    }

    public function test_the_permission_never_reaches_a_service_price_or_the_mutual_grid(): void
    {
        $actor = CatalogActor::fromUser($this->pharmacist);
        $action = app(SetCatalogTariffAction::class);

        $consultation = $this->item(CatalogItemType::Service, CatalogModule::Medicine, 'CONS-01');

        try {
            $action->execute($consultation, CatalogTariffCategory::Standard, '20000', 'Essai', $actor);
            $this->fail('Une consultation ne doit pas être tarifée par la Pharmacie.');
        } catch (AuthorizationException) {
        }

        try {
            $action->execute($this->medicine()->catalogItem, CatalogTariffCategory::Mutual, '1500', 'Essai', $actor);
            $this->fail('La grille Mutuelle reste réservée.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, $consultation->tariffs()->count());
    }

    public function test_the_sale_price_can_be_set_while_recording_a_stock_entry(): void
    {
        $medicine = $this->medicine();
        $entry = fn (array $extra) => [
            'origin' => 'Fournisseur', 'destination' => 'Stock pharmacie', 'reason' => 'Livraison du jour',
            'entries' => [[
                'medicine_uuid' => $medicine->uuid, 'operation' => 'ENTREE',
                'lot_number' => 'LOT-'.uniqid(), 'expires_at' => now()->addYear()->toDateString(),
                'quantity' => 10, ...$extra,
            ]],
        ];

        $this->actingAs($this->pharmacist)->post('/pharmacy/stock/entries/batch', $entry(['sale_price' => '1200']))
            ->assertSessionHasNoErrors();
        $this->assertSame('1200.00', $medicine->catalogItem->fresh()->currentStandardTariff->amount);

        // Same price again: nothing rewritten.
        $this->actingAs($this->pharmacist)->post('/pharmacy/stock/entries/batch', $entry(['sale_price' => '1200']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $medicine->catalogItem->tariffs()->count());

        // The purchase price stays out of reach of the pharmacy.
        $this->actingAs($this->pharmacist)->post('/pharmacy/stock/entries/batch', $entry(['unit_purchase_price' => '800']))
            ->assertForbidden();
    }

    public function test_the_pharmacy_can_give_a_product_its_own_sale_name_at_stock_entry(): void
    {
        $medicine = $this->medicine();

        $this->actingAs($this->pharmacist)->post('/pharmacy/stock/entries/batch', [
            'origin' => 'Fournisseur', 'destination' => 'Stock pharmacie', 'reason' => 'Livraison du jour',
            'entries' => [[
                'medicine_uuid' => $medicine->uuid, 'operation' => 'ENTREE', 'lot_number' => 'LOT-N1',
                'expires_at' => now()->addYear()->toDateString(), 'quantity' => 5,
                'sale_name' => 'Paracétamol 500 mg boîte de 20',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Paracétamol 500 mg boîte de 20', $medicine->catalogItem->fresh()->name);
    }

    public function test_an_account_without_the_permission_is_refused(): void
    {
        $nurse = User::factory()->create(['role_id' => Role::query()->where('code', 'NURSE')->value('id')]);

        $this->actingAs($nurse)
            ->put("/pharmacy/medicines/{$this->medicine()->uuid}/sale-price", ['sale_price' => '1500'])
            ->assertForbidden();
    }
}
