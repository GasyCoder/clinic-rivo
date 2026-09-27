<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\PharmacyStockMovementType;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Pharmacy\Concerns\ReceivesDeliveries;
use Tests\TestCase;

/**
 * ADR-182 — ce qui entre au stock vient d'une livraison réceptionnée, et de
 * rien d'autre.
 *
 * L'écran « Entrée en stock » ne déroule plus le catalogue de la pharmacie et
 * n'accepte plus aucune entrée libre : don, stock de départ et dépannage d'un
 * confrère n'ont plus de chemin local — décision du propriétaire du
 * 2026-09-23, qui revient sur l'ADR-179 §7 et l'ADR-180. Une ligne rangée
 * quitte l'écran et apparaît dans « Médicaments & stock » ; avant toute
 * réception, l'écran est vide.
 */
class StockEntryFromReceptionTest extends TestCase
{
    use RefreshDatabase, ReceivesDeliveries;

    private User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site.name' => 'Ambondromamy']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
    }

    public function test_before_any_reception_the_screen_is_empty_and_never_lists_the_catalogue(): void
    {
        $this->medicine('Paracétamol 500 mg');

        $this->actingAs($this->pharmacist)->get('/pharmacy/stock/entries/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pharmacy/Stock/Entries/Create')
                ->where('pending', [])
                ->missing('medicines')
                ->missing('suppliers'));
    }

    /**
     * Les deux écrans se suivent : ce qui est réceptionné attend dans
     * « Entrée en stock » ; une fois rangé, il en sort et devient du stock
     * dans « Médicaments & stock ».
     */
    public function test_a_received_line_waits_on_the_entry_screen_then_becomes_stock(): void
    {
        $medicine = $this->medicine('Paracétamol 500 mg');
        [$line] = $this->receiveDelivery([[$medicine, 10, 'PARA-01']]);

        $this->actingAs($this->pharmacist)->get('/pharmacy/stock/entries/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pending.0.orders.0.lines.0.uuid', $line->uuid)
                ->where('pending.0.orders.0.lines.0.is_new', true));
        $this->actingAs($this->pharmacist)->get('/pharmacy/stock')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('awaitingStockCount', 1)
                ->where('stock.medicines.0.status', 'NEVER_RECEIVED'));

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', ['lines' => [$this->stockLine($line)]])
            ->assertRedirect('/pharmacy/stock/entries/create')
            ->assertSessionHasNoErrors();

        $this->actingAs($this->pharmacist)->get('/pharmacy/stock/entries/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pending', []));
        $this->actingAs($this->pharmacist)->get('/pharmacy/stock')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('awaitingStockCount', 0)
                ->where('stock.medicines.0.status', 'AVAILABLE')
                ->where('stock.medicines.0.quantity_on_hand', 10));
        $this->assertSame(PharmacyStockMovementType::Entry, PharmacyStockMovement::query()->sole()->type);
    }

    public function test_nothing_enters_the_stock_outside_a_received_delivery(): void
    {
        $medicine = $this->medicine('Compresses stériles');
        $freeEntry = [
            'medicine_uuid' => $medicine->uuid,
            'operation' => 'STOCK_INITIAL',
            'lot_number' => 'DON-01',
            'expires_at' => now()->addYear()->toDateString(),
            'quantity' => 6,
        ];

        // L'ancienne entrée unitaire n'existe plus.
        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries', [...$freeEntry, 'origin' => 'Don', 'destination' => 'Stock', 'reason' => 'Don'])
            ->assertStatus(405);

        // L'envoi groupé refuse, en les nommant, les champs de l'entrée sans commande.
        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', [
                'origin' => 'Don de l’hôpital de district',
                'entries' => [$freeEntry],
            ])
            ->assertSessionHasErrors(['entries', 'origin', 'lines']);

        $this->assertSame(0, MedicineLot::query()->count());
        $this->assertSame(0, PharmacyStockMovement::query()->count());
    }

    public function test_a_refused_line_lets_no_other_line_of_the_delivery_in(): void
    {
        $paracetamol = $this->medicine('Paracétamol 500 mg');
        $amoxicillin = $this->medicine('Amoxicilline 500 mg');
        $this->lot($amoxicillin, 5, 'AMX-01');
        [$first, $second] = $this->receiveDelivery([[$paracetamol, 10, 'PARA-01'], [$amoxicillin, 4, 'AMX-01']]);

        // La seconde ligne contredit la péremption déjà connue de son lot : rien n'entre.
        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', ['lines' => [
                $this->stockLine($first),
                $this->stockLine($second, ['expires_at' => now()->addYears(3)->toDateString()]),
            ]])
            ->assertSessionHasErrors('lines.1.expires_at');

        $this->assertNull($first->refresh()->stocked_at);
        $this->assertFalse(MedicineLot::query()->where('lot_number', 'PARA-01')->exists());
        $this->assertSame(0, PharmacyStockMovement::query()->count());

        // Corrigée, toute la livraison entre d'un coup.
        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', ['lines' => [$this->stockLine($first), $this->stockLine($second)]])
            ->assertSessionHas('status', '2 produits sont entrés en stock.');

        $this->assertSame(10, MedicineLot::query()->where('lot_number', 'PARA-01')->value('quantity_on_hand'));
        $this->assertSame(9, MedicineLot::query()->where('lot_number', 'AMX-01')->value('quantity_on_hand'));
    }

    public function test_the_same_received_line_cannot_be_sent_twice(): void
    {
        [$line] = $this->receiveDelivery([[$this->medicine('Paracétamol 500 mg'), 3, 'PARA-01']]);

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', ['lines' => [$this->stockLine($line), $this->stockLine($line)]])
            ->assertSessionHasErrors('lines.1.uuid');

        $this->assertSame(0, PharmacyStockMovement::query()->count());
    }

    /** ADR-174 — le prix d'achat est celui de la réception, jamais saisi en rangeant. */
    public function test_the_purchase_price_comes_from_the_reception(): void
    {
        [$line] = $this->receiveDelivery([[$this->medicine('Paracétamol 500 mg'), 10, 'PARA-01']]);

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', ['lines' => [$this->stockLine($line, ['unit_purchase_price' => '1'])]])
            ->assertSessionHasErrors('lines.0.unit_purchase_price');
        $this->assertSame(0, PharmacyStockMovement::query()->count());

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', ['lines' => [$this->stockLine($line)]])
            ->assertSessionHasNoErrors();

        $this->assertEquals(100, (float) PharmacyStockMovement::query()->sole()->unit_purchase_price);
    }

    /**
     * ADR-176 — la péremption d'un lot déjà détenu est un fait enregistré :
     * elle est proposée pour une ligne réceptionnée, mais seulement à qui peut
     * voir les lots.
     */
    public function test_known_lots_are_offered_only_to_accounts_that_may_see_them(): void
    {
        $medicine = $this->medicine('Amoxicilline 500 mg');
        $this->lot($medicine, 5, 'AMX-01');
        $this->receiveDelivery([[$medicine, 4, 'AMX-02']]);

        $this->actingAs($this->pharmacist)->get('/pharmacy/stock/entries/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pending.0.orders.0.lines.0.is_new', false)
                ->where('pending.0.orders.0.lines.0.known_lots.0.lot_number', 'AMX-01')
                ->where('pending.0.orders.0.lines.0.known_lots.0.expires_at', now()->addYear()->toDateString()));

        $this->pharmacist->permissions()->attach(
            Permission::query()->where('name', 'stock.lots.view')->value('id'),
            ['effect' => 'deny'],
        );

        $this->actingAs($this->pharmacist->fresh())->get('/pharmacy/stock/entries/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pending.0.orders.0.lines.0.known_lots', []));
    }

    // ------------------------------------------------------------------ outils

    private function lot(Medicine $medicine, int $quantity, string $number): MedicineLot
    {
        return MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => $number,
            'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => $quantity,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    private function medicine(string $name = 'Paracétamol 500 mg'): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.str_pad((string) (CatalogItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'boîte',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        return Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $name,
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }
}
