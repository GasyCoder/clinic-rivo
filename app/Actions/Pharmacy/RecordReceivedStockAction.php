<?php

namespace App\Actions\Pharmacy;

use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Pharmacy\MedicineSaleDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 — fait entrer au stock ce qui a été réceptionné.
 *
 * La réception a constaté la livraison ; ici la marchandise est rangée. Le
 * pharmacien relit chaque ligne et peut encore corriger ce qu'il a sous les
 * yeux — quantité, lot, péremption — avant que le lot et le mouvement
 * immuable n'existent. Une ligne n'entre qu'une fois : elle est verrouillée,
 * vérifiée non encore entrée, puis marquée. Tout ou rien.
 *
 * Le mouvement passe par RecordStockEntryAction, inchangée : mêmes règles de
 * lot, même FEFO, même prix d'achat — celui de la réception (ADR-112).
 */
class RecordReceivedStockAction
{
    public function __construct(private readonly RecordStockEntryAction $entry) {}

    /**
     * @param  array<int, array{uuid: string, quantity: int, lot_number: string, expires_at: string, sale_price?: mixed, sale_name?: ?string}>  $lines
     */
    public function execute(array $lines, User $actor): int
    {
        if ($actor->cannot('stock.entry')) {
            throw new AuthorizationException('Vous ne pouvez pas enregistrer une entrée de stock.');
        }

        return DB::transaction(function () use ($lines, $actor): int {
            $sale = app(MedicineSaleDetails::class);
            $orders = collect();

            foreach (array_values($lines) as $index => $data) {
                $line = GoodsReceiptLine::query()
                    ->with(['goodsReceipt.purchaseOrder.supplier', 'medicine.catalogItem:id,name', 'stocker:id,name'])
                    ->where('uuid', $data['uuid'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $name = $line->medicine->catalogItem?->name ?? 'Produit';

                if (! $line->isAwaitingStock()) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.uuid" => sprintf(
                            'Ligne %d (%s) : déjà entrée en stock%s le %s.',
                            $index + 1, $name,
                            $line->stocker ? ' par '.$line->stocker->name : '',
                            $line->stocked_at->format('d/m/Y à H:i'),
                        ),
                    ]);
                }

                $this->applyCorrections($line, $data, $index, $name);

                $receipt = $line->goodsReceipt;
                $order = $receipt->purchaseOrder;

                try {
                    $movement = $this->entry->execute([
                        'medicine_uuid' => $line->medicine->uuid,
                        'operation' => 'ENTREE',
                        'lot_number' => $line->lot_number,
                        'received_at' => now()->toDateString(),
                        'expires_at' => $line->expires_at->toDateString(),
                        'quantity' => $line->quantity_received,
                        'supplier_uuid' => $order->supplier->uuid,
                        'unit_purchase_price' => $line->unit_purchase_price,
                        'origin' => "Réception {$receipt->receipt_number}",
                        'destination' => 'Stock pharmacie',
                        'reason' => "Entrée en stock de la réception {$receipt->receipt_number} (commande {$order->order_number})",
                    ], $actor);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                        fn (array $messages, string $field) => ["lines.{$index}.{$field}" => sprintf('Ligne %d (%s) : %s', $index + 1, $name, $messages[0])],
                    )->all());
                }

                $line->update([
                    'pharmacy_stock_movement_id' => $movement->getKey(),
                    'stocked_at' => now(),
                    'stocked_by' => $actor->getKey(),
                ]);

                $sale->collect($line->medicine->uuid, $data['sale_price'] ?? null, $data['sale_name'] ?? null, 'lines', $index);
                $orders->put($order->getKey(), $order);
            }

            $sale->apply('réception de commande fournisseur', $actor);
            $orders->each->refreshReceptionStatus();

            return count($lines);
        });
    }

    /**
     * Ce que le pharmacien corrige en rangeant : tant que rien n'est entré,
     * la ligne de réception suit, et la commande avec elle si la quantité
     * change. Le trait Auditable garde l'ancienne et la nouvelle valeur.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyCorrections(GoodsReceiptLine $line, array $data, int $index, string $name): void
    {
        $quantity = (int) $data['quantity'];

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                "lines.{$index}.quantity" => sprintf('Ligne %d (%s) : la quantité doit être d’au moins 1.', $index + 1, $name),
            ]);
        }

        if ($quantity !== $line->quantity_received) {
            $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
            $received = $orderLine->quantity_received - $line->quantity_received + $quantity;

            if ($received > $orderLine->quantity_ordered) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => sprintf(
                        'Ligne %d (%s) : %d dépasserait la quantité commandée (%d au total).',
                        $index + 1, $name, $quantity, $orderLine->quantity_ordered,
                    ),
                ]);
            }

            $orderLine->update(['quantity_received' => $received]);
        }

        $line->fill([
            'quantity_received' => $quantity,
            'lot_number' => trim((string) $data['lot_number']),
            'expires_at' => $data['expires_at'],
        ]);

        if ($line->isDirty()) {
            $line->save();
        }
    }
}
