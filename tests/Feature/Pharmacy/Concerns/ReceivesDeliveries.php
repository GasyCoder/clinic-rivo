<?php

namespace Tests\Feature\Pharmacy\Concerns;

use App\Models\GoodsReceiptLine;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ADR-182 — tout ce qui entre au stock vient d'une livraison réceptionnée.
 *
 * Un test qui veut du stock passe donc par le vrai chemin : une commande
 * envoyée puis réceptionnée, dont les lignes attendent d'être rangées. Un
 * acheteur à part s'en charge, pour que le compte testé garde ses seuls
 * droits — c'est lui, ensuite, qui fait entrer au stock.
 */
trait ReceivesDeliveries
{
    private ?User $deliveryBuyer = null;

    /**
     * Commande ces produits chez un fournisseur, envoie la commande et la
     * réceptionne. Renvoie les lignes de réception, dans l'ordre donné.
     *
     * Chaque élément : [produit, quantité (10), n° de lot (LOT-n), péremption
     * (dans un an)]. Le prix d'achat est celui de la commande : 100.
     *
     * @param  array<int, array{0: Medicine, 1?: int, 2?: string, 3?: string}>  $items
     * @return Collection<int, GoodsReceiptLine>
     */
    protected function receiveDelivery(array $items, ?MedicineSupplier $supplier = null): Collection
    {
        $buyer = $this->deliveryBuyer();
        $rank = MedicineSupplier::query()->withTrashed()->count() + 1;
        $supplier ??= MedicineSupplier::query()->create([
            'code' => "FRN-{$rank}",
            'name' => "Fournisseur {$rank}",
            'active' => true,
            'created_by' => $buyer->id,
            'updated_by' => $buyer->id,
        ]);

        $this->actingAs($buyer)->post("/pharmacy/suppliers/{$supplier->uuid}/purchase-orders", [
            'lines' => collect($items)->map(fn (array $item) => [
                'medicine_uuid' => $item[0]->uuid,
                'quantity_ordered' => $item[1] ?? 10,
                'unit_price' => '100',
            ])->all(),
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->latest('id')->firstOrFail();
        $this->actingAs($buyer)->post("/pharmacy/purchase-orders/{$order->uuid}/submit")->assertSessionHasNoErrors();

        $orderLines = $order->lines()->get()->keyBy('medicine_id');
        $this->actingAs($buyer)->post("/pharmacy/purchase-orders/{$order->uuid}/receipts", [
            'lines' => collect($items)->values()->map(fn (array $item, int $index) => [
                'purchase_order_line_id' => $orderLines[$item[0]->id]->id,
                'medicine_uuid' => null,
                'supplier_catalog_item_uuid' => null,
                'quantity_received' => $item[1] ?? 10,
                'lot_number' => $item[2] ?? 'LOT-'.($index + 1),
                'expires_at' => $item[3] ?? now()->addYear()->toDateString(),
            ])->all(),
        ])->assertSessionHasNoErrors();

        return GoodsReceiptLine::query()
            ->whereIn('goods_receipt_id', $order->receipts()->pluck('id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Ce que l'écran d'entrée en stock envoie pour une ligne réceptionnée.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function stockLine(GoodsReceiptLine $line, array $overrides = []): array
    {
        return [
            'uuid' => $line->uuid,
            'quantity' => $line->quantity_received,
            'lot_number' => $line->lot_number,
            'expires_at' => $line->expires_at->toDateString(),
            ...$overrides,
        ];
    }

    private function deliveryBuyer(): User
    {
        if ($this->deliveryBuyer) {
            return $this->deliveryBuyer;
        }

        $buyer = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
        $buyer->permissions()->attach(
            Permission::query()->whereIn('name', [
                'medicine_suppliers.view',
                'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.submit',
                'goods_receipts.view', 'goods_receipts.create',
                'stock.cost.record', 'stock.cost.view',
            ])->pluck('id'),
            ['effect' => 'allow'],
        );

        return $this->deliveryBuyer = $buyer;
    }
}
