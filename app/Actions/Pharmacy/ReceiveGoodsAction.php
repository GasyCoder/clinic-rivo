<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Finance\FinancialNumberGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-097 — spec §9: receiving a purchase order is a distinct step from
 * ordering it, and can be partial (ordered 100, received 80 → only 80 enter
 * stock). This is the sole integration point between procurement and stock:
 * for every received line it calls the existing, unmodified
 * RecordStockEntryAction — the same lot-creation, FEFO-compatible,
 * immutable-movement path already used by manual stock entries — rather
 * than reimplementing any of that logic here.
 */
class ReceiveGoodsAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly RecordStockEntryAction $recordEntry,
    ) {}

    /**
     * @param array<int, array{
     *   purchase_order_line_id: int,
     *   quantity_received: int,
     *   lot_number: string,
     *   expires_at: string,
     *   unit_purchase_price?: ?string
     * }> $lines
     */
    public function execute(PurchaseOrder $order, array $lines, ?string $notes, User $actor): GoodsReceipt
    {
        if ($actor->cannot('goods_receipts.create')) {
            throw new AuthorizationException('Vous ne pouvez pas réceptionner cette commande.');
        }

        foreach ($lines as $line) {
            if (filled($line['unit_purchase_price'] ?? null) && $actor->cannot('stock.cost.record')) {
                throw new AuthorizationException('Vous ne pouvez pas enregistrer un prix d’achat.');
            }
        }

        return DB::transaction(function () use ($order, $lines, $notes, $actor): GoodsReceipt {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une commande passée peut être réceptionnée.',
                ]);
            }

            $receipt = GoodsReceipt::query()->create([
                'receipt_number' => $this->numbers->goodsReceipt(),
                'purchase_order_id' => $order->getKey(),
                'received_at' => now(),
                'notes' => $notes,
                'received_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            foreach ($lines as $lineData) {
                $this->receiveLine($order, $receipt, $lineData, $actor);
            }

            $this->recalculateStatus($order);

            return $receipt->fresh('lines');
        });
    }

    /** @param array{purchase_order_line_id: int, quantity_received: int, lot_number: string, expires_at: string, unit_purchase_price?: ?string} $lineData */
    private function receiveLine(PurchaseOrder $order, GoodsReceipt $receipt, array $lineData, User $actor): void
    {
        $orderLine = PurchaseOrderLine::query()
            ->where('purchase_order_id', $order->getKey())
            ->where('id', $lineData['purchase_order_line_id'])
            ->lockForUpdate()
            ->firstOrFail();

        $quantity = (int) $lineData['quantity_received'];

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'lines' => 'La quantité reçue doit être supérieure à zéro.',
            ]);
        }

        if ($orderLine->quantity_received + $quantity > $orderLine->quantity_ordered) {
            throw ValidationException::withMessages([
                'lines' => 'La quantité reçue dépasse la quantité commandée pour ce produit.',
            ]);
        }

        $orderLine->loadMissing('medicine');

        // ADR-112 — le prix d'achat n'est jamais redemandé à la réception :
        // c'est celui de la commande. Un compte autorisé peut seulement le
        // corriger si le fournisseur a facturé autre chose.
        $unitPurchasePrice = filled($lineData['unit_purchase_price'] ?? null)
            ? $lineData['unit_purchase_price']
            : $orderLine->unit_price;

        $movement = $this->recordEntry->execute([
            'medicine_uuid' => $orderLine->medicine->uuid,
            'operation' => 'ENTREE',
            'lot_number' => $lineData['lot_number'],
            'received_at' => now()->toDateString(),
            'expires_at' => $lineData['expires_at'],
            'quantity' => $quantity,
            'supplier_uuid' => $order->supplier->uuid,
            'unit_purchase_price' => $unitPurchasePrice,
            'origin' => "Réception {$receipt->receipt_number}",
            'destination' => 'Stock pharmacie',
            'reason' => "Réception commande {$order->order_number}",
        ], $actor);

        $receipt->lines()->create([
            'purchase_order_line_id' => $orderLine->getKey(),
            'medicine_id' => $orderLine->medicine_id,
            'lot_number' => $lineData['lot_number'],
            'expires_at' => $lineData['expires_at'],
            'quantity_received' => $quantity,
            'unit_purchase_price' => $unitPurchasePrice,
            'pharmacy_stock_movement_id' => $movement->getKey(),
        ]);

        $orderLine->update(['quantity_received' => $orderLine->quantity_received + $quantity]);
    }

    private function recalculateStatus(PurchaseOrder $order): void
    {
        $order->loadMissing('lines');
        $fullyReceived = $order->lines->every(fn (PurchaseOrderLine $line) => $line->quantity_received >= $line->quantity_ordered);
        $anyReceived = $order->lines->contains(fn (PurchaseOrderLine $line) => $line->quantity_received > 0);

        $order->update([
            'status' => $fullyReceived ? PurchaseOrderStatus::Received : ($anyReceived ? PurchaseOrderStatus::PartiallyReceived : $order->status),
        ]);
    }
}
