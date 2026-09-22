<?php

namespace App\Services\Pharmacy;

use App\Models\GoodsReceiptLine;
use App\Models\MedicineLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ADR-113 — ce qui a été réceptionné et attend d'être rangé au stock.
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

        $stocked = MedicineLot::query()
            ->whereIn('medicine_id', $lines->pluck('medicine_id')->unique())
            ->pluck('medicine_id')
            ->flip();
        $seeCost = $user->can('stock.cost.view');

        return $lines
            ->groupBy(fn (GoodsReceiptLine $line) => $line->goodsReceipt->purchaseOrder->supplier->uuid)
            ->map(function (Collection $supplierLines) use ($stocked, $seeCost): array {
                $supplier = $supplierLines->first()->goodsReceipt->purchaseOrder->supplier;
                $orders = $supplierLines
                    ->groupBy(fn (GoodsReceiptLine $line) => $line->goodsReceipt->purchaseOrder->uuid)
                    ->map(fn (Collection $orderLines): array => [
                        'uuid' => $orderLines->first()->goodsReceipt->purchaseOrder->uuid,
                        'order_number' => $orderLines->first()->goodsReceipt->purchaseOrder->order_number,
                        'lines' => $orderLines->map(fn (GoodsReceiptLine $line) => $this->present($line, $stocked, $seeCost))->values()->all(),
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
     * @param  Collection<int, int>  $stocked
     * @return array<string, mixed>
     */
    private function present(GoodsReceiptLine $line, Collection $stocked, bool $seeCost): array
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
            // Ce que la commande permet encore pour cette ligne.
            'max_quantity' => $line->purchaseOrderLine->quantity_ordered
                - $line->purchaseOrderLine->quantity_received
                + $line->quantity_received,
            'notes' => $line->notes,
            // ADR-112 — le prix d'achat est confidentiel.
            'unit_purchase_price' => $seeCost ? $line->unit_purchase_price : null,
            'sale_price' => $line->medicine->catalogItem?->currentStandardTariff?->amount,
            // Jamais entré au stock : il n'a encore aucun lot.
            'is_new' => ! $stocked->has($line->medicine_id),
        ];
    }
}
