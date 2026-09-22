<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Services\Pharmacy\MedicineSaleDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-097, amendée par l'ADR-175 — réceptionner une commande constate ce qui
 * est arrivé : quantités, lots, péremptions, remarques. Rien n'entre encore
 * au stock : c'est l'entrée en stock (RecordReceivedStockAction) qui crée le
 * lot et le mouvement, une fois la marchandise contrôlée et rangée.
 *
 * Une réception peut être partielle (100 commandés, 80 arrivés) et porter,
 * dans le même geste, la facture du fournisseur ; sans facture, elle reste
 * « facture en attente » et la facture se saisit plus tard.
 */
class ReceiveGoodsAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly RecordSupplierInvoiceAction $recordInvoice,
    ) {}

    /**
     * @param array<int, array{
     *   purchase_order_line_id: int,
     *   quantity_received: int,
     *   lot_number: string,
     *   expires_at: string,
     *   unit_purchase_price?: ?string,
     *   notes?: ?string,
     *   sale_name?: ?string
     * }> $lines
     * @param array{
     *   invoice_number: string,
     *   invoice_date: string,
     *   due_date?: ?string,
     *   total_amount: string,
     *   notes?: ?string,
     *   attachment?: ?UploadedFile
     * }|null $invoice
     */
    public function execute(PurchaseOrder $order, array $lines, ?string $notes, User $actor, ?array $invoice = null): GoodsReceipt
    {
        if ($actor->cannot('goods_receipts.create')) {
            throw new AuthorizationException('Vous ne pouvez pas réceptionner cette commande.');
        }

        foreach ($lines as $line) {
            if (filled($line['unit_purchase_price'] ?? null) && $actor->cannot('stock.cost.record')) {
                throw new AuthorizationException('Vous ne pouvez pas enregistrer un prix d’achat.');
            }

            if (filled($line['sale_name'] ?? null) && $actor->cannot('medicines.name.update')) {
                throw new AuthorizationException('Vous ne pouvez pas renommer un médicament.');
            }
        }

        if ($invoice !== null && $actor->cannot('supplier_invoices.create')) {
            throw new AuthorizationException('Vous ne pouvez pas enregistrer la facture du fournisseur.');
        }

        return DB::transaction(function () use ($order, $lines, $notes, $actor, $invoice): GoodsReceipt {
            $order = PurchaseOrder::query()->with('supplier')->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une commande envoyée au fournisseur peut être réceptionnée.',
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

            $sale = app(MedicineSaleDetails::class);

            foreach (array_values($lines) as $index => $lineData) {
                $orderLine = $this->receiveLine($order, $receipt, $lineData, $index);
                $sale->collect($orderLine->medicine->uuid, null, $lineData['sale_name'] ?? null, 'lines', $index);
            }

            $sale->apply("Réception {$receipt->receipt_number}", $actor);
            $order->refreshReceptionStatus();

            if ($invoice !== null) {
                $this->recordInvoice->execute($order->supplier, [
                    ...$invoice,
                    'purchase_order_uuid' => $order->uuid,
                    'goods_receipt_uuid' => $receipt->uuid,
                ], CatalogActor::fromUser($actor));
            }

            return $receipt->fresh(['lines', 'invoices']);
        });
    }

    /** @param array<string, mixed> $lineData */
    private function receiveLine(PurchaseOrder $order, GoodsReceipt $receipt, array $lineData, int $index): PurchaseOrderLine
    {
        $orderLine = PurchaseOrderLine::query()
            ->with('medicine.catalogItem:id,name')
            ->where('purchase_order_id', $order->getKey())
            ->where('id', $lineData['purchase_order_line_id'])
            ->lockForUpdate()
            ->firstOrFail();

        $quantity = (int) $lineData['quantity_received'];
        $name = $orderLine->medicine->catalogItem?->name ?? 'Produit';

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                "lines.{$index}.quantity_received" => "{$name} : la quantité reçue doit être supérieure à zéro.",
            ]);
        }

        if ($orderLine->quantity_received + $quantity > $orderLine->quantity_ordered) {
            throw ValidationException::withMessages([
                "lines.{$index}.quantity_received" => sprintf(
                    '%s : %d reçu(s), mais il n’en reste que %d à recevoir sur cette commande.',
                    $name, $quantity, $orderLine->quantityRemaining(),
                ),
            ]);
        }

        // ADR-174 — le prix d'achat n'est jamais redemandé : c'est celui de
        // la commande. Un compte autorisé peut seulement le corriger si le
        // fournisseur a facturé autre chose.
        $unitPurchasePrice = filled($lineData['unit_purchase_price'] ?? null)
            ? $lineData['unit_purchase_price']
            : $orderLine->unit_price;

        $receipt->lines()->create([
            'purchase_order_line_id' => $orderLine->getKey(),
            'medicine_id' => $orderLine->medicine_id,
            'lot_number' => trim((string) $lineData['lot_number']),
            'expires_at' => $lineData['expires_at'],
            'quantity_received' => $quantity,
            'unit_purchase_price' => $unitPurchasePrice,
            'notes' => filled($lineData['notes'] ?? null) ? trim((string) $lineData['notes']) : null,
        ]);

        $orderLine->update(['quantity_received' => $orderLine->quantity_received + $quantity]);

        return $orderLine;
    }
}
