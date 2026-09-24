<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\PurchaseOrderStatus;
use App\Models\CatalogItem;
use App\Models\GoodsReceiptLine;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR-179 — ce qu'une livraison réelle fait subir à une commande : le
 * fournisseur qui confirme (ou non), l'article qui n'arrivera jamais,
 * l'article livré sans avoir été commandé, et l'écart de montant qui en
 * résulte à la facture.
 */
class SupplierOrderReceptionRealityTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    private const PERMISSIONS = [
        'medicine_suppliers.view',
        'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update',
        'purchase_orders.submit', 'purchase_orders.cancel', 'purchase_orders.confirm',
        'purchase_orders.delete',
        'goods_receipts.view', 'goods_receipts.create',
        'supplier_invoices.view', 'supplier_invoices.create',
        'stock.cost.record', 'stock.cost.view', 'stock.entry', 'stock.view',
        'medicines.create', 'catalog.items.create', 'medicine_supplier_offers.create',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
        $this->pharmacist->permissions()->attach(
            Permission::query()->whereIn('name', self::PERMISSIONS)->pluck('id'),
            ['effect' => 'allow'],
        );
    }

    // ---------------------------------------------------------------- point 1

    public function test_a_supplier_confirmation_is_recorded_corrected_and_withdrawn(): void
    {
        Storage::fake('local');
        $order = $this->orderedOrder();

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/confirmation", [
                'confirmed_at' => now()->subDay()->toDateString(),
                'reference' => 'AC-2026-118',
                'notes' => 'Livraison annoncée sous 5 jours.',
                'attachment' => UploadedFile::fake()->create('confirmation.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertTrue($order->isSupplierConfirmed());
        $this->assertSame('AC-2026-118', $order->supplier_confirmation_reference);
        $this->assertSame($this->pharmacist->id, $order->supplier_confirmed_by);
        Storage::disk('local')->assertExists($order->supplier_confirmation_attachment_path);

        // Une correction remplace la trace et le document qu'elle portait.
        $first = $order->supplier_confirmation_attachment_path;
        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/confirmation", [
                'confirmed_at' => now()->toDateString(),
                'reference' => 'AC-2026-119',
                'attachment' => UploadedFile::fake()->create('corrigee.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('AC-2026-119', $order->supplier_confirmation_reference);
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($this->pharmacist)
            ->delete("/pharmacy/purchase-orders/{$order->uuid}/confirmation")
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertFalse($order->isSupplierConfirmed());
        $this->assertNull($order->supplier_confirmation_attachment_path);
    }

    /**
     * Le cœur de la décision : beaucoup de fournisseurs ne confirment jamais.
     * Une commande sans confirmation se réceptionne exactement comme avant.
     */
    public function test_an_unconfirmed_order_is_received_exactly_as_before(): void
    {
        $order = $this->orderedOrder();
        $this->assertFalse($order->isSupplierConfirmed());

        $this->actingAs($this->pharmacist)->get("/pharmacy/purchase-orders/{$order->uuid}/receive")->assertOk();

        $this->receive($order, [$this->lineOf($order)->id => 100])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrderStatus::Received, $order->refresh()->status);
    }

    public function test_recording_a_confirmation_needs_its_own_permission(): void
    {
        $order = $this->orderedOrder();
        $plain = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);

        $this->actingAs($plain)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/confirmation", ['confirmed_at' => now()->toDateString()])
            ->assertForbidden();
    }

    // ------------------------------------------------------------- points 2-3

    /**
     * Le défaut que cette décision corrige : une seule ligne jamais livrée
     * laissait la commande « Partiellement reçue » à vie, donc éternellement
     * dans « À réceptionner ».
     */
    public function test_an_unfulfilled_line_no_longer_blocks_the_order_for_ever(): void
    {
        $supplier = $this->supplier();
        $first = $this->medicine('Paracétamol 500 mg');
        $second = $this->medicine('Amoxicilline 1 g');
        $order = $this->orderedOrder($supplier, [$first, $second]);

        $lines = $order->lines()->orderBy('id')->get();
        $this->receive($order, [$lines[0]->id => 100])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->refresh()->status);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/lines/{$lines[1]->id}/shortage", [
                'reason' => 'Rupture chez le fournisseur jusqu’en décembre.',
            ])
            ->assertSessionHasNoErrors();

        // « Clôturée » et non « Reçue » : la commande n'a pas été livrée en
        // entier, et l'écrire « Reçue » mentirait à qui relira l'historique.
        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);

        $short = $lines[1]->refresh();
        $this->assertTrue($short->isShort());
        $this->assertSame(0, $short->quantityRemaining());
        $this->assertSame($this->pharmacist->id, $short->shortage_by);
        // Rien n'est effacé : la quantité commandée et le prix restent.
        $this->assertSame(100, $short->quantity_ordered);
    }

    public function test_a_line_in_shortage_disappears_from_the_reception_screen_and_cannot_be_received(): void
    {
        $order = $this->orderedOrder();
        $line = $this->lineOf($order);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/lines/{$line->id}/shortage", ['reason' => 'Jamais livré.']);

        // La commande n'attend plus rien : elle n'est plus réceptionnable.
        $this->actingAs($this->pharmacist)
            ->get("/pharmacy/purchase-orders/{$order->uuid}/receive")
            ->assertSessionHasErrors('status');

        // Et le serveur refuse de toute façon, même en forçant la ligne.
        $this->receive($order, [$line->id => 10])->assertSessionHasErrors();
        $this->assertSame(0, $line->refresh()->quantity_received);
    }

    public function test_a_shortage_is_reverted_and_the_order_reopens(): void
    {
        $order = $this->orderedOrder();
        $line = $this->lineOf($order);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/lines/{$line->id}/shortage", ['reason' => 'Annoncé en rupture.']);
        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);

        $this->actingAs($this->pharmacist)
            ->delete("/pharmacy/purchase-orders/{$order->uuid}/lines/{$line->id}/shortage")
            ->assertSessionHasNoErrors();

        $this->assertFalse($line->refresh()->isShort());
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->refresh()->status);
    }

    public function test_a_shortage_reason_is_required_and_the_right_is_that_of_reception(): void
    {
        $order = $this->orderedOrder();
        $line = $this->lineOf($order);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/lines/{$line->id}/shortage", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        // ADR-176 — constater une rupture appartient à qui réceptionne.
        $plain = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $this->actingAs($plain)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/lines/{$line->id}/shortage", ['reason' => 'Rupture.'])
            ->assertSessionHasNoErrors();
    }

    public function test_closing_an_order_abandons_every_outstanding_line_at_once(): void
    {
        $supplier = $this->supplier();
        $order = $this->orderedOrder($supplier, [$this->medicine('A'), $this->medicine('B')]);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/close", ['reason' => 'Le fournisseur a cessé son activité.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);
        $this->assertTrue($order->lines->every(fn (PurchaseOrderLine $line) => $line->isShort()));

        // Rien à abandonner deux fois.
        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/close", ['reason' => 'Encore.'])
            ->assertSessionHasErrors();
    }

    /**
     * Clôturer n'est pas annuler. Une commande clôturée a réellement été
     * envoyée, souvent livrée en partie, et peut porter une facture :
     * l'annuler dirait qu'elle n'a jamais eu lieu, et la rendrait au passage
     * jetable (ADR-176 — une commande annulée part à la corbeille).
     */
    public function test_a_closed_order_is_neither_cancelled_nor_trashed(): void
    {
        $supplier = $this->supplier();
        $order = $this->orderedOrder($supplier, [$this->medicine('A'), $this->medicine('B')]);
        $lines = $order->lines()->orderBy('id')->get();

        // Une ligne réellement livrée, l'autre jamais : la commande se clôt.
        $this->receive($order, [$lines[0]->id => 100])->assertSessionHasNoErrors();
        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/lines/{$lines[1]->id}/shortage", ['reason' => 'Jamais livré.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/cancel", ['reason' => 'Finalement annulée.'])
            ->assertSessionHasErrors('status');
        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);

        $this->actingAs($this->pharmacist)
            ->delete("/pharmacy/purchase-orders/{$order->uuid}", ['reason' => 'Ménage.'])
            ->assertSessionHasErrors('status');
        $this->assertNull($order->refresh()->deleted_at);

        // La sortie existe : on retire la rupture, la commande est de nouveau
        // attendue, et elle s'annule alors normalement.
        $this->actingAs($this->pharmacist)
            ->delete("/pharmacy/purchase-orders/{$order->uuid}/lines/{$lines[1]->id}/shortage")
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->refresh()->status);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/cancel", ['reason' => 'Finalement annulée.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->refresh()->status);
    }

    /** Chez qui d'autre trouver le produit : le même, jamais un équivalent deviné. */
    public function test_the_reception_screen_names_the_other_suppliers_of_the_same_product(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $other = MedicineSupplier::query()->create(['code' => 'FOUR-02', 'name' => 'Second fournisseur']);
        MedicineSupplierOffer::query()->create([
            'medicine_id' => $medicine->id,
            'medicine_supplier_id' => $other->id,
            'quoted_price' => '95.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Prix initial du catalogue',
            'created_by' => $this->pharmacist->id,
        ]);
        // Un produit d'une autre famille, chez le même second fournisseur :
        // il ne doit jamais être proposé comme substitut.
        $unrelated = $this->medicine('Ibuprofène 400 mg');
        MedicineSupplierOffer::query()->create([
            'medicine_id' => $unrelated->id,
            'medicine_supplier_id' => $other->id,
            'quoted_price' => '80.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Prix initial du catalogue',
            'created_by' => $this->pharmacist->id,
        ]);

        $order = $this->orderedOrder($supplier, [$medicine]);

        $this->actingAs($this->pharmacist)
            ->get("/pharmacy/purchase-orders/{$order->uuid}/receive")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where("alternatives.{$medicine->uuid}.0.supplier_name", 'Second fournisseur')
                ->where("alternatives.{$medicine->uuid}.0.quoted_price", '95.00')
                ->count("alternatives.{$medicine->uuid}", 1)
                ->missing("alternatives.{$unrelated->uuid}"));
    }

    // ---------------------------------------------------------------- point 4

    public function test_an_article_delivered_off_order_is_received_without_rewriting_the_order(): void
    {
        $supplier = $this->supplier();
        $ordered = $this->medicine('Paracétamol 500 mg');
        $extra = $this->medicine('Compresses stériles');
        // ADR-182 — un article livré est un produit que ce fournisseur vend.
        $supplier->medicines()->attach($extra->id);
        $order = $this->orderedOrder($supplier, [$ordered]);
        $line = $this->lineOf($order);
        $orderTotal = $order->total_amount;

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
                'lines' => [
                    $this->receiptLine(['purchase_order_line_id' => $line->id, 'quantity_received' => 100]),
                    $this->receiptLine(['medicine_uuid' => $extra->uuid, 'quantity_received' => 12, 'lot_number' => 'HORS-1']),
                ],
            ])
            ->assertSessionHasNoErrors();

        $off = GoodsReceiptLine::query()->where('medicine_id', $extra->id)->firstOrFail();
        $this->assertNull($off->purchase_order_line_id);
        $this->assertSame(12, $off->quantity_received);

        // ADR-098 — la commande n'est pas réécrite : ni ligne, ni montant.
        $order->refresh();
        $this->assertSame(1, $order->lines()->count());
        $this->assertSame($orderTotal, $order->total_amount);
        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
    }

    public function test_an_off_order_article_enters_stock_like_any_other(): void
    {
        $supplier = $this->supplier();
        $extra = $this->medicine('Compresses stériles');
        $supplier->medicines()->attach($extra->id);
        $order = $this->orderedOrder($supplier, [$this->medicine()]);

        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
            'lines' => [$this->receiptLine(['medicine_uuid' => $extra->uuid, 'quantity_received' => 12, 'lot_number' => 'HORS-1'])],
        ]);

        $off = GoodsReceiptLine::query()->where('medicine_id', $extra->id)->firstOrFail();

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries/batch', [
                'lines' => [[
                    'uuid' => $off->uuid,
                    'quantity' => 12,
                    'lot_number' => 'HORS-1',
                    'expires_at' => now()->addYear()->toDateString(),
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($off->refresh()->stocked_at);
        $this->assertSame(12, (int) $extra->lots()->sum('quantity_on_hand'));
    }

    public function test_a_receipt_line_must_designate_something(): void
    {
        $order = $this->orderedOrder();

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
                'lines' => [$this->receiptLine(['quantity_received' => 5])],
            ])
            ->assertSessionHasErrors('lines.0.medicine_uuid');
    }

    /**
     * ADR-182 — un produit que la pharmacie tient mais que ce fournisseur n'a
     * jamais proposé ne se constate pas sur sa livraison.
     */
    public function test_a_receipt_refuses_an_off_order_product_the_supplier_does_not_sell(): void
    {
        $order = $this->orderedOrder();
        $unrelated = $this->medicine('Produit d’un autre fournisseur');

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
                'lines' => [$this->receiptLine(['medicine_uuid' => $unrelated->uuid, 'quantity_received' => 5])],
            ])
            ->assertSessionHasErrors('lines.0.medicine_uuid');

        $this->assertSame(0, GoodsReceiptLine::query()->count());
    }

    // ------------------------ ADR-182 — l'article livré, dans le catalogue du fournisseur

    /**
     * L'article livré hors commande se choisit dans le catalogue actif du
     * fournisseur, et lui seul : ni un produit que seule la pharmacie tient,
     * ni une section « déjà au catalogue de la clinique ».
     */
    public function test_the_off_order_list_is_the_supplier_catalogue_only(): void
    {
        $supplier = $this->supplier();
        $ordered = $this->medicine('Doliprane 500 mg');
        $this->medicine('Produit de la pharmacie seulement');
        $order = $this->orderedOrder($supplier, [$ordered]);
        $catalogLine = $this->catalogLine($supplier, 'Efferalgan 1 g');

        $this->actingAs($this->pharmacist)
            ->get("/pharmacy/purchase-orders/{$order->uuid}/receive")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('offOrderProducts', 1)
                ->where('offOrderProducts.0.catalog_item_uuid', $catalogLine->uuid)
                ->where('offOrderProducts.0.linked', false));
    }

    /**
     * Une ligne du catalogue déjà rattachée désigne un produit que la clinique
     * tient : la réceptionner ne crée rien, et n'exige pas le droit de créer
     * un médicament.
     */
    public function test_a_linked_catalogue_line_is_received_without_creating_a_product(): void
    {
        $supplier = $this->supplier();
        $order = $this->orderedOrder($supplier);
        $efferalgan = $this->medicine('Efferalgan 500 mg');
        $catalogLine = $this->catalogLine($supplier, 'EFFERALGAN 500MG CPR');
        $catalogLine->update(['linked_medicine_id' => $efferalgan->id]);
        $medicines = Medicine::query()->count();

        $receiver = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $receiver->permissions()->attach(Permission::query()->where('name', 'medicines.create')->pluck('id'), ['effect' => 'deny']);

        $this->actingAs($receiver)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
                'lines' => [$this->receiptLine([
                    'supplier_catalog_item_uuid' => $catalogLine->uuid,
                    'quantity_received' => 6,
                    'lot_number' => 'EFF-9',
                ])],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($medicines, Medicine::query()->count());
        $this->assertSame(1, GoodsReceiptLine::query()->where('medicine_id', $efferalgan->id)->count());
    }

    /**
     * ADR-182, amendement du 2026-09-24 — réceptionner suffit : un compte
     * Pharmacie sans le droit de créer un médicament fait entrer au catalogue
     * de la clinique le produit que le fournisseur a livré. Un refus
     * individuel l'interdit toujours.
     */
    public function test_receiving_a_supplier_catalogue_line_is_enough_to_bring_the_product_in(): void
    {
        $supplier = $this->supplier();
        $order = $this->orderedOrder($supplier);
        $catalogLine = $this->catalogLine($supplier, 'Efferalgan 1 g', '120.00');

        $receiver = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $this->assertFalse($receiver->can('medicines.create'));

        $this->actingAs($receiver)
            ->get("/pharmacy/purchase-orders/{$order->uuid}/receive")
            ->assertInertia(fn ($page) => $page->where('can.create_medicine', true));

        $this->actingAs($receiver)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
                'lines' => [$this->receiptLine(['supplier_catalog_item_uuid' => $catalogLine->uuid, 'quantity_received' => 4])],
            ])
            ->assertSessionHasNoErrors();

        $medicine = Medicine::query()->findOrFail($catalogLine->refresh()->linked_medicine_id);
        $this->assertSame('Efferalgan 1 g', $medicine->catalogItem->name);
        $this->assertNull($medicine->catalogItem->currentStandardTariff, 'Aucun prix de vente inventé (ADR-174).');
        $this->assertSame(1, GoodsReceiptLine::query()->where('medicine_id', $medicine->id)->count());
    }

    public function test_an_individual_deny_still_forbids_bringing_a_product_in_at_reception(): void
    {
        $supplier = $this->supplier();
        $order = $this->orderedOrder($supplier);
        $catalogLine = $this->catalogLine($supplier, 'Efferalgan 1 g');
        $medicines = Medicine::query()->count();

        $receiver = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $receiver->permissions()->attach(Permission::query()->where('name', 'medicines.create')->pluck('id'), ['effect' => 'deny']);

        $this->actingAs($receiver)
            ->get("/pharmacy/purchase-orders/{$order->uuid}/receive")
            ->assertInertia(fn ($page) => $page->where('can.create_medicine', false));

        $this->actingAs($receiver)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
                'lines' => [$this->receiptLine(['supplier_catalog_item_uuid' => $catalogLine->uuid, 'quantity_received' => 4])],
            ])
            ->assertForbidden();

        $this->assertSame($medicines, Medicine::query()->count());
    }

    public function test_a_catalogue_line_of_another_supplier_is_refused_at_reception(): void
    {
        $order = $this->orderedOrder();
        $foreign = $this->catalogLine(
            MedicineSupplier::query()->create(['code' => 'FOUR-02', 'name' => 'Autre fournisseur']),
            'Produit étranger',
        );

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
                'lines' => [$this->receiptLine(['supplier_catalog_item_uuid' => $foreign->uuid, 'quantity_received' => 5])],
            ])
            ->assertSessionHasErrors('lines.0.supplier_catalog_item_uuid');
    }

    // ---------------------------------------------------------------- point 5

    /**
     * Le montant proposé reste celui de ce qui est arrivé — sur une livraison
     * partielle, c'est lui qui est juste. Ce que la commande engage est servi
     * à côté pour que l'écart se voie, sans jamais rien bloquer.
     */
    public function test_the_reception_screen_serves_the_ordered_amount_beside_what_arrived(): void
    {
        $order = $this->orderedOrder();

        $this->actingAs($this->pharmacist)
            ->get("/pharmacy/purchase-orders/{$order->uuid}/receive")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.total_amount', '10000.00')
                ->where('order.invoiced_amount', 0));
    }

    /** ADR-174 — le montant révèle le coût d'achat : il reste confidentiel. */
    public function test_the_ordered_amount_is_withheld_without_the_cost_permission(): void
    {
        $order = $this->orderedOrder();
        $plain = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);

        $this->actingAs($plain)
            ->get("/pharmacy/purchase-orders/{$order->uuid}/receive")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('order.total_amount', null));
    }

    // -------------------------------------------------------------- fabriques

    private function supplier(): MedicineSupplier
    {
        return MedicineSupplier::query()->firstOrCreate(
            ['code' => 'FOUR-01'],
            ['name' => 'Fournisseur de contrôle'],
        );
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

    /** @param array<int, Medicine>|null $medicines */
    private function orderedOrder(?MedicineSupplier $supplier = null, ?array $medicines = null): PurchaseOrder
    {
        $supplier ??= $this->supplier();
        $medicines ??= [$this->medicine()];

        $this->actingAs($this->pharmacist)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => collect($medicines)
                ->map(fn (Medicine $medicine) => [
                    'medicine_uuid' => $medicine->uuid,
                    'quantity_ordered' => 100,
                    'unit_price' => '100',
                ])->all(),
        ]);

        $order = PurchaseOrder::query()->latest('id')->firstOrFail();
        $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/submit");

        return $order->refresh();
    }

    private function catalogLine(MedicineSupplier $supplier, string $label, ?string $price = '120.00'): SupplierCatalogItem
    {
        $catalog = SupplierCatalog::query()->firstOrCreate(
            ['medicine_supplier_id' => $supplier->id, 'active_key' => 'ACTIVE'],
            [
                'original_name' => 'catalogue.xlsx',
                'path' => 'catalogues/'.Str::uuid().'.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => 10,
                'kind' => 'EXCEL',
                'created_by' => $this->pharmacist->id,
                'updated_by' => $this->pharmacist->id,
            ],
        );

        return SupplierCatalogItem::query()->create([
            'supplier_catalog_id' => $catalog->id,
            'reference' => 'REF-'.(SupplierCatalogItem::query()->count() + 1),
            'medicine_label' => $label,
            'presentation' => 'Boîte',
            'supplier_price' => $price,
            'row_number' => SupplierCatalogItem::query()->count() + 1,
            'created_by' => $this->pharmacist->id,
        ]);
    }

    private function lineOf(PurchaseOrder $order): PurchaseOrderLine
    {
        return $order->lines()->orderBy('id')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function receiptLine(array $overrides = []): array
    {
        return [
            'purchase_order_line_id' => null,
            'medicine_uuid' => null,
            'supplier_catalog_item_uuid' => null,
            'lot_number' => 'LOT-001',
            'expires_at' => now()->addYear()->toDateString(),
            ...$overrides,
        ];
    }

    /** @param array<int, int> $quantities ligne de commande => quantité reçue */
    private function receive(PurchaseOrder $order, array $quantities): TestResponse
    {
        return $this->actingAs($this->pharmacist)->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
            'lines' => collect($quantities)
                ->map(fn (int $quantity, int $lineId) => $this->receiptLine([
                    'purchase_order_line_id' => $lineId,
                    'quantity_received' => $quantity,
                    'lot_number' => 'LOT-'.$lineId,
                ]))
                ->values()
                ->all(),
        ]);
    }
}
