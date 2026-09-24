<?php

namespace App\Services\Pharmacy;

use App\Models\GoodsReceiptLine;
use App\Models\MedicineLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ADR-175 — ce qui a été réceptionné et attend d'être rangé au stock.
 *
 * Une ligne de réception attend tant qu'elle n'a pas de `stocked_at` ; dès
 * qu'elle entre, elle quitte cette file et ne peut plus être proposée : c'est
 * ce qui empêche qu'une même livraison entre deux fois au stock.
 */
class ReceivedStockQueue
{
    /** @return Builder<GoodsReceiptLine> */
    public function awaiting(): Builder
    {
        return GoodsReceiptLine::query()->whereNull('stocked_at');
    }

    public function count(): int
    {
        return $this->awaiting()->count();
    }

    /**
     * Tout ce qui attend, rangé par fournisseur puis par commande : l'écran
     * choisit le fournisseur et la commande sans recharger la page. Ces
     * lignes sont peu nombreuses — elles quittent la file dès qu'elles
     * entrent au stock.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(User $user): array
    {
        $lines = $this->awaiting()
            ->with([
                'goodsReceipt.purchaseOrder:id,uuid,order_number,medicine_supplier_id',
                'goodsReceipt.purchaseOrder.supplier:id,uuid,name',
                'purchaseOrderLine:id,quantity_ordered,quantity_received',
                'medicine.catalogItem.currentStandardTariff',
            ])
            ->orderBy('goods_receipt_id')
            ->orderBy('id')
            ->get();

        // Tous les lots des produits en attente, en une requête : ils disent si
        // un produit a déjà été rangé, et quels lots la pharmacie tient déjà.
        $lots = MedicineLot::query()
            ->whereIn('medicine_id', $lines->pluck('medicine_id')->unique())
            ->orderBy('expires_at')
            ->get(['uuid', 'medicine_id', 'lot_number', 'expires_at', 'active'])
            ->groupBy('medicine_id');
        $seeCost = $user->can('stock.cost.view');
        $seeLots = $user->can('stock.lots.view');
        $seeExpiry = $user->can('stock.expiration.view');

        return $lines
            ->groupBy(fn (GoodsReceiptLine $line) => $line->goodsReceipt->purchaseOrder->supplier->uuid)
            ->map(function (Collection $supplierLines) use ($lots, $seeCost, $seeLots, $seeExpiry): array {
                $supplier = $supplierLines->first()->goodsReceipt->purchaseOrder->supplier;
                $orders = $supplierLines
                    ->groupBy(fn (GoodsReceiptLine $line) => $line->goodsReceipt->purchaseOrder->uuid)
                    ->map(fn (Collection $orderLines): array => [
                        'uuid' => $orderLines->first()->goodsReceipt->purchaseOrder->uuid,
                        'order_number' => $orderLines->first()->goodsReceipt->purchaseOrder->order_number,
                        'lines' => $orderLines->map(fn (GoodsReceiptLine $line) => $this->present(
                            $line,
                            $lots->get($line->medicine_id, collect()),
                            $seeCost,
                            $seeLots,
                            $seeExpiry,
                        ))->values()->all(),
                    ])
                    ->values();

                return [
                    'uuid' => $supplier->uuid,
                    'name' => $supplier->name,
                    'lines_count' => $supplierLines->count(),
                    'orders_count' => $orders->count(),
                    'orders' => $orders->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MedicineLot>  $lots  les lots déjà connus de ce produit
     * @return array<string, mixed>
     */
    private function present(GoodsReceiptLine $line, Collection $lots, bool $seeCost, bool $seeLots, bool $seeExpiry): array
    {
        return [
            'uuid' => $line->uuid,
            'receipt_number' => $line->goodsReceipt->receipt_number,
            'received_at' => $line->goodsReceipt->received_at?->toIso8601String(),
            'medicine_uuid' => $line->medicine->uuid,
            'medicine_name' => $line->medicine->catalogItem?->name,
            'medicine_code' => $line->medicine->catalogItem?->code,
            'unit' => $line->medicine->catalogItem?->unit,
            'lot_number' => $line->lot_number,
            'expires_at' => $line->expires_at?->toDateString(),
            'quantity' => $line->quantity_received,
            // Ce que la commande permet encore pour cette ligne ; rien ne
            // borne un article livré hors commande.
            'max_quantity' => $line->purchaseOrderLine
                ? $line->purchaseOrderLine->quantity_ordered
                    - $line->purchaseOrderLine->quantity_received
                    + $line->quantity_received
                : null,
            'notes' => $line->notes,
            // ADR-179 — un article livré hors commande ne solde aucune ligne
            // de commande : rien ne le borne.
            'off_order' => $line->purchase_order_line_id === null,
            // ADR-174 — le prix d'achat est confidentiel.
            'unit_purchase_price' => $seeCost ? $line->unit_purchase_price : null,
            'sale_price' => $line->medicine->catalogItem?->currentStandardTariff?->amount,
            // Jamais entré au stock : il n'a encore aucun lot.
            'is_new' => $lots->isEmpty(),
            /*
             * ADR-176 — le lot et la péremption se lisent sur la boîte ; la
             * seule chose que le système sache, ce sont les lots que la
             * pharmacie tient déjà. Un lot inactif ne reçoit plus d'entrée :
             * il n'est pas proposé. Les droits de lecture restent ceux du stock.
             */
            'known_lots' => $seeLots
                ? $lots->where('active', true)->map(fn (MedicineLot $lot): array => [
                    'uuid' => $lot->uuid,
                    'lot_number' => $lot->lot_number,
                    'expires_at' => $seeExpiry ? $lot->expires_at?->toDateString() : null,
                ])->values()->all()
                : [],
        ];
    }
}
